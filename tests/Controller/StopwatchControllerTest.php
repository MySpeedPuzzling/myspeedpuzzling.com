<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StopwatchControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/stopwatch');
        $this->assertResponseRedirects();
    }

    public function testLoggedInUserIsRedirectedToActiveStopwatch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/stopwatch');
        $this->assertResponseRedirects();
    }

    public function testNonExistentStopwatchRedirectsInsteadOf404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // A valid UUID that doesn't exist should redirect, not 404
        $browser->request('GET', '/en/stopwatch/018d000d-0000-0000-0000-999999999999');
        $this->assertResponseRedirects('/en/stopwatch');
    }

    public function testInvalidUuidRedirectsInsteadOf404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/stopwatch/not-a-valid-uuid');
        $this->assertResponseRedirects('/en/stopwatch');
    }

    public function testExistingStopwatchReturns200(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED);
        $this->assertResponseIsSuccessful();
    }

    public function testPuzzleStopwatchPageReturns200(): void
    {
        $browser = self::createClient();
        // Use PLAYER_ADMIN who has no running stopwatches (no redirect)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/puzzle-stopwatch/' . PuzzleFixture::PUZZLE_500_01);
        $this->assertResponseIsSuccessful();
    }
}
