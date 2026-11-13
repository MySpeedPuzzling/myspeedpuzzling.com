<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinishProfileControllerTest extends WebTestCase
{
    public function testAnonymousUserIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/finish-profile');

        self::assertResponseRedirects();
    }

    public function testSavingTheProfileLeadsToTheHub(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/finish-profile');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $browser->submit($form, [
            $form->getName() . '[name]' => 'Renamed Puzzler',
            $form->getName() . '[country]' => 'cz',
        ]);

        self::assertResponseRedirects('/en/hub');

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertSame('Renamed Puzzler', $player->name);
        self::assertSame('cz', $player->country);
    }

    public function testNationalityAndTimezoneAreAskedUpFront(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/finish-profile');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('details select#timezone'));
        self::assertCount(1, $crawler->filter('.timezone-field select#timezone'));
        self::assertCount(0, $crawler->filter('details .country-field'));

        // Codes as values (the guess picks one) and a flag for every country
        $czechia = $crawler->filter('.country-field select option[value="cz"]');
        self::assertGreaterThanOrEqual(1, $czechia->count());
        self::assertSame('cz', $czechia->first()->attr('data-flag'));
        self::assertSame('Czechia', $czechia->first()->attr('data-alias'));
    }

    public function testTheCountryIsGuessedOnlyWhileNoneIsSaved(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/finish-profile');
        self::assertSame('', $crawler->filter('.country-field')->attr('data-profile-country-saved-zone-value') ?? '');
        // A saved country: nothing to guess, so no zone map in the page
        self::assertSame('[]', $crawler->filter('.country-field')->attr('data-profile-country-zone-countries-value'));

        $container = $browser->getContainer();
        $player = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeProfile(
            name: $player->name,
            city: $player->city,
            country: null,
            avatar: $player->avatar,
            bio: $player->bio,
            facebook: $player->facebook,
            instagram: $player->instagram,
            twitch: $player->twitch,
        );
        $player->changeTimezone('Europe/Prague');
        $container->get(EntityManagerInterface::class)->flush();

        $crawler = $browser->request('GET', '/en/finish-profile');
        $field = $crawler->filter('.country-field');
        self::assertSame('Europe/Prague', $field->attr('data-profile-country-saved-zone-value'));

        $zoneCountries = json_decode((string) $field->attr('data-profile-country-zone-countries-value'), true);
        self::assertIsArray($zoneCountries);
        self::assertSame('cz', $zoneCountries['Europe/Prague'] ?? null);
        self::assertSame('in', $zoneCountries['Asia/Calcutta'] ?? null);
    }
}
