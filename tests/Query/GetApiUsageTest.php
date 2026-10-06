<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetApiUsage;
use SpeedPuzzling\Web\Query\GetApiUsageCallers;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiCallerKind;
use SpeedPuzzling\Web\Value\ApiUsageFilter;
use SpeedPuzzling\Web\Value\ApiUsageMonth;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetApiUsageTest extends KernelTestCase
{
    private const string MONTH = '2026-09';

    private GetApiUsage $getApiUsage;
    private Connection $connection;
    private ApiUsageMonth $month;
    private ApiCaller $pat;
    private ApiCaller $app;
    private ApiCaller $machine;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getApiUsage = $container->get(GetApiUsage::class);
        $this->connection = $container->get(Connection::class);
        $this->month = ApiUsageMonth::fromUserInput(self::MONTH, new DateTimeImmutable('2026-10-07 12:00:00 UTC'));

        $tokenId = Uuid::uuid7()->toString();
        $this->connection->insert('personal_access_token', [
            'id' => $tokenId,
            'player_id' => PlayerFixture::PLAYER_REGULAR,
            'name' => 'Spreadsheet',
            'token_hash' => hash('sha256', $tokenId),
            'token_prefix' => 'msp_pat_abcd',
            'fair_use_policy_accepted_at' => '2026-09-01 00:00:00',
            'created_at' => '2026-09-01 00:00:00',
        ]);

        $this->pat = ApiCaller::personalAccessToken($tokenId, PlayerFixture::PLAYER_REGULAR);
        $this->app = ApiCaller::oauth2User(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, PlayerFixture::PLAYER_REGULAR);
        $this->machine = ApiCaller::oauth2Client(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID);

        $this->insert($this->pat, '2026-09-01', 'GET /api/v1/me', '2xx', 10, 1000, peak: 4);
        $this->insert($this->pat, '2026-09-02', 'GET /api/v1/me', '2xx', 5, 500, peak: 2);
        $this->insert($this->pat, '2026-09-02', 'GET /api/v1/me/results', '4xx', 3, 30, peak: null);
        $this->insert($this->app, '2026-09-02', 'GET /api/v1/me/results', '2xx', 7, 70, peak: 7);
        $this->insert($this->machine, '2026-09-03', 'GET /api/v1/puzzles', '429', 2, 2, peak: 9);
        // Another month - never counted
        $this->insert($this->pat, '2026-10-01', 'GET /api/v1/me', '2xx', 100, 100, peak: 50);
    }

    public function testTotalsOfAPlayer(): void
    {
        $totals = $this->getApiUsage->totals(new ApiUsageFilter(playerId: PlayerFixture::PLAYER_REGULAR), $this->month);

        self::assertSame(25, $totals->requests);
        self::assertSame(3, $totals->failed);
        self::assertSame(0, $totals->tooManyRequests);
        self::assertSame(64, $totals->averageMs());
        self::assertSame(7, $totals->peakRequestsPerMinute);
        self::assertSame(12.0, $totals->failedPercent());
    }

    public function testAnAppIsSummedOverItsUsersAndItsOwnCalls(): void
    {
        $totals = $this->getApiUsage->totals(ApiUsageFilter::forApp(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID), $this->month);

        self::assertSame(9, $totals->requests);
        self::assertSame(2, $totals->failed);
        self::assertSame(2, $totals->tooManyRequests);
        self::assertSame(9, $totals->peakRequestsPerMinute);
    }

    public function testDailyAndPerRequestType(): void
    {
        $filter = ApiUsageFilter::forCaller($this->pat);

        self::assertSame(
            ['2xx' => ['2026-09-01' => 10, '2026-09-02' => 5], '4xx' => ['2026-09-02' => 3]],
            $this->getApiUsage->daily($filter, $this->month, 'status_class'),
        );

        $operations = $this->getApiUsage->byOperation($filter, $this->month);
        self::assertCount(2, $operations);
        self::assertSame('GET /api/v1/me', $operations[0]->operation);
        self::assertSame(15, $operations[0]->requests);
        self::assertSame(100, $operations[0]->averageMs());
        self::assertSame(3, $operations[1]->failed);

        self::assertSame(['2xx' => 15, '4xx' => 3], $this->getApiUsage->byStatusClass($filter, $this->month));
        self::assertSame([$this->pat->key() => 18], $this->getApiUsage->requestsByCaller($filter, $this->month));
    }

    public function testRecentTotalsForTheEditProfilePage(): void
    {
        $recent = $this->getApiUsage->recentForPlayer(
            PlayerFixture::PLAYER_REGULAR,
            [OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID],
            new DateTimeImmutable('2026-10-01 12:00:00 UTC'),
        );

        // 30 days = 2 Sep - 1 Oct: 1 Sep falls out
        self::assertSame(108, $recent->personalAccessToken((string) $this->pat->personalAccessTokenId));
        self::assertSame(7, $recent->connectedApp(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID));
        self::assertSame(9, $recent->ownApp(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID));
        self::assertSame(0, $recent->ownApp('somebody-elses-app'));
    }

    public function testCallersAreNamedForAdmins(): void
    {
        $callers = self::getContainer()->get(GetApiUsageCallers::class)->forMonth(new ApiUsageFilter(), $this->month);
        $byKey = [];

        foreach ($callers as $row) {
            $byKey[$row->caller->key()] = $row;
        }

        self::assertSame(18, $byKey[$this->pat->key()]->requests);
        self::assertSame('Spreadsheet', $byKey[$this->pat->key()]->tokenName);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $byKey[$this->pat->key()]->playerName);
        self::assertSame(4, $byKey[$this->pat->key()]->peakRequestsPerMinute);
        self::assertSame(2, $byKey[$this->machine->key()]->tooManyRequests);
        self::assertNull($byKey[$this->machine->key()]->playerName);

        $onlyMachines = self::getContainer()->get(GetApiUsageCallers::class)->forMonth(new ApiUsageFilter(callerKind: ApiCallerKind::OAuth2Client), $this->month);
        self::assertSame([$this->machine->key()], array_map(static fn ($row): string => $row->caller->key(), $onlyMachines));
    }

    private function insert(ApiCaller $caller, string $day, string $operation, string $statusClass, int $requests, int $durationMs, null|int $peak): void
    {
        $this->connection->insert('api_usage_day', [
            'id' => Uuid::uuid7()->toString(),
            'day' => $day,
            'caller_key' => $caller->key(),
            'player_id' => $caller->playerId,
            'personal_access_token_id' => $caller->personalAccessTokenId,
            'oauth2_client_identifier' => $caller->oauth2ClientIdentifier,
            'operation' => $operation,
            'status_class' => $statusClass,
            'requests' => $requests,
            'duration_ms_total' => $durationMs,
        ]);

        if ($peak !== null) {
            $this->connection->insert('api_caller_day', [
                'id' => Uuid::uuid7()->toString(),
                'day' => $day,
                'caller_key' => $caller->key(),
                'player_id' => $caller->playerId,
                'personal_access_token_id' => $caller->personalAccessTokenId,
                'oauth2_client_identifier' => $caller->oauth2ClientIdentifier,
                'peak_requests_per_minute' => $peak,
                'last_request_at' => $day . ' 12:00:00+00',
            ]);
        }
    }
}
