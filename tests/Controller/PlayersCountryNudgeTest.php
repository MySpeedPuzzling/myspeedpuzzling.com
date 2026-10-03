<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Add your country" (Players:CountryNudge, docs/features/players-page/README.md): only for a signed-in player
 * without a country, until it is dismissed.
 */
final class PlayersCountryNudgeTest extends WebTestCase
{
    public function testAPlayerWithoutACountryIsAskedForOne(): void
    {
        $browser = self::createClient();
        $this->withoutCountry($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzlers');
        self::assertResponseIsSuccessful();

        $nudge = $crawler->filter('.players-nudge');
        self::assertCount(1, $nudge);
        self::assertStringContainsString("You're not on the map yet.", $nudge->text());
        self::assertStringContainsString('Country Cup', $nudge->text());
        self::assertSame('players_country_nudge', $nudge->attr('data-dismiss-hint-type-value'));
        self::assertStringContainsString('%country%', (string) $nudge->attr('data-players-country-nudge-named-value'));

        $form = $nudge->filter('form[method="post"][action="/en/puzzlers/my-country"]');
        self::assertCount(1, $form);
        self::assertCount(1, $form->filter('input[name="_token"]'));
        self::assertSame('Choose your country', $form->filter('select[name="country"] option')->first()->text());
        self::assertSame('', $form->filter('select[name="country"] option')->first()->attr('value'), 'Nothing preselected on the server - the browser guesses');
        self::assertSame('Czechia', $form->filter('select[name="country"] option[value="cz"]')->text());
        self::assertCount(1, $nudge->filter('button.players-nudge-close[data-action="dismiss-hint#dismiss"]'));
    }

    public function testCountriesAreListedByName(): void
    {
        $browser = self::createClient();
        $this->withoutCountry($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzlers');

        $names = $crawler->filter('.players-nudge select[name="country"] option')->each(static fn ($option): string => $option->text());
        array_shift($names);

        self::assertSame('Afghanistan', $names[0]);
        self::assertSame('Åland Islands', $names[1], 'Sorted like a person would, not byte by byte');
        self::assertSame('Zimbabwe', $names[count($names) - 1]);
    }

    public function testGuestsAreNotAsked(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-nudge'));
    }

    public function testAPlayerWithACountryIsNotAsked(): void
    {
        $browser = self::createClient();
        // John Doe lives in Czechia
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-nudge'));
    }

    public function testOnceDismissedItIsGoneForGood(): void
    {
        $browser = self::createClient();
        $this->withoutCountry($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'players_country_nudge']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $browser->request('GET', '/en/puzzlers');
        self::assertCount(0, $crawler->filter('.players-nudge'));

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $crawler->filter('.players-nudge'), 'One dismissal covers the profile too');
    }

    public function testTheOwnProfileAsksToo(): void
    {
        $browser = self::createClient();
        $this->withoutCountry($browser, PlayerFixture::PLAYER_REGULAR);
        $this->notANewcomer($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.players-nudge.mb-3 form[action="/en/puzzlers/my-country"]'));

        // Somebody else's profile is about them, not about the viewer
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-nudge'));
    }

    public function testANewcomersProfileLeavesItToTheGettingStartedChecklist(): void
    {
        $browser = self::createClient();
        // Fixture players registered just now: newcomers, whose checklist asks for the country already
        $this->withoutCountry($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.getting-started'));
        self::assertCount(0, $crawler->filter('.players-nudge'));
    }

    private function withoutCountry(KernelBrowser $browser, string $playerId): void
    {
        $browser->getContainer()->get(Connection::class)
            ->executeStatement('UPDATE player SET country = NULL WHERE id = :id', ['id' => $playerId]);
    }

    private function notANewcomer(KernelBrowser $browser, string $playerId): void
    {
        $browser->getContainer()->get(Connection::class)
            ->executeStatement("UPDATE player SET registered_at = NOW() - INTERVAL '60 days' WHERE id = :id", ['id' => $playerId]);
    }
}
