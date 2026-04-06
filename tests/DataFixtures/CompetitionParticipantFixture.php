<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\ParticipantSource;

final class CompetitionParticipantFixture extends Fixture implements DependentFixtureInterface
{
    public const string PARTICIPANT_CONNECTED = '018d0006-0000-0000-0000-000000000001';
    public const string PARTICIPANT_UNCONNECTED = '018d0006-0000-0000-0000-000000000002';
    public const string PARTICIPANT_PRIVATE = '018d0006-0000-0000-0000-000000000003';
    public const string PARTICIPANT_SELF_JOINED = '018d0006-0000-0000-0000-000000000004';
    public const string PARTICIPANT_DELETED = '018d0006-0000-0000-0000-000000000005';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $wjpcCompetition = $this->getReference(CompetitionFixture::COMPETITION_WJPC_2024, Competition::class);
        $regularPlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $privatePlayer = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);
        $favoritesPlayer = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $qualificationRound = $this->getReference(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRound::class);
        $finalRound = $this->getReference(CompetitionRoundFixture::ROUND_WJPC_FINAL, CompetitionRound::class);

        // Connected participant (imported, linked to PLAYER_REGULAR)
        $connected = new CompetitionParticipant(
            id: Uuid::fromString(self::PARTICIPANT_CONNECTED),
            name: 'John Regular',
            country: 'cz',
            competition: $wjpcCompetition,
            source: ParticipantSource::Imported,
        );
        $connected->connect($regularPlayer, $this->clock->now());
        $connected->updateExternalId('EXT-001');
        $manager->persist($connected);
        $this->addReference(self::PARTICIPANT_CONNECTED, $connected);

        // Unconnected participant (imported, no player link)
        $unconnected = new CompetitionParticipant(
            id: Uuid::fromString(self::PARTICIPANT_UNCONNECTED),
            name: 'Jane Unconnected',
            country: 'us',
            competition: $wjpcCompetition,
            source: ParticipantSource::Imported,
        );
        $manager->persist($unconnected);
        $this->addReference(self::PARTICIPANT_UNCONNECTED, $unconnected);

        // Private player participant (imported, linked to PLAYER_PRIVATE)
        $private = new CompetitionParticipant(
            id: Uuid::fromString(self::PARTICIPANT_PRIVATE),
            name: 'Secret Player',
            country: 'de',
            competition: $wjpcCompetition,
            source: ParticipantSource::Imported,
        );
        $private->connect($privatePlayer, $this->clock->now());
        $manager->persist($private);
        $this->addReference(self::PARTICIPANT_PRIVATE, $private);

        // Self-joined participant (linked to PLAYER_WITH_FAVORITES)
        $selfJoined = new CompetitionParticipant(
            id: Uuid::fromString(self::PARTICIPANT_SELF_JOINED),
            name: 'Michael Johnson',
            country: 'us',
            competition: $wjpcCompetition,
            source: ParticipantSource::SelfJoined,
        );
        $selfJoined->connect($favoritesPlayer, $this->clock->now());
        $manager->persist($selfJoined);
        $this->addReference(self::PARTICIPANT_SELF_JOINED, $selfJoined);

        // Soft-deleted participant
        $deleted = new CompetitionParticipant(
            id: Uuid::fromString(self::PARTICIPANT_DELETED),
            name: 'Deleted Person',
            country: 'gb',
            competition: $wjpcCompetition,
            source: ParticipantSource::Imported,
        );
        $deleted->softDelete($this->clock->now());
        $manager->persist($deleted);
        $this->addReference(self::PARTICIPANT_DELETED, $deleted);

        // Round assignments
        $manager->persist(new CompetitionParticipantRound(
            id: Uuid::uuid7(),
            participant: $connected,
            round: $qualificationRound,
        ));
        $manager->persist(new CompetitionParticipantRound(
            id: Uuid::uuid7(),
            participant: $connected,
            round: $finalRound,
        ));
        $manager->persist(new CompetitionParticipantRound(
            id: Uuid::uuid7(),
            participant: $unconnected,
            round: $qualificationRound,
        ));
        $manager->persist(new CompetitionParticipantRound(
            id: Uuid::uuid7(),
            participant: $private,
            round: $qualificationRound,
        ));

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompetitionFixture::class,
            PlayerFixture::class,
            CompetitionRoundFixture::class,
        ];
    }
}
