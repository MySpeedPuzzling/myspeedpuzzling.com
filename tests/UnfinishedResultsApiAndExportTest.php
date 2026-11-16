<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use SpeedPuzzling\Web\Query\GetExportableSolvingTimes;
use SpeedPuzzling\Web\Services\PuzzlerDataExporter;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ExportFormat;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An unfinished result in the API (v1 + v0) and the results export (docs/features/competitions-management/unfinished-results-plan.md
 * "API v1"): additive fields `pieces_placed` + `finished_later_seconds`, a null time with pieces placed is no relax solve.
 */
final class UnfinishedResultsApiAndExportTest extends WebTestCase
{
    use UnfinishedResultSeeding;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string PUZZLE = PuzzleFixture::PUZZLE_500_05;

    private KernelBrowser $browser;
    private string $timeId;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->timeId = $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479, finishedLaterSeconds: 6492)->id->toString();
    }

    public function testApiV1ResultsCarryThePiecesPlaced(): void
    {
        $token = OAuth2TestHelper::createAccessToken($this->browser, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, self::PLAYER, ['results:read']);
        OAuth2TestHelper::addBearerToken($this->browser, $token);

        $this->browser->request('GET', '/api/v1/me/results');

        self::assertResponseIsSuccessful();
        /** @var array{results: list<array<string, mixed>>} $response */
        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $byId = array_column($response['results'], null, 'time_id');

        self::assertArrayHasKey($this->timeId, $byId);
        self::assertNull($byId[$this->timeId]['time_seconds']);
        self::assertSame(479, $byId[$this->timeId]['pieces_placed']);
        self::assertSame(6492, $byId[$this->timeId]['finished_later_seconds']);

        // A timed result has the fields, empty
        $timed = array_values(array_filter($response['results'], fn (array $result): bool => $result['time_id'] !== $this->timeId))[0];
        self::assertArrayHasKey('pieces_placed', $timed);
        self::assertNull($timed['pieces_placed']);
        self::assertNull($timed['finished_later_seconds']);
    }

    public function testApiV0NeverCallsItRelax(): void
    {
        $this->browser->request('GET', '/api/v0/players/' . self::PLAYER . '/results?token=any');

        self::assertResponseIsSuccessful();
        /** @var array{solo: list<array<string, mixed>>} $response */
        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $byId = array_column($response['solo'], null, 'id');

        self::assertArrayHasKey($this->timeId, $byId);
        self::assertFalse($byId[$this->timeId]['is_relax_mode']);
        self::assertSame(479, $byId[$this->timeId]['pieces_placed']);
        self::assertSame(6492, $byId[$this->timeId]['finished_later_seconds']);
    }

    public function testTheExportHasThePiecesPlacedColumns(): void
    {
        $times = self::getContainer()->get(GetExportableSolvingTimes::class)->byPlayerId(self::PLAYER);
        /** @var list<array<string, mixed>> $json */
        $json = json_decode(self::getContainer()->get(PuzzlerDataExporter::class)->export($times, ExportFormat::Json), true, 512, JSON_THROW_ON_ERROR);
        $byId = array_column($json, null, 'result_id');

        self::assertArrayHasKey($this->timeId, $byId);
        self::assertSame(479, $byId[$this->timeId]['pieces_placed']);
        self::assertSame(6492, $byId[$this->timeId]['finished_later_seconds']);
        self::assertNull($byId[$this->timeId]['seconds_to_solve']);
    }
}
