<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\StartEventWizardDraft;
use SpeedPuzzling\Web\Query\GetEventWizardState;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Two submits of the same new-event page at once (EventWizardBasicsController): the second StartEventWizardDraft with the
 * page's id meets the first one's row, Doctrine closes the entity manager - and the controller's check of "is it this
 * player's draft?" still answers, through DBAL (GetEventWizardState), so the second submit is sent to the draft.
 */
final class StartEventWizardDraftTwiceTest extends KernelTestCase
{
    public function testTheSecondInsertOfTheSameIdFailsAndTheDraftIsStillReadable(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $competitionId = Uuid::uuid7()->toString();

        $bus->dispatch(self::message($competitionId));
        // The second submit is another request: its entity manager knows nothing of the first one's row
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        try {
            $bus->dispatch(self::message($competitionId));
            self::fail('The same id twice must be refused');
        } catch (\Throwable $failure) {
            self::assertNotNull(EventWizardRefusal::find($failure, UniqueConstraintViolationException::class), (string) $failure);
        }

        self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->isOpen(), 'Doctrine closes the entity manager after a failed flush');

        $state = self::getContainer()->get(GetEventWizardState::class)->of($competitionId);
        self::assertTrue($state->isDraft);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $state->addedByPlayerId);
        self::assertSame(1, $state->roundsCount);
    }

    private static function message(string $competitionId): StartEventWizardDraft
    {
        return new StartEventWizardDraft(
            competition: new AddCompetition(
                competitionId: Uuid::fromString($competitionId),
                playerId: PlayerFixture::PLAYER_REGULAR,
                name: 'Twice Cup',
                shortcut: null,
                description: null,
                link: null,
                registrationLink: null,
                resultsLink: null,
                location: 'Brno',
                locationCountryCode: 'cz',
                dateFrom: new DateTimeImmutable('2027-06-15'),
                dateTo: new DateTimeImmutable('2027-06-15'),
                isOnline: false,
                logo: null,
                maintainerIds: [],
                isDraft: true,
            ),
            round: new AddCompetitionRound(
                roundId: Uuid::uuid7(),
                competitionId: $competitionId,
                name: 'Solo',
                minutesLimit: 90,
                startsAt: new DateTimeImmutable('2027-06-15 08:00:00'),
                timezone: 'Europe/Prague',
                badgeBackgroundColor: null,
                badgeTextColor: null,
            ),
        );
    }
}
