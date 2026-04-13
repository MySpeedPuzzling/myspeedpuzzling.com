<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\FeatureRequestStatusChanged;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenFeatureRequestStatusChanged;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotifyWhenFeatureRequestStatusChangedTest extends KernelTestCase
{
    private NotifyWhenFeatureRequestStatusChanged $handler;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(NotifyWhenFeatureRequestStatusChanged::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testSendsEmailWhenStatusChanges(): void
    {
        $this->expectNotToPerformAssertions();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_NEW),
            oldStatus: FeatureRequestStatus::Open,
            newStatus: FeatureRequestStatus::InProgress,
        ));
    }

    public function testHandlesAllStatusTransitions(): void
    {
        $this->expectNotToPerformAssertions();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_NEW),
            oldStatus: FeatureRequestStatus::Open,
            newStatus: FeatureRequestStatus::Completed,
        ));

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
            oldStatus: FeatureRequestStatus::Open,
            newStatus: FeatureRequestStatus::Declined,
        ));
    }

    public function testSkipsWhenPlayerHasNoEmail(): void
    {
        $this->expectNotToPerformAssertions();

        $this->connection->executeStatement(
            'UPDATE player SET email = NULL WHERE id = (SELECT author_id FROM feature_request WHERE id = :id)',
            ['id' => FeatureRequestFixture::FEATURE_REQUEST_NEW],
        );
        $this->entityManager->clear();

        ($this->handler)(new FeatureRequestStatusChanged(
            featureRequestId: Uuid::fromString(FeatureRequestFixture::FEATURE_REQUEST_NEW),
            oldStatus: FeatureRequestStatus::Open,
            newStatus: FeatureRequestStatus::InProgress,
        ));
    }
}
