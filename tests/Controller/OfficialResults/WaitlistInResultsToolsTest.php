<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Query\GetLiveResultsEventPeople;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Results\SeatingProposalRow;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Services\SeatingProposer;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\SeatingSource;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * People waiting on a managed event's waitlist are no entries of the official results tools (review round 3, MINOR 3):
 * the one going rule (CompetitionParticipantGoing) - the entries, the progress counts, the seating proposal, the live
 * entry's "Already on the list?", the write path - and the desk + seating page say how many wait.
 */
final class WaitlistInResultsToolsTest extends WebTestCase
{
    public function testAWaitlistedPersonOfTheRoundIsNoEntryOfItsTools(): void
    {
        self::createClient();
        $this->waitlist(OfficialResultsFixture::PARTICIPANT_FILIP);

        $refs = array_map(
            static fn (RoundResultEntry $entry): string => $entry->ref->id,
            self::getContainer()->get(GetRoundResultEntries::class)->forRound(OfficialResultsFixture::ROUND_GROUP_A),
        );
        self::assertNotContains(OfficialResultsFixture::ENTRY_A_FILIP, $refs);
        self::assertCount(5, $refs);

        $overview = self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame(5, $overview->entriesTotal);
        self::assertSame(5, $overview->entriesWithTableNumber);
        self::assertTrue($overview->isSeated(), 'Filip, the only one without a table, waits on the waitlist');
        self::assertSame(1, $overview->peopleOnWaitlist);
        self::assertSame(0, self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_GROUP_B)->peopleOnWaitlist);

        $proposal = self::getContainer()->get(SeatingProposer::class)->propose(
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            OfficialResultsFixture::ROUND_GROUP_A,
            SeatingSource::Name,
            false,
            1,
            1,
            'en',
        );
        self::assertNotContains(
            'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP,
            array_map(static fn (SeatingProposalRow $row): string => $row->entryRef, $proposal->rows),
        );
        self::assertCount(5, $proposal->rows);
    }

    public function testAWaitlistedMemberOfAPairIsLeftOutOfItsPeople(): void
    {
        self::createClient();
        $this->waitlist(OfficialResultsFixture::PARTICIPANT_EVA);

        $unnamed = null;
        foreach (self::getContainer()->get(GetRoundResultEntries::class)->forRound(OfficialResultsFixture::ROUND_PAIRS) as $entry) {
            if ($entry->ref->id === OfficialResultsFixture::TEAM_UNNAMED) {
                $unnamed = $entry;
            }
        }

        self::assertNotNull($unnamed);
        self::assertSame(['Filip Pending'], array_map(static fn (RoundResultEntryMember $member): string => $member->name, $unnamed->members));
        self::assertSame(1, self::getContainer()->get(GetRoundResultsOverview::class)->forRound(OfficialResultsFixture::ROUND_PAIRS)->peopleOnWaitlist);
    }

    public function testTheWritePathTakesNoWaitlistedPerson(): void
    {
        self::createClient();
        $this->waitlist(OfficialResultsFixture::PARTICIPANT_FILIP);
        $this->waitlist(OfficialResultsFixture::PARTICIPANT_IVAN);

        $recorded = $this->record([
            // Filip's entry of Group A
            [
                'clientChangeId' => Uuid::uuid7()->toString(),
                'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP,
                'field' => 'result',
                'from' => null,
                'to' => ['seconds' => 4000],
            ],
            // Ivan (Group B) put into Group A from the event's people
            [
                'clientChangeId' => Uuid::uuid7()->toString(),
                'newEntry' => ['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'participantId' => OfficialResultsFixture::PARTICIPANT_IVAN],
                'field' => 'result',
                'from' => null,
                'to' => ['seconds' => 4100],
            ],
        ]);

        self::assertSame(
            ['entry_not_found', 'participant_waitlisted'],
            array_map(static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->reason, $recorded->outcomes),
        );
        self::assertNull($this->database()->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_FILIP]));

        // The live entry does not offer them either
        $people = array_column(
            self::getContainer()->get(GetLiveResultsEventPeople::class)->notInRound(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_A),
            'participantId',
        );
        self::assertNotContains(OfficialResultsFixture::PARTICIPANT_IVAN, $people);
        self::assertContains(OfficialResultsFixture::PARTICIPANT_GINA, $people);
    }

    public function testTheDeskAndTheSeatingPageSayHowManyWait(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        $this->assertSelectorNotExists('[data-round-waitlist-note]');

        $this->waitlist(OfficialResultsFixture::PARTICIPANT_FILIP);

        foreach (['/en/manage-round-results/', '/en/round-seating/'] as $page) {
            $crawler = $browser->request('GET', $page . OfficialResultsFixture::ROUND_GROUP_A);

            self::assertResponseIsSuccessful();
            $note = $crawler->filter('[data-round-waitlist-note]');
            self::assertCount(1, $note, $page);
            self::assertStringContainsString('One person on the waitlist is in this round - give them a spot to include them.', $note->text());
            // The People tab of the participants sheet, filtered to the waitlist (BR17)
            self::assertSame('/en/participants-sheet/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '?tab=people&filter=waitlist', $note->filter('a')->attr('href'));
        }
    }

    private function waitlist(string $participantId): void
    {
        $this->database()->executeStatement(
            "UPDATE competition_participant SET registration_status = 'waitlisted' WHERE id = :id",
            ['id' => $participantId],
        );
    }

    /**
     * @param list<array<string, mixed>> $changes
     */
    private function record(array $changes): RecordedRoundResults
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse($changes),
            dryRun: false,
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(RecordedRoundResults::class, $recorded);

        return $recorded;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
