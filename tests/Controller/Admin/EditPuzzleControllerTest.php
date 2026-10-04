<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A moderator's direct edit of a puzzle, and the puzzle's history that shows it.
 */
final class EditPuzzleControllerTest extends WebTestCase
{
    private const string EDIT_URL = '/admin/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/edit';
    private const string HISTORY_URL = '/admin/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/history';

    public function testAnonymousIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::EDIT_URL);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));

        $browser->request('GET', self::HISTORY_URL);
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAPlayerWhoIsNoModeratorIsForbidden(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', self::EDIT_URL);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $browser->request('GET', self::HISTORY_URL);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testThePuzzlePageOffersTheEditToModeratorsOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href^="' . self::EDIT_URL . '"]');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertSelectorExists('a[href^="' . self::EDIT_URL . '"]');
        self::assertSelectorExists('a[href^="' . self::HISTORY_URL . '"]');
    }

    public function testAnEditIsSavedAndShownInTheHistory(): void
    {
        $browser = $this->signedInAdmin();
        $puzzleUrl = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        $crawler = $browser->request('GET', self::EDIT_URL . '?return=' . urlencode($puzzleUrl));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[data-controller~="puzzle-record"]')->form();
        $values = $form->getValues();
        self::assertSame('Puzzle 1', $values['puzzle_record_form[name]']);
        self::assertSame('RB-500-001', $values['puzzle_record_form[identificationNumber]']);
        self::assertArrayNotHasKey('puzzle_record_form[image]', $values);

        $browser->submit($form, [
            'puzzle_record_form[name]' => 'Puzzle 1 - Edited',
            'puzzle_record_form[piecesCount]' => '520',
            'puzzle_record_form[note]' => 'Counted the pieces',
        ]);

        self::assertResponseRedirects($puzzleUrl);

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1 - Edited', $puzzle->name);
        self::assertSame(520, $puzzle->piecesCount);

        $browser->request('GET', self::HISTORY_URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('article', 'Edited directly');
        self::assertSelectorTextContains('article', 'Counted the pieces');
        self::assertSelectorTextContains('article table', 'Puzzle 1 - Edited');
        self::assertSelectorTextContains('article table', '520');
    }

    public function testAnInvalidEanIsRefusedWithTheFormKept(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', self::EDIT_URL);
        $form = $crawler->filter('form[data-controller~="puzzle-record"]')->form();

        $crawler = $browser->submit($form, [
            'puzzle_record_form[name]' => 'Typed By The Moderator',
            'puzzle_record_form[ean]' => '1234567890123',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'Typed By The Moderator',
            $crawler->filter('form[data-controller~="puzzle-record"]')->form()->getValues()['puzzle_record_form[name]'],
        );

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1', $puzzle->name);
    }

    public function testAnUnknownPuzzleIsNotFound(): void
    {
        $browser = $this->signedInAdmin();

        $browser->request('GET', '/admin/puzzles/018d0003-0000-0000-0000-00000000ffff/edit');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $browser->request('GET', '/admin/puzzles/018d0003-0000-0000-0000-00000000ffff/history');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function signedInAdmin(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
    }
}
