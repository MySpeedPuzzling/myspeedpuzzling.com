<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ProposeChangesControllerTest extends WebTestCase
{
    private const string URL = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03 . '/suggest-change';

    public function testInvalidNewCodeIsRefusedWithAMessageOnTheField(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // The fixture's code 4005556789012 has a wrong check digit - it stays allowed, the new one is refused
        $crawler = $this->submit($browser, ean: '4005556789012, 45555011897');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('looks like 4005555011897 with two zeros missing', $crawler->filter('form[name="propose_puzzle_changes_form"]')->text());
        self::assertSame(0, $this->changeRequestCount());
    }

    public function testInvalidCodeThePuzzleAlreadyCarriesDoesNotBlockOtherChanges(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->submit($browser, ean: '4005556789012', name: 'Puzzle 8 - corrected name');

        self::assertResponseRedirects();
        self::assertSame(1, $this->changeRequestCount());
    }

    private function submit(KernelBrowser $browser, string $ean, null|string $name = null): Crawler
    {
        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        $form['propose_puzzle_changes_form[ean]'] = $ean;

        if ($name !== null) {
            $form['propose_puzzle_changes_form[name]'] = $name;
        }

        return $browser->submit($form);
    }

    private function changeRequestCount(): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM puzzle_change_request WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_03],
        );

        return is_numeric($count) ? (int) $count : -1;
    }
}
