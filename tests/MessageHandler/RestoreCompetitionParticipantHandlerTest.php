<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\RestoreCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RestoreCompetitionParticipantHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
    }

    public function testRestoreClearsDeletedAt(): void
    {
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_DELETED);
        self::assertTrue($participant->isDeleted());

        $this->messageBus->dispatch(new RestoreCompetitionParticipant(
            participantId: CompetitionParticipantFixture::PARTICIPANT_DELETED,
        ));

        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_DELETED);

        self::assertFalse($participant->isDeleted());
    }
}
