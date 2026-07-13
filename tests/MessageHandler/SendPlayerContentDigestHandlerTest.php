<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\MessageHandler\SendPlayerContentDigestHandler;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\ContentDigestLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Digest\WeeklyDigestDataProvider;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\TransportSpy;
use SpeedPuzzling\Web\Value\DigestPeriod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\NullLogger;

final class SendPlayerContentDigestHandlerTest extends KernelTestCase
{
    private Connection $database;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testSendsDigestWithActivityAndLogsIt(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());
        $this->insertSolve(PlayerFixture::PLAYER_WITH_STRIPE, $this->clock->now());

        $transport = new TransportSpy();
        $handler = $this->handler($transport);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', $period->key));
        $this->entityManager->flush();

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('emails/content_digest_weekly.html.twig', $email->getHtmlTemplate());
        self::assertNotNull($email->getHeaders()->get('List-Unsubscribe'));
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame('bulk', $email->getHeaders()->get('Precedence')?->getBodyAsString());

        $log = $this->logRow(PlayerFixture::PLAYER_WITH_STRIPE, $period->key);
        self::assertNotNull($log);
        self::assertSame('sent', $log['status']);
        self::assertTrue((bool) $log['had_activity']);
    }

    public function testNoActivityVariantStillSendsAndIsMarked(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());

        $transport = new TransportSpy();
        $handler = $this->handler($transport);

        // PLAYER_PRIVATE has no solves in the current week (fixtures are day-offset based,
        // but the week window only catches recent ones — delete to be deterministic).
        $this->database->executeStatement(
            'DELETE FROM puzzle_solving_time WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_PRIVATE],
        );
        $this->database->executeStatement(
            'DELETE FROM xp_entry WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_PRIVATE],
        );

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_PRIVATE, 'weekly', $period->key));
        $this->entityManager->flush();

        self::assertCount(1, $transport->sent);

        $log = $this->logRow(PlayerFixture::PLAYER_PRIVATE, $period->key);
        self::assertNotNull($log);
        self::assertFalse((bool) $log['had_activity']);
    }

    public function testSuppressedWhileFeatureFlagActive(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());

        $transport = new TransportSpy();
        $handler = $this->handler($transport, flagActive: true);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', $period->key));
        $this->entityManager->flush();

        self::assertCount(0, $transport->sent);
        self::assertNull($this->logRow(PlayerFixture::PLAYER_WITH_STRIPE, $period->key));
    }

    public function testStalePeriodIsSkippedSilently(): void
    {
        $transport = new TransportSpy();
        $handler = $this->handler($transport);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', '2020-W01'));
        $this->entityManager->flush();

        self::assertCount(0, $transport->sent);
        self::assertNull($this->logRow(PlayerFixture::PLAYER_WITH_STRIPE, '2020-W01'));
    }

    public function testExperienceOptOutIsExcluded(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());
        $this->database->executeStatement(
            'UPDATE player SET experience_system_opted_out = true WHERE id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $transport = new TransportSpy();
        $handler = $this->handler($transport);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', $period->key));

        self::assertCount(0, $transport->sent);
    }

    public function testFrequencyNoneIsExcluded(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());
        $this->database->executeStatement(
            "UPDATE player SET content_digest_frequency = 'none' WHERE id = :playerId",
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $transport = new TransportSpy();
        $handler = $this->handler($transport);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', $period->key));

        self::assertCount(0, $transport->sent);
    }

    public function testAlreadyLoggedPeriodIsNotSentTwice(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());
        $this->insertLog(PlayerFixture::PLAYER_WITH_STRIPE, $period->key);

        $transport = new TransportSpy();
        $handler = $this->handler($transport);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', $period->key));

        self::assertCount(0, $transport->sent);
    }

    public function testPermanentRecipientFailureLogsAndAcks(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());

        $transport = new TransportSpy(new UnexpectedResponseException('550 mailbox unavailable', 550));
        $handler = $this->handler($transport);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', $period->key));
        $this->entityManager->flush();

        $log = $this->logRow(PlayerFixture::PLAYER_WITH_STRIPE, $period->key);
        self::assertNotNull($log);
        self::assertSame('failed_permanent', $log['status']);
    }

    public function testTransientFailureBubblesForRetry(): void
    {
        $period = DigestPeriod::weeklyFor($this->clock->now());

        $transport = new TransportSpy(new UnexpectedResponseException('421 try again later', 421));
        $handler = $this->handler($transport);

        $this->expectException(UnexpectedResponseException::class);

        $handler(new SendPlayerContentDigest(PlayerFixture::PLAYER_WITH_STRIPE, 'weekly', $period->key));
    }

    private function handler(TransportInterface $transport, bool $flagActive = false): SendPlayerContentDigestHandler
    {
        $container = self::getContainer();

        return new SendPlayerContentDigestHandler(
            playerRepository: $container->get(PlayerRepository::class),
            getPlayerProfile: $container->get(GetPlayerProfile::class),
            contentDigestLogRepository: $container->get(ContentDigestLogRepository::class),
            weeklyDigestDataProvider: $container->get(WeeklyDigestDataProvider::class),
            transport: $transport,
            translator: $container->get(TranslatorInterface::class),
            uriSigner: $container->get(UriSigner::class),
            urlGenerator: $container->get(UrlGeneratorInterface::class),
            database: $this->database,
            clock: $this->clock,
            xpFeatureGate: new XpFeatureGate(adminOnly: $flagActive),
            logger: new NullLogger(),
        );
    }

    /**
     * @return array{status: string, had_activity: bool}|null
     */
    private function logRow(string $playerId, string $periodKey): null|array
    {
        /** @var array{status: string, had_activity: bool}|false $row */
        $row = $this->database->fetchAssociative(
            "SELECT status, had_activity FROM content_digest_log WHERE player_id = :playerId AND digest_type = 'weekly' AND period_key = :periodKey",
            ['playerId' => $playerId, 'periodKey' => $periodKey],
        );

        return $row === false ? null : $row;
    }

    private function insertLog(string $playerId, string $periodKey, bool $hadActivity = true, string $status = 'sent'): void
    {
        $this->database->executeStatement(
            "INSERT INTO content_digest_log (id, player_id, digest_type, period_key, sent_at, had_activity, status)
             VALUES (:id, :playerId, 'weekly', :periodKey, NOW(), :hadActivity, :status)",
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'periodKey' => $periodKey,
                'hadActivity' => $hadActivity ? 'true' : 'false',
                'status' => $status,
            ],
        );
    }

    private function insertSolve(string $playerId, \DateTimeImmutable $at): void
    {
        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, 3600, :playerId, :puzzleId, :at, true, NULL, :at,
                 NULL, NULL, false, false, 1, \'solo\', false, 500)',
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'puzzleId' => PuzzleFixture::PUZZLE_500_04,
                'at' => $at->format('Y-m-d H:i:s'),
            ],
        );
    }
}
