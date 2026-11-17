<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplaysPresence;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The display signals (competition toolkit D5, D-displays-overlays.md §7.3, R-review-and-plan.md §1.6): an event without
 * displays gets nothing and - once its answer is cached - pays no statement for asking; a results signal needs a display
 * showing results; the signal carries no data; a hub failure never reaches the write.
 */
final class CompetitionDisplayLiveUpdatesTest extends WebTestCase
{
    use CreatesCompetitionDisplays;

    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ROUND = OfficialResultsFixture::ROUND_GROUP_A;

    public function testAnEventWithoutDisplaysGetsNothingAndPaysNoStatementOnceItsAnswerIsCached(): void
    {
        self::bootKernel();
        $signals = self::getContainer()->get(CompetitionDisplayLiveUpdates::class);

        // The first ask of the round reads it once (one statement), the request's other writes ride on it
        $statements = self::statementsOf(static function () use ($signals): void {
            $signals->resultsChanged(self::ROUND);
            $signals->puzzlesChanged(self::ROUND);
            $signals->roundChanged(self::ROUND);
        });
        // ... plus one for the standings (v2 S1: does a published standing count this round?) - forwarded from here
        self::assertCount(2, $statements);
        self::assertCount(1, array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'competition_display')));

        // The next request (the object's own memory gone - worker mode resets it): the shared cache answers
        self::getContainer()->get(CompetitionDisplaysPresence::class)->reset();
        $statements = self::statementsOf(static function () use ($signals): void {
            $signals->resultsChanged(self::ROUND);
            $signals->roundsChanged(self::CUP);
        });
        // ... only the event itself, asked for the first time, is read (displays + standings, once each)
        self::assertCount(2, $statements);
        foreach ($statements as $sql) {
            self::assertStringNotContainsString('competition_round', $sql);
        }

        self::getContainer()->get(CompetitionDisplaysPresence::class)->reset();
        self::assertSame([], self::statementsOf(static function () use ($signals): void {
            $signals->resultsChanged(self::ROUND);
            $signals->puzzlesChanged(self::ROUND);
            $signals->roundChanged(self::ROUND);
            $signals->roundsChanged(self::CUP);
        }));

        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    public function testAResultsSignalNeedsADisplayShowingResults(): void
    {
        self::bootKernel();
        self::createDisplay(self::CUP, [['type' => 'timer']]);
        $signals = self::getContainer()->get(CompetitionDisplayLiveUpdates::class);

        $signals->resultsChanged(self::ROUND);
        self::assertSame([], self::hub()->getPublishedUpdates());

        $signals->roundChanged(self::ROUND);
        $signals->puzzlesChanged(self::ROUND);
        $signals->roundsChanged(self::CUP);
        self::assertSame([
            ['type' => 'display.signal', 'what' => 'round', 'roundId' => self::ROUND],
            ['type' => 'display.signal', 'what' => 'puzzles', 'roundId' => self::ROUND],
            ['type' => 'display.signal', 'what' => 'round'],
        ], self::signals());

        // A display showing results added - the controller forgets the event's answer after the commit
        self::createDisplay(self::CUP, [['type' => 'leaderboard']]);
        self::getContainer()->get(CompetitionDisplaysPresence::class)->changed(self::CUP);
        self::hub()->reset();

        $signals->resultsChanged(self::ROUND);
        $signals->resultsChangedInCompetition(self::CUP);
        self::assertSame([
            ['type' => 'display.signal', 'what' => 'results', 'roundId' => self::ROUND],
            ['type' => 'display.signal', 'what' => 'results'],
        ], self::signals());
    }

    public function testTheSignalGoesPrivatelyToTheEventsDisplayTopicOnly(): void
    {
        self::bootKernel();
        self::createDisplay(self::CUP, [['type' => 'leaderboard']]);
        $signals = self::getContainer()->get(CompetitionDisplayLiveUpdates::class);

        $signals->resultsChanged(self::ROUND);
        // Another event's round and another event: no displays there
        $signals->resultsChanged(CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $signals->roundsChanged(CompetitionFixture::COMPETITION_WJPC_2024);
        // An unknown round or a malformed id: nothing, no failure
        $signals->roundChanged('018d0020-0000-0000-0000-00000000dead');
        $signals->roundChanged('not-a-uuid');

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame(['/competition-display/' . self::CUP], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
    }

    public function testADisplayChangeIsAlwaysSignalled(): void
    {
        self::bootKernel();
        $signals = self::getContainer()->get(CompetitionDisplayLiveUpdates::class);

        // A deleted display leaves no display to ask about - its open page must still hear it
        $signals->displayChanged(strtoupper(self::CUP), '018D0020-0000-0000-0000-0000000000AA');

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame(['/competition-display/' . self::CUP], $updates[0]->getTopics());
        self::assertSame([['type' => 'display.signal', 'what' => 'design', 'displayId' => '018d0020-0000-0000-0000-0000000000aa']], self::signals());
    }

    public function testAHubThatIsDownNeverFailsTheWrite(): void
    {
        self::bootKernel();
        self::createDisplay(self::CUP, [['type' => 'leaderboard']]);
        self::hub()->failWith(new \RuntimeException('Hub down'));
        $signals = self::getContainer()->get(CompetitionDisplayLiveUpdates::class);

        $signals->resultsChanged(self::ROUND);
        $signals->displayChanged(self::CUP, '018d0020-0000-0000-0000-0000000000aa');

        self::hub()->failWith(null);
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    /**
     * Signals go out after the commit, from controllers - a handler that signalled would tell the displays about a write
     * its transaction may still roll back.
     */
    public function testNoHandlerSignalsTheDisplays(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src/MessageHandler', \FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof \SplFileInfo);
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^use [^;]*\\\\(CompetitionDisplayLiveUpdates|CompetitionDisplaysPresence|OfficialResultsLiveUpdates);/m', $source) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * @return list<string> the SQL the callback ran
     */
    private static function statementsOf(\Closure $callback): array
    {
        /** @var DebugDataHolder $debugData */
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $callback();

        $statements = [];

        foreach ($debugData->getData() as $queries) {
            if (!is_array($queries)) {
                continue;
            }

            foreach ($queries as $query) {
                if (is_array($query) && is_string($query['sql'] ?? null)) {
                    $statements[] = $query['sql'];
                }
            }
        }

        return $statements;
    }

    /**
     * @return list<mixed> the decoded payloads published so far
     */
    private static function signals(): array
    {
        return array_map(
            static fn ($update): mixed => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR),
            array_values(self::hub()->getPublishedUpdates()),
        );
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
