<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SetMyCountryControllerTest extends WebTestCase
{
    private const string ENDPOINT = '/en/puzzlers/my-country';

    public function testSavingLandsOnThatCountrysPageWithAFlash(): void
    {
        $browser = $this->playerWithoutCountry();

        $browser->request('POST', self::ENDPOINT, ['_token' => $this->tokenFromThePage($browser), 'country' => 'cz']);

        self::assertResponseRedirects('/en/puzzlers?scope=cz');
        self::assertSame('cz', $this->countryOf($browser, PlayerFixture::PLAYER_REGULAR));

        $crawler = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', "Saved: you're now among Czechia's puzzlers.");
        self::assertCount(0, $crawler->filter('.players-nudge'), 'The nudge is gone once the country is there');
        self::assertSelectorExists('.players-scope-switch a[href="/en/puzzlers?scope=cz"]');
    }

    public function testAnUnknownCodeIsA422WithTheFormAgain(): void
    {
        $browser = $this->playerWithoutCountry();

        $crawler = $browser->request('POST', self::ENDPOINT, ['_token' => $this->tokenFromThePage($browser), 'country' => 'xx']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.players-nudge-error', "We couldn't find that country.");
        self::assertCount(1, $crawler->filter('.players-nudge form[action="' . self::ENDPOINT . '"] select[name="country"][aria-invalid="true"]'));
        self::assertNull($this->countryOf($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testAForgedRequestChangesNothing(): void
    {
        $browser = $this->playerWithoutCountry();

        $browser->request('POST', self::ENDPOINT, ['_token' => 'forged', 'country' => 'cz']);

        self::assertResponseRedirects('/en/puzzlers');
        self::assertNull($this->countryOf($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-warning', "That didn't go through. Please try again.");
    }

    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::ENDPOINT, ['_token' => 'any', 'country' => 'cz']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testTheFormIsPostOnly(): void
    {
        $browser = $this->playerWithoutCountry();

        $browser->request('GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(405);
    }

    private function playerWithoutCountry(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)
            ->executeStatement('UPDATE player SET country = NULL WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    private function tokenFromThePage(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', '/en/puzzlers');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('.players-nudge form[action="' . self::ENDPOINT . '"] input[name="_token"]');
        self::assertCount(1, $token);

        return (string) $token->attr('value');
    }

    private function countryOf(KernelBrowser $browser, string $playerId): null|string
    {
        $country = $browser->getContainer()->get(Connection::class)
            ->fetchOne('SELECT country FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertTrue($country === null || is_string($country));

        return $country;
    }
}
