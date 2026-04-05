<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditCompetitionParticipantHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUpdatesParticipantFields(): void
    {
        $this->messageBus->dispatch(new EditCompetitionParticipant(
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            name: 'Updated Name',
            country: 'de',
            externalId: 'EXT-UPDATED',
            playerId: null,
            roundIds: [],
        ));

        $this->entityManager->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);

        self::assertSame('Updated Name', $participant->name);
        self::assertSame('de', $participant->country);
        self::assertSame('EXT-UPDATED', $participant->externalId);
    }

    public function testConnectsPlayer(): void
    {
        $this->messageBus->dispatch(new EditCompetitionParticipant(
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            name: 'Jane Unconnected',
            country: 'us',
            externalId: null,
            playerId: PlayerFixture::PLAYER_ADMIN,
            roundIds: [],
        ));

        $this->entityManager->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);

        self::assertNotNull($participant->player);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $participant->player->id->toString());
    }

    public function testSyncsRoundAssignments(): void
    {
        // PARTICIPANT_UNCONNECTED currently has ROUND_WJPC_QUALIFICATION
        // Change to only ROUND_WJPC_FINAL
        $this->messageBus->dispatch(new EditCompetitionParticipant(
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
            name: 'Jane Unconnected',
            country: 'us',
            externalId: null,
            playerId: null,
            roundIds: [CompetitionRoundFixture::ROUND_WJPC_FINAL],
        ));

        $this->entityManager->clear();

        /** @var array<CompetitionParticipantRound> $rounds */
        $rounds = $this->entityManager
            ->getRepository(CompetitionParticipantRound::class)
            ->findBy(['participant' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]);

        self::assertCount(1, $rounds);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_FINAL, $rounds[0]->round->id->toString());
    }
}
