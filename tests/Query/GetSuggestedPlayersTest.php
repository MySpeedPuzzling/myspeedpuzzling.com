<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetSuggestedPlayers;
use SpeedPuzzling\Web\Results\SuggestedPlayer;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\SuggestionReason;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * "Suggested for you" (docs/features/players-page/README.md, stream S6). Seeded players only, so the fixtures'
 * own people never decide an assertion; the lists are asked for in full (a high limit) unless the order is the point.
 */
final class GetSuggestedPlayersTest extends KernelTestCase
{
    use ComparisonSeeding;

    private const int EVERYBODY = 500;

    // Far from every fixture time, so the fixtures' players never count as a similar time
    private const int VIEWER_BEST_500 = 20000;

    private GetSuggestedPlayers $query;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetSuggestedPlayers::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testSomebodyWhoPuzzlesWithYourCoPuzzlerIsSuggestedWithTheirName(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $coPuzzler = $this->seedPlayer('Petra Pairs');
        $candidate = $this->seedPlayer('Cecilie Candidate');
        $notPuzzledYet = $this->seedPlayer('Only Prepared');
        $puzzle = $this->seedPuzzle(500);

        $this->seedTime($viewer, $puzzle, 1800, $this->daysAgo(3), teamId: $this->seedTeam([$viewer, $coPuzzler]));
        $this->seedTime($coPuzzler, $puzzle, 1900, $this->daysAgo(2), teamId: $this->seedTeam([$coPuzzler, $candidate]));
        // A pair put together ahead, without a result together, is nobody "puzzling with" anyone yet
        $this->seedTeam([$coPuzzler, $notPuzzledYet]);
        $this->recalculate();

        $suggestions = $this->suggestionsFor($viewer);

        $found = $this->find($suggestions, $candidate);
        self::assertNotNull($found);
        self::assertSame(SuggestionReason::CoPuzzler, $found->reason);
        self::assertSame('Petra Pairs', $found->coPuzzlerName);
        self::assertNull($this->find($suggestions, $notPuzzledYet));
        self::assertNull($this->find($suggestions, $coPuzzler), 'Somebody you puzzle with is not suggested to you');
    }

    public function testAPrivateCoPuzzlerIsNotNamed(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $coPuzzler = $this->seedPlayer('Secret Pairs', private: true);
        $candidate = $this->seedPlayer('Cecilie Candidate');
        $puzzle = $this->seedPuzzle(500);

        $this->seedTime($viewer, $puzzle, 1800, $this->daysAgo(3), teamId: $this->seedTeam([$viewer, $coPuzzler]));
        $this->seedTime($coPuzzler, $puzzle, 1900, $this->daysAgo(2), teamId: $this->seedTeam([$coPuzzler, $candidate]));
        $this->recalculate();

        $found = $this->find($this->suggestionsFor($viewer), $candidate);
        self::assertNotNull($found);
        self::assertSame(SuggestionReason::CoPuzzler, $found->reason);
        self::assertNull($found->coPuzzlerName);
    }

    public function testACoPuzzlerWhoBlocksYouIsNotNamed(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $coPuzzler = $this->seedPlayer('Petra Pairs');
        $candidate = $this->seedPlayer('Cecilie Candidate');
        $puzzle = $this->seedPuzzle(500);

        $this->seedTime($viewer, $puzzle, 1800, $this->daysAgo(3), teamId: $this->seedTeam([$viewer, $coPuzzler]));
        $this->seedTime($coPuzzler, $puzzle, 1900, $this->daysAgo(2), teamId: $this->seedTeam([$coPuzzler, $candidate]));
        $this->seedBlock($coPuzzler, $viewer);
        $this->recalculate();

        $found = $this->find($this->suggestionsFor($viewer), $candidate);
        self::assertNotNull($found);
        self::assertNull($found->coPuzzlerName);
    }

    public function testSomebodyYouHaveBeenInATeamWithIsNeverSuggestedThroughACoPuzzler(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $coPuzzler = $this->seedPlayer('Petra Pairs');
        $teammate = $this->seedPlayer('Already Known');
        $puzzle = $this->seedPuzzle(500);

        $this->seedTime($viewer, $puzzle, 1800, $this->daysAgo(3), teamId: $this->seedTeam([$viewer, $coPuzzler]));
        $this->seedTime($coPuzzler, $puzzle, 1900, $this->daysAgo(2), teamId: $this->seedTeam([$coPuzzler, $teammate]));
        $this->seedTeam([$viewer, $teammate]);
        $this->recalculate();

        self::assertNull($this->find($this->suggestionsFor($viewer), $teammate));
    }

    public function testSomebodyFromAnEventYouTookPartInIsSuggested(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $fellow = $this->seedPlayer('Event Fellow');
        $unapprovedFellow = $this->seedPlayer('Pending Fellow');
        $older = $this->seedCompetition('Spring Cup 2025', new DateTimeImmutable('2025-04-01'));
        $newer = $this->seedCompetition('Autumn Cup 2026', new DateTimeImmutable('2026-09-01'));
        $unapproved = $this->seedCompetition('Pending Cup', new DateTimeImmutable('2026-09-10'), approved: false);

        foreach ([$older, $newer] as $competition) {
            $this->seedParticipant($competition, $viewer);
            $this->seedParticipant($competition, $fellow);
        }

        $this->seedParticipant($unapproved, $viewer);
        $this->seedParticipant($unapproved, $unapprovedFellow);
        $this->recalculate();

        $suggestions = $this->suggestionsFor($viewer);

        $found = $this->find($suggestions, $fellow);
        self::assertNotNull($found);
        self::assertSame(SuggestionReason::SameEvent, $found->reason);
        self::assertSame('Autumn Cup 2026', $found->eventName, 'The most recent shared event is named');
        self::assertNull($this->find($suggestions, $unapprovedFellow), 'An event that is not public is no reason');
    }

    public function testALeftEventIsNoReason(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $fellow = $this->seedPlayer('Event Fellow');
        $competition = $this->seedCompetition('Spring Cup 2025', new DateTimeImmutable('2025-04-01'));
        $this->seedParticipant($competition, $viewer);
        $this->seedParticipant($competition, $fellow, deleted: true);
        $this->recalculate();

        self::assertNull($this->find($this->suggestionsFor($viewer), $fellow));
    }

    public function testASimilarBest500IsSuggestedWithTheirTime(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $similar = $this->seedPlayer('Similar Speed');
        $faster = $this->seedPlayer('Much Faster');
        $optedOut = $this->seedPlayer('Opted Out', rankingOptedOut: true);
        $this->recalculate();

        $this->setBest500($viewer, self::VIEWER_BEST_500);
        $this->setBest500($similar, 21000);
        $this->setBest500($faster, 18000);
        $this->setBest500($optedOut, self::VIEWER_BEST_500);

        $suggestions = $this->suggestionsFor($viewer);

        $found = $this->find($suggestions, $similar);
        self::assertNotNull($found);
        self::assertSame(SuggestionReason::SimilarTime, $found->reason);
        self::assertSame(21000, $found->best500Seconds);
        self::assertNull($this->find($suggestions, $faster), '10 % faster is not similar');
        self::assertNull($this->find($suggestions, $optedOut), 'Who opted out of rankings is not compared by speed');
    }

    public function testManyOfTheSamePuzzlesAreSuggestedWithTheCount(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $sameTaste = $this->seedPlayer('Same Taste');
        $almost = $this->seedPlayer('Almost');

        for ($i = 0; $i < GetSuggestedPlayers::MIN_SHARED_PUZZLES + 2; $i++) {
            // 1000 pieces: no best 500, so the similar time cannot be the reason first
            $puzzle = $this->seedPuzzle(1000);
            $this->seedTime($viewer, $puzzle, 4000, $this->daysAgo(5));

            if ($i < GetSuggestedPlayers::MIN_SHARED_PUZZLES) {
                $this->seedTime($sameTaste, $puzzle, 4100, $this->daysAgo(4));
            }

            if ($i < GetSuggestedPlayers::MIN_SHARED_PUZZLES - 1) {
                $this->seedTime($almost, $puzzle, 4200, $this->daysAgo(4));
            }
        }

        // Enough results of their own, just one puzzle short of the threshold in common
        $this->seedTime($almost, $this->seedPuzzle(1000), 4200, $this->daysAgo(4));
        $this->seedTime($almost, $this->seedPuzzle(1000), 4200, $this->daysAgo(4));
        $this->recalculate();

        $suggestions = $this->suggestionsFor($viewer);

        $found = $this->find($suggestions, $sameTaste);
        self::assertNotNull($found);
        self::assertSame(SuggestionReason::PuzzlesInCommon, $found->reason);
        self::assertSame(GetSuggestedPlayers::MIN_SHARED_PUZZLES, $found->sharedPuzzles);
        self::assertNull($this->find($suggestions, $almost));
    }

    public function testTheFirstReasonThatAppliesWins(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $coPuzzler = $this->seedPlayer('Petra Pairs');
        $candidate = $this->seedPlayer('Cecilie Candidate');
        $puzzle = $this->seedPuzzle(1000);
        $competition = $this->seedCompetition('Spring Cup 2025', new DateTimeImmutable('2025-04-01'));

        $this->seedTime($viewer, $puzzle, 1800, $this->daysAgo(3), teamId: $this->seedTeam([$viewer, $coPuzzler]));
        $this->seedTime($coPuzzler, $puzzle, 1900, $this->daysAgo(2), teamId: $this->seedTeam([$coPuzzler, $candidate]));
        $this->seedParticipant($competition, $viewer);
        $this->seedParticipant($competition, $candidate);
        $this->recalculate();
        $this->setBest500($viewer, self::VIEWER_BEST_500);
        $this->setBest500($candidate, self::VIEWER_BEST_500);

        $found = $this->find($this->suggestionsFor($viewer), $candidate);
        self::assertNotNull($found);
        self::assertSame(SuggestionReason::CoPuzzler, $found->reason);
    }

    public function testFavoritesAreNotSuggested(): void
    {
        [$viewer, $candidate] = $this->viewerWithASimilarCandidate();
        $this->database->executeStatement(
            'UPDATE player SET favorite_players = :favorites WHERE id = :id',
            ['favorites' => json_encode([$candidate], JSON_THROW_ON_ERROR), 'id' => $viewer],
        );

        self::assertNull($this->find($this->suggestionsFor($viewer), $candidate));
    }

    public function testPrivateProfilesAreNotSuggested(): void
    {
        [$viewer, $candidate] = $this->viewerWithASimilarCandidate();
        $this->database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => $candidate]);

        self::assertNull($this->find($this->suggestionsFor($viewer), $candidate));
    }

    public function testThePlayerTheViewerBlocksIsNotSuggested(): void
    {
        $viewerUserId = 'test|suggestions-' . Uuid::uuid7()->toString();
        [$viewer, $candidate] = $this->viewerWithASimilarCandidate($viewerUserId);
        $this->seedBlock($viewer, $candidate);

        // Without a signed-in viewer the explicit block check alone keeps them out
        self::assertNull($this->find($this->suggestionsFor($viewer), $candidate));

        TestingViewer::signIn(self::getContainer(), $viewer);
        self::assertContains($candidate, self::getContainer()->get(HiddenPlayers::class)->ids());

        self::assertNull($this->find($this->suggestionsFor($viewer), $candidate));
    }

    public function testThePlayerWhoBlocksTheViewerIsNotSuggested(): void
    {
        [$viewer, $candidate] = $this->viewerWithASimilarCandidate();
        $this->seedBlock($candidate, $viewer);

        self::assertNull($this->find($this->suggestionsFor($viewer), $candidate));
    }

    public function testTheViewerIsNeverSuggestedToThemselves(): void
    {
        [$viewer, $candidate] = $this->viewerWithASimilarCandidate();

        $suggestions = $this->suggestionsFor($viewer);
        self::assertNotNull($this->find($suggestions, $candidate));
        self::assertNull($this->find($suggestions, $viewer));
    }

    public function testActivePeopleComeFirst(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $active = $this->seedPlayer('Active One');
        $away = $this->seedPlayer('Away One');
        $this->recalculate();

        foreach ([$viewer => self::VIEWER_BEST_500, $active => self::VIEWER_BEST_500 + 60, $away => self::VIEWER_BEST_500 + 30] as $playerId => $seconds) {
            $this->setBest500($playerId, $seconds);
        }

        $this->setLastSolvedAt($active, $this->daysAgo(10));
        $this->setLastSolvedAt($away, $this->daysAgo(GetSuggestedPlayers::ACTIVE_DAYS + 30));

        $order = $this->seededOrder($this->suggestionsFor($viewer), [$active, $away]);
        self::assertSame([$active, $away], $order);
        self::assertTrue($this->find($this->suggestionsFor($viewer), $active)?->active);
        self::assertFalse($this->find($this->suggestionsFor($viewer), $away)?->active);
    }

    public function testReasonsTakeTurns(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $coPuzzler = $this->seedPlayer('Petra Pairs');
        $viaCoPuzzler = [];
        $puzzle = $this->seedPuzzle(1000);
        $this->seedTime($viewer, $puzzle, 4000, $this->daysAgo(3), teamId: $this->seedTeam([$viewer, $coPuzzler]));

        for ($i = 0; $i < 3; $i++) {
            $candidate = $this->seedPlayer('Via Petra ' . $i);
            $this->seedTime($coPuzzler, $puzzle, 4000, $this->daysAgo(2), teamId: $this->seedTeam([$coPuzzler, $candidate]));
            $viaCoPuzzler[] = $candidate;
        }

        $similar = $this->seedPlayer('Similar Speed');
        $this->recalculate();
        $this->setBest500($viewer, self::VIEWER_BEST_500);
        $this->setBest500($similar, self::VIEWER_BEST_500 + 60);
        $this->setLastSolvedAt($similar, $this->daysAgo(1));

        $order = $this->seededOrder($this->suggestionsFor($viewer), [...$viaCoPuzzler, $similar]);

        // The best of each reason before the second of any: the one similar time is second, not after all three
        self::assertCount(4, $order);
        self::assertSame($similar, $order[1]);
    }

    public function testTheSelectionHoldsForTheDay(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $candidates = [];

        for ($i = 0; $i < 12; $i++) {
            $candidates[] = $this->seedPlayer('Similar ' . $i);
        }

        $this->recalculate();
        $this->setBest500($viewer, self::VIEWER_BEST_500);

        foreach ($candidates as $playerId) {
            $this->setBest500($playerId, self::VIEWER_BEST_500);
            $this->setLastSolvedAt($playerId, $this->daysAgo(1));
        }

        $morning = $this->queryAt('today 08:00');
        $evening = $this->queryAt('today 22:00');
        $tomorrow = $this->queryAt('tomorrow 08:00');

        $first = $this->seededOrder($morning->forViewer($viewer, self::EVERYBODY), $candidates);
        self::assertCount(12, $first);
        self::assertSame($first, $this->seededOrder($morning->forViewer($viewer, self::EVERYBODY), $candidates));
        self::assertSame($first, $this->seededOrder($evening->forViewer($viewer, self::EVERYBODY), $candidates));
        self::assertNotSame($first, $this->seededOrder($tomorrow->forViewer($viewer, self::EVERYBODY), $candidates), 'A new day, a new shuffle');
    }

    public function testAtMostTheLimit(): void
    {
        $viewer = $this->seedPlayer('Viewer');
        $candidates = [];

        for ($i = 0; $i < GetSuggestedPlayers::LIMIT + 3; $i++) {
            $candidates[] = $this->seedPlayer('Similar ' . $i);
        }

        $this->recalculate();
        $this->setBest500($viewer, self::VIEWER_BEST_500);

        foreach ($candidates as $playerId) {
            $this->setBest500($playerId, self::VIEWER_BEST_500);
        }

        self::assertCount(GetSuggestedPlayers::LIMIT, $this->query->forViewer($viewer));
    }

    public function testAnUnknownViewerGetsNothing(): void
    {
        self::assertSame([], $this->query->forViewer('not-a-uuid'));
        self::assertSame([], $this->query->forViewer(Uuid::uuid7()->toString()));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function viewerWithASimilarCandidate(null|string $viewerUserId = null): array
    {
        $viewer = $this->seedPlayer('Viewer', userId: $viewerUserId);
        $candidate = $this->seedPlayer('Similar Speed');
        $this->recalculate();
        $this->setBest500($viewer, self::VIEWER_BEST_500);
        $this->setBest500($candidate, self::VIEWER_BEST_500 + 60);

        return [$viewer, $candidate];
    }

    /**
     * @return list<SuggestedPlayer>
     */
    private function suggestionsFor(string $viewerId): array
    {
        return $this->query->forViewer($viewerId, self::EVERYBODY);
    }

    /**
     * @param list<SuggestedPlayer> $suggestions
     */
    private function find(array $suggestions, string $playerId): null|SuggestedPlayer
    {
        foreach ($suggestions as $suggestion) {
            if ($suggestion->playerId === $playerId) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * The given players in the order they were suggested (the fixtures' players left out).
     *
     * @param list<SuggestedPlayer> $suggestions
     * @param list<string> $playerIds
     * @return list<string>
     */
    private function seededOrder(array $suggestions, array $playerIds): array
    {
        return array_values(array_filter(
            array_map(static fn (SuggestedPlayer $suggestion): string => $suggestion->playerId, $suggestions),
            static fn (string $playerId): bool => in_array($playerId, $playerIds, true),
        ));
    }

    private function queryAt(string $time): GetSuggestedPlayers
    {
        return new GetSuggestedPlayers(
            $this->database,
            new MockClock(new DateTimeImmutable($time)),
            self::getContainer()->get(HiddenPlayers::class),
        );
    }

    private function recalculate(): void
    {
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
    }

    private function setBest500(string $playerId, int $seconds): void
    {
        $this->database->executeStatement(
            'UPDATE community_player_stats SET best500_seconds = :seconds WHERE player_id = :id',
            ['seconds' => $seconds, 'id' => $playerId],
            ['seconds' => ParameterType::INTEGER],
        );
    }

    private function setLastSolvedAt(string $playerId, DateTimeImmutable $at): void
    {
        $this->database->executeStatement(
            'UPDATE community_player_stats SET last_solved_at = :at WHERE player_id = :id',
            ['at' => $at->format('Y-m-d H:i:s'), 'id' => $playerId],
        );
    }

    private function seedCompetition(string $name, DateTimeImmutable $dateFrom, bool $approved = true): string
    {
        $id = Uuid::uuid7()->toString();

        $this->database->executeStatement(
            'INSERT INTO competition (id, name, date_from, date_to, approved_at, is_online) VALUES (:id, :name, :dateFrom, :dateFrom, :approvedAt, false)',
            [
                'id' => $id,
                'name' => $name,
                'dateFrom' => $dateFrom->format('Y-m-d H:i:s'),
                'approvedAt' => $approved ? $dateFrom->format('Y-m-d H:i:s') : null,
            ],
        );

        return $id;
    }

    private function seedParticipant(string $competitionId, string $playerId, bool $deleted = false): void
    {
        $this->database->executeStatement(
            <<<SQL
INSERT INTO competition_participant (id, name, competition_id, player_id, connected_at, deleted_at, source)
VALUES (:id, 'Participant', :competitionId, :playerId, NOW(), :deletedAt, 'imported')
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'competitionId' => $competitionId,
                'playerId' => $playerId,
                'deletedAt' => $deleted ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            ],
        );
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return new DateTimeImmutable("-{$days} days");
    }
}
