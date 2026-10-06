<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2UserConsent;
use SpeedPuzzling\Web\Entity\PersonalAccessToken;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\FlushApiUsage;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryApiUsageCounter;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiRequestRecord;
use SpeedPuzzling\Web\Value\ApiStatusClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class FlushApiUsageHandlerTest extends KernelTestCase
{
    private InMemoryApiUsageCounter $counter;
    private MessageBusInterface $messageBus;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Test-only service (config/services_test.php) - the dev container phpstan reads has the Redis one
        $counter = $container->get(InMemoryApiUsageCounter::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        self::assertInstanceOf(InMemoryApiUsageCounter::class, $counter);
        $this->counter = $counter;
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->now = $container->get(ClockInterface::class)->now();
    }

    public function testCopiesTheCountersIntoTheDailyTablesAndStampsLastUsed(): void
    {
        $token = $this->createToken();
        $consent = $this->createConsent();
        $pat = ApiCaller::personalAccessToken($token->id->toString(), PlayerFixture::PLAYER_REGULAR);
        $app = ApiCaller::oauth2User(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, PlayerFixture::PLAYER_REGULAR);
        $machine = ApiCaller::oauth2Client(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID);
        $yesterday = $this->now->modify('-1 day');

        $this->record($pat, 'GET /api/v1/me', ApiStatusClass::Success, 10, $this->now);
        $this->record($pat, 'GET /api/v1/me', ApiStatusClass::Success, 20, $this->now);
        $this->record($pat, 'GET /api/v1/me', ApiStatusClass::Success, 5, $yesterday);
        $this->record($app, 'GET /api/v1/me/results', ApiStatusClass::ClientError, 7, $this->now);
        $this->record($machine, 'GET /api/v1/puzzles', ApiStatusClass::TooManyRequests, 1, $this->now);

        $this->flush();

        self::assertSame(2, $this->requests($pat, 'GET /api/v1/me', '2xx', $this->now));
        self::assertSame(30, $this->duration($pat, 'GET /api/v1/me', '2xx', $this->now));
        self::assertSame(1, $this->requests($pat, 'GET /api/v1/me', '2xx', $yesterday));
        self::assertSame(1, $this->requests($app, 'GET /api/v1/me/results', '4xx', $this->now));
        self::assertSame(1, $this->requests($machine, 'GET /api/v1/puzzles', '429', $this->now));

        /** @var array{player_id: null|string, personal_access_token_id: null|string, oauth2_client_identifier: null|string} $columns */
        $columns = $this->connection->fetchAssociative(
            'SELECT player_id, personal_access_token_id, oauth2_client_identifier FROM api_usage_day WHERE caller_key = :key LIMIT 1',
            ['key' => $machine->key()],
        );
        self::assertNull($columns['player_id']);
        self::assertNull($columns['personal_access_token_id']);
        self::assertSame(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, $columns['oauth2_client_identifier']);

        /** @var int|string $peak */
        $peak = $this->connection->fetchOne(
            'SELECT peak_requests_per_minute FROM api_caller_day WHERE caller_key = :key AND day = :day',
            ['key' => $pat->key(), 'day' => $this->now->format('Y-m-d')],
        );
        self::assertGreaterThanOrEqual(1, (int) $peak);

        $this->entityManager->clear();
        $token = $this->entityManager->find(PersonalAccessToken::class, $token->id);
        $consent = $this->entityManager->find(OAuth2UserConsent::class, $consent->id);
        self::assertNotNull($token?->lastUsedAt);
        self::assertSame($this->now->getTimestamp(), $token->lastUsedAt->getTimestamp());
        self::assertNotNull($consent?->lastUsedAt);
    }

    public function testRunningAgainNeverLowersOrDoublesANumber(): void
    {
        $caller = ApiCaller::oauth2Client(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID);

        $this->record($caller, 'GET /api/v1/puzzles', ApiStatusClass::Success, 10, $this->now);
        $this->record($caller, 'GET /api/v1/puzzles', ApiStatusClass::Success, 10, $this->now);
        $this->flush();
        $this->flush();

        self::assertSame(2, $this->requests($caller, 'GET /api/v1/puzzles', '2xx', $this->now));

        // Redis restarted and lost its counters: the next copy holds a smaller total
        $this->connection->executeStatement(
            'UPDATE api_usage_day SET requests = 50 WHERE caller_key = :key',
            ['key' => $caller->key()],
        );
        $this->flush();

        self::assertSame(50, $this->requests($caller, 'GET /api/v1/puzzles', '2xx', $this->now));
    }

    public function testSkipsCallersWhosePlayerIsGone(): void
    {
        $gone = ApiCaller::oauth2User(OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, Uuid::uuid7()->toString());
        $goneToken = ApiCaller::personalAccessToken(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_REGULAR);

        $this->record($gone, 'GET /api/v1/me', ApiStatusClass::Success, 1, $this->now);
        $this->record($goneToken, 'GET /api/v1/me', ApiStatusClass::Success, 1, $this->now);
        $this->flush();

        /** @var int|string $rows */
        $rows = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM api_usage_day WHERE caller_key IN (:a, :b)',
            ['a' => $gone->key(), 'b' => $goneToken->key()],
        );
        self::assertSame(0, (int) $rows);
    }

    private function flush(): void
    {
        $this->messageBus->dispatch(new FlushApiUsage());
    }

    private function record(ApiCaller $caller, string $operation, ApiStatusClass $statusClass, int $durationMs, DateTimeImmutable $at): void
    {
        $this->counter->record(new ApiRequestRecord($caller, $operation, $statusClass, $durationMs, $at));
    }

    private function requests(ApiCaller $caller, string $operation, string $statusClass, DateTimeImmutable $day): int
    {
        return $this->column('requests', $caller, $operation, $statusClass, $day);
    }

    private function duration(ApiCaller $caller, string $operation, string $statusClass, DateTimeImmutable $day): int
    {
        return $this->column('duration_ms_total', $caller, $operation, $statusClass, $day);
    }

    private function column(string $column, ApiCaller $caller, string $operation, string $statusClass, DateTimeImmutable $day): int
    {
        $value = $this->connection->fetchOne(
            "SELECT {$column} FROM api_usage_day WHERE caller_key = :key AND operation = :operation AND status_class = :status AND day = :day",
            [
                'key' => $caller->key(),
                'operation' => $operation,
                'status' => $statusClass,
                'day' => $day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            ],
        );
        self::assertNotFalse($value, 'No row stored');
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function createToken(): PersonalAccessToken
    {
        $player = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);

        $token = new PersonalAccessToken(
            id: Uuid::uuid7(),
            player: $player,
            name: 'Usage test',
            tokenHash: hash('sha256', Uuid::uuid7()->toString()),
            tokenPrefix: 'msp_pat_test',
            fairUsePolicyAcceptedAt: $this->now,
            createdAt: $this->now,
        );
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    private function createConsent(): OAuth2UserConsent
    {
        $player = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);

        $consent = new OAuth2UserConsent(
            id: Uuid::uuid7(),
            player: $player,
            clientIdentifier: OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            scopes: ['profile:read'],
            consentedAt: $this->now,
        );
        $this->entityManager->persist($consent);
        $this->entityManager->flush();

        return $consent;
    }
}
