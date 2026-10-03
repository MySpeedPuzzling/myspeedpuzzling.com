<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Suggested for you" on the Players page (docs/features/players-page/README.md, stream S6). Fixtures: PLAYER_REGULAR
 * and PLAYER_WITH_FAVORITES (Michael Johnson) both took part in WJPC 2024 (CompetitionParticipantFixture).
 */
final class PlayersSuggestionsTest extends WebTestCase
{
    public function testGuestsGetNoSuggestions(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.players-suggestions');
    }

    public function testASignedInPlayerSeesPeopleWithTheReasonFavoriteAndCompare(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzlers?scope=cz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#players-suggestions-title', 'Suggested for you');

        $card = $this->cardOf($browser->getCrawler(), PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertStringContainsString('Michael Johnson', $card->text());
        self::assertStringContainsString('Also at WJPC 2024', $card->filter('.players-suggestion-reason')->text());

        // The person opens the player card, the buttons are the player header's own endpoints and come back here
        self::assertCount(1, $card->filter('a.players-person[data-action="players-card#open"]'));
        $favorite = (string) $card->filter('.players-suggestion-actions a.btn-outline-primary')->attr('href');
        self::assertSame('/en/add-player-to-favorites/' . PlayerFixture::PLAYER_WITH_FAVORITES, parse_url($favorite, PHP_URL_PATH));
        parse_str((string) parse_url($favorite, PHP_URL_QUERY), $query);
        self::assertSame('/en/puzzlers?scope=cz', $query['return'] ?? null);
        self::assertCount(1, $card->filter('form[action="/en/compare/add"] input[name="subject"][value="p-' . PlayerFixture::PLAYER_WITH_FAVORITES . '"]'));
    }

    public function testSomebodyAlreadyInTheComparisonLinksToIt(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO comparison_subject (id, player_id, subject_player_id, added_at) VALUES (:id, :owner, :subject, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'owner' => PlayerFixture::PLAYER_REGULAR, 'subject' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        $card = $this->cardOf($browser->getCrawler(), PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertCount(0, $card->filter('form[action="/en/compare/add"]'));
        self::assertSame('/en/compare?kind=solo', $card->filter('.players-suggestion-actions a.btn-outline-secondary')->attr('href'));
    }

    public function testFavoritesArePeopleTheViewerAlreadyFollows(): void
    {
        $browser = self::createClient();
        // Michael Johnson has PLAYER_REGULAR in favorites - the event they share is no reason to suggest him again
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.players-suggestion a[data-players-card-url-param$="' . PlayerFixture::PLAYER_REGULAR . '"]');
    }

    private function cardOf(Crawler $crawler, string $playerId): Crawler
    {
        $card = $crawler
            ->filter('.players-suggestion')
            ->reduce(static fn (Crawler $node): bool => $node->filter('a.players-person[data-players-card-url-param$="' . $playerId . '"]')->count() === 1);

        self::assertCount(1, $card, 'A card for ' . $playerId);

        return $card;
    }
}
