<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetCompetitionParticipantsTest extends KernelTestCase
{
    private GetCompetitionParticipants $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCompetitionParticipants::class);
    }

    public function testNotConnectedParticipantsAreOnlyUnclaimedActiveOnes(): void
    {
        $participants = $this->query->getNotConnectedParticipants(CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertSame(
            [CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
            array_map(static fn ($participant): string => $participant->id, $participants),
        );
    }

    public function testHasNotConnectedParticipants(): void
    {
        self::assertTrue($this->query->hasNotConnectedParticipants(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertFalse($this->query->hasNotConnectedParticipants(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));
    }

    public function testMatchingNameIgnoresCaseAccentsAndWhitespace(): void
    {
        // PARTICIPANT_UNCONNECTED is 'Jane Unconnected' from 'us'
        self::assertSame(
            CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            $this->query->findNotConnectedParticipantMatchingName(CompetitionFixture::COMPETITION_WJPC_2024, '  jáne   UNCONNECTED ', 'us'),
        );

        self::assertSame(
            CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            $this->query->findNotConnectedParticipantMatchingName(CompetitionFixture::COMPETITION_WJPC_2024, 'Jane Unconnected', null),
        );
    }

    public function testMatchingNameRejectsPartialNamesOtherCountriesAndClaimedParticipants(): void
    {
        $competitionId = CompetitionFixture::COMPETITION_WJPC_2024;

        self::assertNull($this->query->findNotConnectedParticipantMatchingName($competitionId, 'Jane', 'us'));
        self::assertNull($this->query->findNotConnectedParticipantMatchingName($competitionId, 'Jane Unconnected', 'cz'));
        self::assertNull($this->query->findNotConnectedParticipantMatchingName($competitionId, '', null));
        // 'John Regular' is already connected, 'Deleted Person' is soft-deleted
        self::assertNull($this->query->findNotConnectedParticipantMatchingName($competitionId, 'John Regular', 'cz'));
        self::assertNull($this->query->findNotConnectedParticipantMatchingName($competitionId, 'Deleted Person', 'gb'));
    }

    public function testIsPlayerSelfJoined(): void
    {
        $competitionId = CompetitionFixture::COMPETITION_WJPC_2024;

        self::assertTrue($this->query->isPlayerSelfJoined($competitionId, PlayerFixture::PLAYER_WITH_FAVORITES));
        // Connected to an imported row, not self-joined
        self::assertFalse($this->query->isPlayerSelfJoined($competitionId, PlayerFixture::PLAYER_REGULAR));
        self::assertFalse($this->query->isPlayerSelfJoined($competitionId, PlayerFixture::PLAYER_ADMIN));
    }
}
