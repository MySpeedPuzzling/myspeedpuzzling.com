<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\AfterResponse;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionStandingsLiveUpdates;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Update;

/**
 * The after-commit hook of the public standings (competition toolkit v2 S1, displays-standings.md §2.2), reached through
 * CompetitionDisplayLiveUpdates (the choke point every results / round write calls - forwarded first, also for an event
 * without displays): which writes start a new generation of the guest snapshots, and that an event without published
 * standings pays no statement once its answer is cached. v2 S4: the public, data-free signal after the new generation -
 * once the answer went out (AfterResponse), once per event and request, a hub failure only a warning.
 *
 * Results Cup: Group A published, Group B not; one published standing over both.
 */
final class CompetitionStandingsLiveUpdatesTest extends KernelTestCase
{
    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private Connection $database;
    private CompetitionDisplayLiveUpdates $hook;
    private PublicStandingSnapshots $snapshots;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->hook = self::getContainer()->get(CompetitionDisplayLiveUpdates::class);
        $this->snapshots = self::getContainer()->get(PublicStandingSnapshots::class);
    }

    public function testResultsOfAnUnpublishedRoundBumpNothing(): void
    {
        PublishedStanding::insert($this->database, self::CUP, 'overall-solo', 'Overall solo', 'solo', [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B]);

        // Live entry of a running, unpublished round: the public view does not count it
        $generation = $this->snapshots->generation(self::CUP);
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertSame($generation, $this->snapshots->generation(self::CUP));

        // A published round of a published standing: a new generation
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
        $generation = $this->snapshots->generation(self::CUP);

        // Publishing Group B (the round itself changed): its cached "not published" is forgotten first
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]);
        $this->hook->roundChanged(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
        $generation = $this->snapshots->generation(self::CUP);
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
        $generation = $this->snapshots->generation(self::CUP);

        // Event-wide writes (participants sheet, rounds added / moved)
        $this->hook->resultsChangedInCompetition(self::CUP);
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
        $generation = $this->snapshots->generation(self::CUP);
        $this->hook->roundsChanged(self::CUP);
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
    }

    public function testAnUnpublishedStandingGatesEverythingButStandingWrites(): void
    {
        PublishedStanding::insert($this->database, self::CUP, 'overall-solo', 'Overall solo', 'solo', [OfficialResultsFixture::ROUND_GROUP_A], published: false);
        $generation = $this->snapshots->generation(self::CUP);

        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);
        $this->hook->roundChanged(OfficialResultsFixture::ROUND_GROUP_A);
        $this->hook->resultsChangedInCompetition(self::CUP);
        self::assertSame($generation, $this->snapshots->generation(self::CUP));

        // A standing written (here: published) - always a new generation, and the presence is read again
        $this->database->executeStatement('UPDATE competition_standing SET published_at = ' . TestClock::SQL_NOW . ' WHERE competition_id = :id', ['id' => self::CUP]);
        $this->hook->standingsChanged(self::CUP);
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
        $generation = $this->snapshots->generation(self::CUP);
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
    }

    /**
     * Rule 1 of the toolkit: an event without published standings (nearly every event) - a results write runs no
     * statement for standings once the answer is cached (CompetitionStandingsPresence), like the displays' presence.
     */
    public function testAnUntouchedEventCostsNoStatementWarm(): void
    {
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $generation = $this->snapshots->generation(self::CUP);
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);
        $this->hook->roundChanged(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame($generation, $this->snapshots->generation(self::CUP));

        // roundChanged() forgets the round's answer (its publication may have flipped) - one statement, no more
        $statements = $holder->getData()['default'] ?? [];
        self::assertIsArray($statements);
        self::assertLessThanOrEqual(1, count($statements));
        $holder->reset();
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame([], $holder->getData()['default'] ?? []);
    }

    public function testTheBumpSendsOnePublicDataFreeSignalAfterTheResponse(): void
    {
        PublishedStanding::insert($this->database, self::CUP, 'overall-solo', 'Overall solo', 'solo', [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B]);
        $hub = self::hub();

        // Gated out (an unpublished round): no signal
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_B);
        self::afterResponse()->run();
        self::assertSame([], self::standingsUpdates($hub));

        // Three writes of one request: nothing before the response, one signal after it
        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);
        $this->hook->roundChanged(OfficialResultsFixture::ROUND_GROUP_A);
        $this->hook->resultsChangedInCompetition(self::CUP);
        self::assertSame([], self::standingsUpdates($hub), 'Signalled before the response went out');
        self::afterResponse()->run();

        $updates = self::standingsUpdates($hub);
        self::assertCount(1, $updates);
        self::assertSame([CompetitionStandingsLiveUpdates::topic(self::CUP)], $updates[0]->getTopics());
        self::assertSame('/competition-standings/' . strtolower(self::CUP), $updates[0]->getTopics()[0]);
        self::assertFalse($updates[0]->isPrivate());
        // No data: no standing, no round, no name - a listener fetches what it shows through the public rules
        self::assertSame('{"type":"standing.signal"}', $updates[0]->getData());

        // The next request signals again
        $this->hook->standingsChanged(self::CUP);
        self::afterResponse()->run();
        self::assertCount(2, self::standingsUpdates($hub));
    }

    public function testAHubFailureIsAWarningOnly(): void
    {
        PublishedStanding::insert($this->database, self::CUP, 'overall-solo', 'Overall solo', 'solo', [OfficialResultsFixture::ROUND_GROUP_A]);
        $hub = self::hub();
        $hub->failWith(new \RuntimeException('The hub is down'));
        $generation = $this->snapshots->generation(self::CUP);

        $this->hook->resultsChanged(OfficialResultsFixture::ROUND_GROUP_A);
        self::afterResponse()->run();

        // The new generation stands; nothing thrown
        self::assertNotSame($generation, $this->snapshots->generation(self::CUP));
        $hub->failWith(null);
        self::assertSame([], self::standingsUpdates($hub));
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }

    private static function afterResponse(): AfterResponse
    {
        return self::getContainer()->get(AfterResponse::class);
    }

    /**
     * @phpstan-impure
     *
     * @return list<Update>
     */
    private static function standingsUpdates(NullMercureHub $hub): array
    {
        return array_values(array_filter(
            $hub->getPublishedUpdates(),
            static function (Update $update): bool {
                $topic = $update->getTopics()[0] ?? null;

                return is_string($topic) && str_starts_with($topic, '/competition-standings/');
            },
        ));
    }
}
