<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MultiscanBrandOptionsControllerTest extends WebTestCase
{
    public function testEveryBrandMostPuzzlesFirstIncludingUnapprovedOnes(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $client->request('GET', '/multiscan-brands.json');
        self::assertResponseIsSuccessful();

        /** @var list<array{value: string, text: string, count: int, logo: null|string, eanPrefix: string}> $brands */
        $brands = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $ids = array_column($brands, 'value');

        self::assertContains(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $ids);
        self::assertContains(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $ids, 'a brand left out gets typed again as a duplicate');

        $counts = array_column($brands, 'count');
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts, 'most puzzles first');
    }

    public function testGuestsAreSentToSignIn(): void
    {
        $client = self::createClient();
        $client->request('GET', '/multiscan-brands.json');

        self::assertResponseRedirects();
    }
}
