<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Query\GetBotTrustEligibility;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who may carry the bot-blocker's __bb_trust cookie: a verified e-mail, a social login, a solving time or an account
 * at least 14 days old - any one is enough. Seeded on fresh accounts so each criterion is tested alone.
 */
final class GetBotTrustEligibilityTest extends KernelTestCase
{
    private GetBotTrustEligibility $query;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetBotTrustEligibility::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testBrandNewBareAccountIsNotEligible(): void
    {
        $account = $this->seedAccount(TestClock::now());
        // A player without any time does not change that
        $this->seedPlayer($account->userId);

        self::assertFalse($this->query->isEligible($account->userId));
    }

    public function testVerifiedEmailIsEligible(): void
    {
        $account = $this->seedAccount(TestClock::now());
        $account->markEmailVerified(TestClock::now());
        $this->entityManager->flush();

        self::assertTrue($this->query->isEligible($account->userId));
    }

    public function testSocialLoginIsEligible(): void
    {
        $account = $this->seedAccount(TestClock::now());

        $this->connection->insert('oauth_identity', [
            'id' => Uuid::uuid7()->toString(),
            'user_account_id' => $account->id->toString(),
            'provider' => 'google',
            'provider_user_id' => 'google-' . bin2hex(random_bytes(4)),
            'email_at_link' => null,
            'linked_at' => TestClock::now()->format('Y-m-d H:i:sP'),
        ]);

        self::assertTrue($this->query->isEligible($account->userId));
    }

    public function testSolvingTimeOfThePlayerIsEligible(): void
    {
        $account = $this->seedAccount(TestClock::now());
        $player = $this->seedPlayer($account->userId);

        $this->connection->insert('puzzle_solving_time', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $player->id->toString(),
            'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
            'seconds_to_solve' => 3600,
            'tracked_at' => TestClock::now()->format('Y-m-d H:i:s'),
            'finished_at' => TestClock::now()->format('Y-m-d H:i:s'),
            'verified' => 'true',
            'first_attempt' => 'false',
            'unboxed' => 'false',
            'puzzling_type' => 'solo',
        ]);

        self::assertTrue($this->query->isEligible($account->userId));
    }

    public function testAccountAgeCountsFromFourteenDays(): void
    {
        $boundary = TestClock::now()->modify(sprintf('-%d days', GetBotTrustEligibility::TRUSTED_ACCOUNT_AGE_DAYS));
        $oldEnough = $this->seedAccount($boundary);
        $tooYoung = $this->seedAccount($boundary->modify('+1 minute'));

        self::assertTrue($this->query->isEligible($oldEnough->userId));
        self::assertFalse($this->query->isEligible($tooYoung->userId));
    }

    public function testUnknownAccountIsNotEligible(): void
    {
        self::assertFalse($this->query->isEligible('msp|no-such-account'));
    }

    private function seedAccount(DateTimeImmutable $registeredAt): UserAccount
    {
        $userId = 'msp|bbtrust-' . bin2hex(random_bytes(4));
        $account = new UserAccount(Uuid::uuid7(), $userId, $userId . '@example.com', $registeredAt);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function seedPlayer(string $userId): Player
    {
        $player = new Player(Uuid::uuid7(), 'BBT' . bin2hex(random_bytes(3)), $userId, 'Trust Tester', TestClock::now());

        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }
}
