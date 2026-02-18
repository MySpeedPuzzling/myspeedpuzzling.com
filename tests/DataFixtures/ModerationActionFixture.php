<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ConversationReport;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\ModerationActionType;

final class ModerationActionFixture extends Fixture implements DependentFixtureInterface
{
    public const string ACTION_WARNING = '018d0010-0000-0000-0000-000000000001';
    public const string ACTION_EXPIRED_MUTE = '018d0010-0000-0000-0000-000000000002';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $resolvedReport = $this->getReference(ConversationReportFixture::REPORT_RESOLVED, ConversationReport::class);

        $now = $this->clock->now();

        // Warning action
        $warningAction = new ModerationAction(
            id: Uuid::fromString(self::ACTION_WARNING),
            targetPlayer: $playerRegular,
            admin: $playerAdmin,
            actionType: ModerationActionType::Warning,
            performedAt: $now->modify('-3 days'),
            report: $resolvedReport,
            reason: 'First warning for inappropriate behavior',
        );
        $manager->persist($warningAction);
        $this->addReference(self::ACTION_WARNING, $warningAction);

        // Expired mute action (already expired)
        $playerWithFavorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $expiredMuteAction = new ModerationAction(
            id: Uuid::fromString(self::ACTION_EXPIRED_MUTE),
            targetPlayer: $playerWithFavorites,
            admin: $playerAdmin,
            actionType: ModerationActionType::TemporaryMute,
            performedAt: $now->modify('-14 days'),
            reason: 'Temporary mute for spam',
            expiresAt: $now->modify('-7 days'),
        );
        $manager->persist($expiredMuteAction);
        $this->addReference(self::ACTION_EXPIRED_MUTE, $expiredMuteAction);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            ConversationReportFixture::class,
        ];
    }
}
