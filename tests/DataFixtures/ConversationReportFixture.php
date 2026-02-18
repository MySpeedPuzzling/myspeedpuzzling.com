<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\ConversationReport;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\ReportStatus;

final class ConversationReportFixture extends Fixture implements DependentFixtureInterface
{
    public const string REPORT_PENDING = '018d000f-0000-0000-0000-000000000001';
    public const string REPORT_RESOLVED = '018d000f-0000-0000-0000-000000000002';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $conversationAccepted = $this->getReference(ConversationFixture::CONVERSATION_ACCEPTED, Conversation::class);
        $conversationMarketplace = $this->getReference(ConversationFixture::CONVERSATION_MARKETPLACE, Conversation::class);

        $now = $this->clock->now();

        // Pending report: REGULAR reports conversation with ADMIN
        $pendingReport = new ConversationReport(
            id: Uuid::fromString(self::REPORT_PENDING),
            conversation: $conversationAccepted,
            reporter: $playerRegular,
            reason: 'Inappropriate messages',
            status: ReportStatus::Pending,
            reportedAt: $now->modify('-2 hours'),
        );
        $manager->persist($pendingReport);
        $this->addReference(self::REPORT_PENDING, $pendingReport);

        // Resolved report on marketplace conversation
        $playerWithFavorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $resolvedReport = new ConversationReport(
            id: Uuid::fromString(self::REPORT_RESOLVED),
            conversation: $conversationMarketplace,
            reporter: $playerWithFavorites,
            reason: 'Spam messages',
            status: ReportStatus::Resolved,
            reportedAt: $now->modify('-5 days'),
        );
        $resolvedReport->resolve($playerAdmin, ReportStatus::Resolved, 'Warning issued');
        $manager->persist($resolvedReport);
        $this->addReference(self::REPORT_RESOLVED, $resolvedReport);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            ConversationFixture::class,
        ];
    }
}
