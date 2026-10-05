<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "Suggest another name" from the puzzle page's menu (docs/features/puzzle-names/README.md).
 */
final class SuggestPuzzleNameControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string PUZZLE_URL = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02;
    private const string URL = self::PUZZLE_URL . '/suggest-name';

    /** What Turbo sends for a form inside the modal frame */
    private const array MODAL_FORM_HEADERS = [
        'HTTP_TURBO_FRAME' => 'modal-frame',
        'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
    ];

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testThePuzzlePageOffersItToSignedInPlayersOnly(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PUZZLE_URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="' . self::URL . '"]');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::PUZZLE_URL);
        self::assertSelectorExists('.more-menu a[href="' . self::URL . '"][data-turbo-frame="modal-frame"]');
    }

    public function testTheModalHoldsTheFormWithItsOwnActionAndTheNamesKnownAlready(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::URL, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal-frame form[action="' . self::URL . '"]');
        self::assertSelectorTextContains('turbo-frame#modal-frame', PuzzleFixture::NAME_DE_MAGIC_GARDEN . ' (German)');
        // A new name has no language on English pages, players cannot change the main title
        self::assertSame('', $crawler->filter('form[name="suggest_puzzle_name_form"]')->form()->getValues()['suggest_puzzle_name_form[language]']);
        self::assertSelectorNotExists('input[name="suggest_puzzle_name_form[makeMainTitle]"]');
    }

    public function testTheLanguageStartsAsThePagesOnOtherLanguages(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_1000_02 . '/navrhnout-nazev');

        self::assertResponseIsSuccessful();
        self::assertSame('cs', $crawler->filter('form[name="suggest_puzzle_name_form"]')->form()->getValues()['suggest_puzzle_name_form[language]']);
    }

    public function testAPlayersSuggestionClosesTheModalWithAThankYou(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', self::URL, ['suggest_puzzle_name_form' => [
            'name' => 'Jardín mágico',
            'language' => 'es',
            '_token' => $this->csrfToken($browser),
        ]], server: self::MODAL_FORM_HEADERS);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="modal-frame">', $content);
        self::assertStringContainsString('Thank you! A moderator will check the name.', $content);
        self::assertStringNotContainsString('action="refresh"', $content);

        $proposed = self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT proposed_alternative_names FROM puzzle_change_request WHERE puzzle_id = :puzzleId AND status = 'pending'",
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_02],
        );
        self::assertIsString($proposed);
        $names = json_decode($proposed, true);
        self::assertIsArray($names);
        self::assertSame(['name' => 'Jardín mágico', 'language' => 'es'], $names[2] ?? null);
    }

    public function testANameThePuzzleHasComesBackWithTheFormRefused(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('POST', self::URL, ['suggest_puzzle_name_form' => [
            'name' => 'zauberhafter garten',
            'language' => '',
            '_token' => $this->csrfToken($browser),
        ]], server: self::MODAL_FORM_HEADERS);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('turbo-frame#modal-frame', 'This puzzle already has this name.');
        self::assertSame('zauberhafter garten', $crawler->filter('input[name="suggest_puzzle_name_form[name]"]')->attr('value'));
    }

    public function testABlankNameIsRefused(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', self::URL, ['suggest_puzzle_name_form' => [
            'name' => '   ',
            '_token' => $this->csrfToken($browser),
        ]], server: self::MODAL_FORM_HEADERS);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testWithoutTheModalTheSuggestionRedirectsToThePuzzle(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        $browser->submit($crawler->filter('form[name="suggest_puzzle_name_form"]')->form(), [
            'suggest_puzzle_name_form[name]' => 'Jardín mágico',
            'suggest_puzzle_name_form[language]' => 'es',
        ]);

        self::assertResponseRedirects(self::PUZZLE_URL);
    }

    public function testTheEleventhSuggestionOfTheDayIsRefused(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $this->limiter()->create(PlayerFixture::PLAYER_REGULAR)->consume(10);

        $browser->request('POST', self::URL, ['suggest_puzzle_name_form' => [
            'name' => 'Jardín mágico',
            'language' => 'es',
            '_token' => $this->csrfToken($browser),
        ]], server: self::MODAL_FORM_HEADERS);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSelectorTextContains('turbo-frame#modal-frame', 'You have suggested many names today.');
        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM puzzle_change_request WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_02],
        ));
    }

    public function testAModeratorsNameIsAddedAndThePageRefreshed(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::URL, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertSelectorExists('input[name="suggest_puzzle_name_form[makeMainTitle]"]');

        $browser->request('POST', self::URL, ['suggest_puzzle_name_form' => [
            'name' => 'Jardín mágico',
            'language' => 'es',
            '_token' => $this->csrfToken($browser),
        ]], server: self::MODAL_FORM_HEADERS);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<turbo-stream action="refresh">', (string) $browser->getResponse()->getContent());

        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame(['name' => 'Jardín mágico', 'language' => 'es'], $puzzle->alternativeNames[2] ?? null);
    }

    public function testWithTheKillSwitchOffOnlyModeratorsSuggest(): void
    {
        $this->overrideFeatureFlagEnv('PUZZLE_NAME_SUGGESTIONS_PUBLIC', false);

        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::PUZZLE_URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="' . self::URL . '"]');

        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::PUZZLE_URL);
        self::assertSelectorExists('a[href="' . self::URL . '"]');

        $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::URL);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        // The limiter's storage outlives the test - every test starts with a full day's budget
        $this->limiter()->create($playerId)->reset();

        return $browser;
    }

    private function limiter(): RateLimiterFactoryInterface
    {
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = self::getContainer()->get('limiter.puzzle_name_suggestion');

        return $limiter;
    }

    private function csrfToken(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', self::URL, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        return (string) $crawler->filter('input[name="suggest_puzzle_name_form[_token]"]')->attr('value');
    }
}
