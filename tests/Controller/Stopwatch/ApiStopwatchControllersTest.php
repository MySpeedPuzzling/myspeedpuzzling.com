<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Stopwatch;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiStopwatchControllersTest extends WebTestCase
{
    public function testStartRequiresAuthentication(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/api/stopwatch/start', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseStatusCodeSame(302);
    }

    public function testStartCreatesNewStopwatch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('POST', '/api/stopwatch/start', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseIsSuccessful();

        /** @var array{stopwatchId: string} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertNotEmpty($data['stopwatchId']);
    }

    public function testStartWithPuzzleId(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/api/stopwatch/start', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'puzzleId' => PuzzleFixture::PUZZLE_500_02,
        ]));
        self::assertResponseIsSuccessful();

        /** @var array{stopwatchId: string} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertNotEmpty($data['stopwatchId']);
    }

    public function testPauseStopwatch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/api/stopwatch/' . StopwatchFixture::STOPWATCH_RUNNING . '/pause', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();

        /** @var array{ok: bool} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
    }

    public function testResumeStopwatch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/api/stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED . '/resume', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();

        /** @var array{ok: bool} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
    }

    public function testResetStopwatch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/api/stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED . '/reset', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();

        /** @var array{ok: bool} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
    }

    public function testRenameStopwatch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/api/stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED . '/rename', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'name' => 'Test session',
        ]));
        self::assertResponseIsSuccessful();

        /** @var array{ok: bool} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
    }

    public function testRenameToNullClears(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/api/stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED . '/rename', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'name' => null,
        ]));
        self::assertResponseIsSuccessful();
    }

    public function testPauseRequiresAuthentication(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/api/stopwatch/' . StopwatchFixture::STOPWATCH_RUNNING . '/pause', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseStatusCodeSame(302);
    }
}
