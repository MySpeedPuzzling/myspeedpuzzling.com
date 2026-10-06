<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReportDuplicatePuzzleControllerTest extends WebTestCase
{
    public function testTheReporterMaySayWhichLanguageEachNameIsIn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04 . '/suggest-change?tab=report');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="report_duplicate_puzzle_form"]')->form();
        $form['report_duplicate_puzzle_form[duplicatePuzzleUrl]'] = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_1000_05;
        $form['report_duplicate_puzzle_form[sourceNameLanguage]'] = 'cs';
        $form['report_duplicate_puzzle_form[duplicateNameLanguage]'] = 'de';
        $browser->submit($form);

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $languages = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT reported_name_languages FROM puzzle_merge_request WHERE source_puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_04],
        );
        self::assertIsString($languages);
        self::assertSame([
            PuzzleFixture::PUZZLE_1000_04 => 'cs',
            PuzzleFixture::PUZZLE_1000_05 => 'de',
        ], json_decode($languages, true));
    }

    public function testTheLanguagesAreOptional(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04 . '/suggest-change?tab=report');
        $form = $crawler->filter('form[name="report_duplicate_puzzle_form"]')->form();
        $form['report_duplicate_puzzle_form[duplicatePuzzleUrl]'] = PuzzleFixture::PUZZLE_1000_05;
        $browser->submit($form);

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $languages = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT reported_name_languages FROM puzzle_merge_request WHERE source_puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_04],
        );
        self::assertIsString($languages);
        self::assertSame([], json_decode($languages, true));
    }

    public function testAReportOfThePuzzleItselfComesBackWithTheReasonAndEverythingChosen(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04 . '/suggest-change?tab=report');
        $form = $crawler->filter('form[name="report_duplicate_puzzle_form"]')->form();
        // The puzzle itself is no duplicate of itself
        $form['report_duplicate_puzzle_form[duplicatePuzzleUrl]'] = PuzzleFixture::PUZZLE_1000_04;
        $form['report_duplicate_puzzle_form[sourceNameLanguage]'] = 'cs';
        $crawler = $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="report_duplicate_puzzle_form"]', 'You cannot report a puzzle as a duplicate of itself');
        self::assertSame(PuzzleFixture::PUZZLE_1000_04, $crawler->filter('input[name="report_duplicate_puzzle_form[duplicatePuzzleUrl]"]')->attr('value'));
        self::assertSame('cs', $crawler->filter('select[name="report_duplicate_puzzle_form[sourceNameLanguage]"] option[selected]')->attr('value'));
        self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM puzzle_merge_request WHERE source_puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_04],
        ), 'Nothing is filed');
    }

    public function testAPickedPuzzleComesBackWithItsOptionWhenTheReportIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04 . '/suggest-change?tab=report');
        $form = $crawler->filter('form[name="report_duplicate_puzzle_form"]')->form();
        // A picked puzzle plus an address too long for the field: refused, the pick must survive
        $form['report_duplicate_puzzle_form[selectedPuzzleId]'] = PuzzleFixture::PUZZLE_1000_05;
        $form['report_duplicate_puzzle_form[duplicatePuzzleUrl]'] = str_repeat('x', 501);
        $crawler = $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        $picker = $crawler->filter('input[name="report_duplicate_puzzle_form[selectedPuzzleId]"]');
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $picker->attr('value'));
        $option = json_decode((string) $picker->attr('data-selected-option'), true);
        self::assertIsArray($option);
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $option['value']);
    }
}
