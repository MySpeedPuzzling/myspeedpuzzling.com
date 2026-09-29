<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzlesControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testAnonymousUserCanAccessPuzzlesPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPuzzlesPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle');

        $this->assertResponseIsSuccessful();
    }

    public function testPuzzlesPageWithSortByParameter(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle?sortBy=newest');

        $this->assertResponseIsSuccessful();
    }

    public function testGuestDefaultPageHasNoListFilterAndPaysNothingForIt(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle'); // warm the shared first-page cache

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/puzzle');
        $this->assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#puzzle-search-list'));
        foreach ($this->executedSql($browser) as $sql) {
            self::assertDoesNotMatchRegularExpression('/FROM collection\s+WHERE/', $sql);
        }
    }

    public function testListOptionsCostMembersOneQuery(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/puzzle');

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/puzzle');
        $this->assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#puzzle-search-list'));
        self::assertCount(9, $crawler->filter('#puzzle-search-list option[value!=""]'));

        $collectionQueries = array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => preg_match('/FROM collection\s+WHERE/', $sql) === 1,
        );
        self::assertCount(1, $collectionQueries);
    }

    public function testNonMemberSeesTheFreeListsOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle');
        $this->assertResponseIsSuccessful();

        self::assertSame(
            ['library', 'wishlist', 'unsolved', 'solved'],
            array_values(array_filter($crawler->filter('#puzzle-search-list option')->extract(['value']))),
        );
    }
}
