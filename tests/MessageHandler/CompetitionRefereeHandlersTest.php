<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\AddCompetitionReferee;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\RemoveCompetitionReferee;
use SpeedPuzzling\Web\Query\GetCompetitionReferees;
use SpeedPuzzling\Web\Results\CompetitionRefereeListItem;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CompetitionRefereeAddition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * AddCompetitionReferee / RemoveCompetitionReferee (docs/features/competitions-management/live-results.md "Referees").
 * The Results Cup is organised by PLAYER_WITH_STRIPE.
 */
final class CompetitionRefereeHandlersTest extends KernelTestCase
{
    private const string COMPETITION = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testAPlayerIsAddedOnce(): void
    {
        self::assertSame(CompetitionRefereeAddition::Added, $this->add(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertSame(CompetitionRefereeAddition::AlreadyReferee, $this->add(PlayerFixture::PLAYER_WITH_FAVORITES));

        $referees = $this->referees();
        self::assertCount(1, $referees);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $referees[0]->playerId);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $referees[0]->addedByName);
    }

    public function testAnOrganiserIsNeverStoredAsAReferee(): void
    {
        // The creator
        self::assertSame(CompetitionRefereeAddition::Organiser, $this->add(self::ORGANISER));

        // A maintainer
        self::getContainer()->get(Connection::class)->insert('competition_maintainer', [
            'competition_id' => self::COMPETITION,
            'player_id' => PlayerFixture::PLAYER_REGULAR,
        ]);
        self::assertSame(CompetitionRefereeAddition::Organiser, $this->add(PlayerFixture::PLAYER_REGULAR));

        // A series maintainer, at an edition of their series
        self::getContainer()->get(Connection::class)->insert('competition_series_maintainer', [
            'competition_series_id' => CompetitionSeriesFixture::SERIES_OFFLINE,
            'player_id' => PlayerFixture::PLAYER_PRIVATE,
        ]);
        self::assertSame(
            CompetitionRefereeAddition::Organiser,
            $this->add(PlayerFixture::PLAYER_PRIVATE, CompetitionSeriesFixture::EDITION_OFFLINE_1),
        );

        self::assertSame([], $this->referees());
    }

    public function testAnUnknownPlayerIsNotAdded(): void
    {
        self::assertSame(CompetitionRefereeAddition::UnknownPlayer, $this->add('018d0099-0000-0000-0000-00000000dead'));
        self::assertSame([], $this->referees());
    }

    public function testRemovingTakesTheRightsAwayAndRemovingNobodyDoesNothing(): void
    {
        $this->add(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->add(PlayerFixture::PLAYER_PRIVATE);

        $this->messageBus->dispatch(new RemoveCompetitionReferee(self::COMPETITION, PlayerFixture::PLAYER_WITH_FAVORITES));
        $this->messageBus->dispatch(new RemoveCompetitionReferee(self::COMPETITION, PlayerFixture::PLAYER_REGULAR));
        $this->messageBus->dispatch(new RemoveCompetitionReferee(self::COMPETITION, '018d0099-0000-0000-0000-00000000dead'));

        self::assertSame(
            [PlayerFixture::PLAYER_PRIVATE],
            array_map(static fn (CompetitionRefereeListItem $referee): string => $referee->playerId, $this->referees()),
        );
    }

    public function testDeletingTheRefereesAccountRemovesTheirRow(): void
    {
        $this->add(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_WITH_FAVORITES));

        self::assertSame([], $this->referees());
    }

    private function add(string $playerId, string $competitionId = self::COMPETITION): CompetitionRefereeAddition
    {
        $envelope = $this->messageBus->dispatch(new AddCompetitionReferee($competitionId, $playerId, self::ORGANISER));
        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof CompetitionRefereeAddition);

        return $outcome;
    }

    /**
     * @return list<CompetitionRefereeListItem>
     */
    private function referees(): array
    {
        return self::getContainer()->get(GetCompetitionReferees::class)->ofCompetition(self::COMPETITION);
    }
}
