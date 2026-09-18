<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActivityCalendarControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPublicPlayerCalendar(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousUserIsRedirectedForPrivatePlayer(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseRedirects();
    }

    public function testLoggedInVisitorIsRedirectedForPrivatePlayer(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseRedirects();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMalformedQueries(): array
    {
        return [
            'quote after the year' => ["year=2025'"],
            'template injection in month' => ['month=${7*7}&year=2025'],
            'array instead of a value' => ['year[]=2024'],
        ];
    }

    /**
     * A malformed period falls back to the current month like an out-of-range one, instead of a 400.
     */
    #[DataProvider('provideMalformedQueries')]
    public function testMalformedQueryFallsBackToCurrentMonth(string $query): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_REGULAR . '?' . $query);

        self::assertResponseIsSuccessful();
    }

    public function testOwnerCanAccessTheirOwnPrivateCalendar(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseIsSuccessful();
    }
}
