<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

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
            // Countries are listed twice (most common + all), so the options are numbered: 0 is the first, Czechia
            $form->getName() . '[country]' => '0',
        ]);

        self::assertResponseRedirects('/en/hub');

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertSame('Renamed Puzzler', $player->name);
        self::assertSame('cz', $player->country);
    }
}
