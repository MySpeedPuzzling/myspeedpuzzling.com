<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetCompetitionParticipantsPrivacyTest extends KernelTestCase
{
    private GetCompetitionParticipants $getCompetitionParticipants;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getCompetitionParticipants = self::getContainer()->get(GetCompetitionParticipants::class);
    }

    public function testPrivatePlayerHasIsPrivateFlag(): void
    {
        $participants = $this->getCompetitionParticipants->getConnectedParticipants(
            CompetitionFixture::COMPETITION_WJPC_2024,
        );

        $privateParticipant = null;
        $regularParticipant = null;

        foreach ($participants as $participant) {
            if ($participant->participantId === CompetitionParticipantFixture::PARTICIPANT_PRIVATE) {
                $privateParticipant = $participant;
            }
            if ($participant->participantId === CompetitionParticipantFixture::PARTICIPANT_CONNECTED) {
                $regularParticipant = $participant;
            }
        }

        self::assertNotNull($privateParticipant, 'Private participant should be in results');
        self::assertTrue($privateParticipant->isPrivate, 'Private player should have isPrivate=true');

        self::assertNotNull($regularParticipant, 'Regular participant should be in results');
        self::assertFalse($regularParticipant->isPrivate, 'Regular player should have isPrivate=false');
    }

    public function testSoftDeletedParticipantsExcludedFromPublicQueries(): void
    {
        $connected = $this->getCompetitionParticipants->getConnectedParticipants(
            CompetitionFixture::COMPETITION_WJPC_2024,
        );

        $notConnected = $this->getCompetitionParticipants->getNotConnectedParticipants(
            CompetitionFixture::COMPETITION_WJPC_2024,
        );

        $allIds = array_merge(
            array_map(fn ($p) => $p->participantId, $connected),
            array_map(fn ($p) => $p->id, $notConnected),
        );

        self::assertNotContains(
            CompetitionParticipantFixture::PARTICIPANT_DELETED,
            $allIds,
            'Soft-deleted participant should not appear in public queries',
        );
    }

    public function testSoftDeletedParticipantExcludedFromPairingMapping(): void
    {
        $mapping = $this->getCompetitionParticipants->mappingForPairing(
            CompetitionFixture::COMPETITION_WJPC_2024,
        );

        self::assertNotContains(
            CompetitionParticipantFixture::PARTICIPANT_DELETED,
            $mapping,
            'Soft-deleted participant should not appear in pairing mapping',
        );
    }
}
