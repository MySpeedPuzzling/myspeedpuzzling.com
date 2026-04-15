<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\FeatureRequestStatusChanged;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenFeatureRequestStatusChanged;
use SpeedPuzzling\Web\Query\GetFeatureRequestVoters;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotifyWhenFeatureRequestStatusChangedTest extends KernelTestCase
{
    private const string COMPLETED_AUTHOR_TEMPLATE = 'emails/feature_request_completed.html.twig';
    private const string COMPLETED_UPVOTER_TEMPLATE = 'emails/feature_request_completed_upvoter.html.twig';
    private const string DECLINED_AUTHOR_TEMPLATE = 'emails/feature_request_declined.html.twig';
    private const string DECLINED_UPVOTER_TEMPLATE = 'emails/feature_request_declined_upvoter.html.twig';

    private NotifyWhenFeatureRequestStatusChanged $handler;
    private TestMailerSpy $mailer;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->handler = new NotifyWhenFeatureRequestStatusChanged(
            featureRequestRepository: $container->get(FeatureRequestRepository::class),
            getFeatureRequestVoters: $container->get(GetFeatureRequestVoters::class),
            mailer: $this->mailer,
            translator: $container->get(TranslatorInterface::class),
        );
    }

    public function testSkipsTransitionsOtherThanCompletedOrDeclined(): void
    {
        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::Open,
            newStatus: FeatureRequestStatus::InProgress,
        ));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testCompletedAuthorOnlyWhenNoUpvoters(): void
    {
        // FEATURE_REQUEST_NEW: author=PLAYER_ADMIN, no votes
        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_NEW),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->assertTemplatedEmail($this->mailer->sent[0]);
        self::assertSame(['admin@speedpuzzling.cz'], $this->toAddresses($email));
        self::assertSame(self::COMPLETED_AUTHOR_TEMPLATE, $email->getHtmlTemplate());
    }

    public function testCompletedAuthorPlusAllUpvoters(): void
    {
        // FEATURE_REQUEST_POPULAR: author=PLAYER_WITH_STRIPE, voters=[ADMIN, REGULAR]
        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        self::assertCount(3, $this->mailer->sent);

        $summary = $this->summarize($this->mailer->sent);

        self::assertSame(self::COMPLETED_AUTHOR_TEMPLATE, $summary[PlayerFixture::PLAYER_WITH_STRIPE_EMAIL]);
        self::assertSame(self::COMPLETED_UPVOTER_TEMPLATE, $summary['admin@speedpuzzling.cz']);
        self::assertSame(self::COMPLETED_UPVOTER_TEMPLATE, $summary[PlayerFixture::PLAYER_REGULAR_EMAIL]);
    }

    public function testDeclinedSendsDeclinedTemplates(): void
    {
        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::Open,
            newStatus: FeatureRequestStatus::Declined,
        ));

        self::assertCount(3, $this->mailer->sent);

        $summary = $this->summarize($this->mailer->sent);

        self::assertSame(self::DECLINED_AUTHOR_TEMPLATE, $summary[PlayerFixture::PLAYER_WITH_STRIPE_EMAIL]);
        self::assertSame(self::DECLINED_UPVOTER_TEMPLATE, $summary['admin@speedpuzzling.cz']);
        self::assertSame(self::DECLINED_UPVOTER_TEMPLATE, $summary[PlayerFixture::PLAYER_REGULAR_EMAIL]);
    }

    public function testSkipsWhenAuthorHasNoEmailButStillNotifiesUpvoters(): void
    {
        $this->connection->executeStatement(
            'UPDATE player SET email = NULL WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        $this->entityManager->clear();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::Open,
            newStatus: FeatureRequestStatus::Declined,
        ));

        self::assertCount(2, $this->mailer->sent);
        foreach ($this->mailer->sent as $message) {
            $email = $this->assertTemplatedEmail($message);
            self::assertSame(self::DECLINED_UPVOTER_TEMPLATE, $email->getHtmlTemplate());
        }
    }

    public function testSkipsUpvoterWithoutEmail(): void
    {
        $this->connection->executeStatement(
            'UPDATE player SET email = NULL WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        $this->entityManager->clear();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        // Expected: author (PLAYER_WITH_STRIPE) + 1 upvoter (ADMIN). REGULAR skipped.
        self::assertCount(2, $this->mailer->sent);

        $summary = $this->summarize($this->mailer->sent);

        self::assertArrayNotHasKey(PlayerFixture::PLAYER_REGULAR_EMAIL, $summary);
        self::assertSame(self::COMPLETED_AUTHOR_TEMPLATE, $summary[PlayerFixture::PLAYER_WITH_STRIPE_EMAIL]);
        self::assertSame(self::COMPLETED_UPVOTER_TEMPLATE, $summary['admin@speedpuzzling.cz']);
    }

    public function testAuthorNeverReceivesUpvoterEmailEvenIfTheyAlsoVoted(): void
    {
        // Defensive: bypass the business rule and insert a vote row by the author.
        $this->connection->executeStatement(
            'INSERT INTO feature_request_vote (id, feature_request_id, voter_id, voted_at) '
            . 'VALUES (:id, :featureRequestId, :voterId, NOW())',
            [
                'id' => Uuid::uuid7()->toString(),
                'featureRequestId' => FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
                'voterId' => PlayerFixture::PLAYER_WITH_STRIPE,
            ],
        );

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        // Total: author + 2 other upvoters (ADMIN, REGULAR) = 3 — author row is excluded
        self::assertCount(3, $this->mailer->sent);

        $summary = $this->summarize($this->mailer->sent);
        self::assertSame(self::COMPLETED_AUTHOR_TEMPLATE, $summary[PlayerFixture::PLAYER_WITH_STRIPE_EMAIL]);

        $authorEmails = array_filter(
            $this->mailer->sent,
            fn(RawMessage $m): bool => $this->toAddresses($this->assertTemplatedEmail($m))[0] === PlayerFixture::PLAYER_WITH_STRIPE_EMAIL,
        );
        self::assertCount(1, $authorEmails, 'Author must receive exactly one email');
    }

    public function testUpvoterWithMultipleVotesReceivesOnlyOneEmail(): void
    {
        // The unique constraint on (feature_request_id, voter_id) was dropped in
        // Version20260324082438, so the same upvoter can have multiple vote rows
        // for the same feature request. They must still receive exactly one email.
        $this->connection->executeStatement(
            'INSERT INTO feature_request_vote (id, feature_request_id, voter_id, voted_at) '
            . 'VALUES (:id, :featureRequestId, :voterId, NOW())',
            [
                'id' => Uuid::uuid7()->toString(),
                'featureRequestId' => FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
                'voterId' => PlayerFixture::PLAYER_ADMIN,
            ],
        );

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        // Author + 2 distinct upvoters (ADMIN deduped, REGULAR) = 3
        self::assertCount(3, $this->mailer->sent);

        $adminEmails = array_filter(
            $this->mailer->sent,
            fn(RawMessage $m): bool => $this->toAddresses($this->assertTemplatedEmail($m))[0] === 'admin@speedpuzzling.cz',
        );
        self::assertCount(1, $adminEmails, 'Upvoter with duplicate votes must receive exactly one email');
    }

    public function testUsesRecipientLocale(): void
    {
        // Author: Czech. Upvoter ADMIN: German. Upvoter REGULAR: unset → en fallback.
        $this->connection->executeStatement(
            'UPDATE player SET locale = :locale WHERE id = :id',
            ['locale' => 'cs', 'id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        $this->connection->executeStatement(
            'UPDATE player SET locale = :locale WHERE id = :id',
            ['locale' => 'de', 'id' => PlayerFixture::PLAYER_ADMIN],
        );
        $this->entityManager->clear();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        $localesByRecipient = [];
        foreach ($this->mailer->sent as $message) {
            $email = $this->assertTemplatedEmail($message);
            $localesByRecipient[$this->toAddresses($email)[0]] = $email->getLocale();
        }

        self::assertSame('cs', $localesByRecipient[PlayerFixture::PLAYER_WITH_STRIPE_EMAIL]);
        self::assertSame('de', $localesByRecipient['admin@speedpuzzling.cz']);
        self::assertSame('en', $localesByRecipient[PlayerFixture::PLAYER_REGULAR_EMAIL]);
    }

    public function testAdminCommentPresentInContextForBothTemplates(): void
    {
        $this->connection->executeStatement(
            'UPDATE feature_request SET admin_comment = :comment WHERE id = :id',
            [
                'comment' => 'Shipped this sprint.',
                'id' => FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            ],
        );
        $this->entityManager->clear();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        self::assertCount(3, $this->mailer->sent);
        foreach ($this->mailer->sent as $message) {
            $email = $this->assertTemplatedEmail($message);
            self::assertSame('Shipped this sprint.', $email->getContext()['adminComment']);
        }
    }

    public function testAdminCommentNullPassedToContext(): void
    {
        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        self::assertCount(3, $this->mailer->sent);
        foreach ($this->mailer->sent as $message) {
            $email = $this->assertTemplatedEmail($message);
            self::assertNull($email->getContext()['adminComment']);
        }
    }

    public function testGithubUrlPassedToContextOnCompleted(): void
    {
        $this->connection->executeStatement(
            'UPDATE feature_request SET github_url = :url WHERE id = :id',
            [
                'url' => 'https://github.com/example/repo/pull/42',
                'id' => FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            ],
        );
        $this->entityManager->clear();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        self::assertCount(3, $this->mailer->sent);
        foreach ($this->mailer->sent as $message) {
            $email = $this->assertTemplatedEmail($message);
            self::assertSame('https://github.com/example/repo/pull/42', $email->getContext()['githubUrl']);
        }
    }

    public function testGithubUrlNullPassedToContext(): void
    {
        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::InProgress,
            newStatus: FeatureRequestStatus::Completed,
        ));

        self::assertCount(3, $this->mailer->sent);
        foreach ($this->mailer->sent as $message) {
            $email = $this->assertTemplatedEmail($message);
            self::assertNull($email->getContext()['githubUrl']);
        }
    }

    /**
     * @param list<RawMessage> $messages
     * @return array<string, null|string> recipient email => html template
     */
    private function summarize(array $messages): array
    {
        $summary = [];
        foreach ($messages as $message) {
            $email = $this->assertTemplatedEmail($message);
            $summary[$this->toAddresses($email)[0]] = $email->getHtmlTemplate();
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function toAddresses(TemplatedEmail $email): array
    {
        return array_values(array_map(
            fn(Address $address): string => $address->getAddress(),
            $email->getTo(),
        ));
    }

    private function assertTemplatedEmail(RawMessage $message): TemplatedEmail
    {
        self::assertInstanceOf(TemplatedEmail::class, $message);

        return $message;
    }
}
