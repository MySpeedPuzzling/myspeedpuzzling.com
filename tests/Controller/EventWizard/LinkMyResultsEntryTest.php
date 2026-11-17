<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\LinkSolvingTimesToEvent;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Were you there? Link my results" on the event and edition pages (docs/features/competitions-management/
 * creation-wizard.md "Backfill", entry points) and the waiting link's note on the result detail and My data.
 *
 * TIME_08 = PLAYER_REGULAR's solo PUZZLE_500_02, 10 days ago (TIME_07 15 days ago - before the event, practice).
 */
final class LinkMyResultsEntryTest extends WebTestCase
{
    private const string EVENT_SLUG = 'were-you-there-open';
    private const string EVENT_NAME = 'Were You There Open';
    private const string PENDING_URL = '/en/events/unapproved-puzzle-event';

    public function testAGuestGetsNoEntry(): void
    {
        $browser = self::createClient();
        $this->publicEvent(PuzzleFixture::PUZZLE_500_02, $this->dayOf(PuzzleSolvingTimeFixture::TIME_08));

        $browser->request('GET', '/en/events/' . self::EVENT_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-link-my-results]');
    }

    public function testAPlayerWithAResultOfTheEventsPuzzleGetsTheEntryLeadingToTheLinkStep(): void
    {
        $browser = $this->client(PlayerFixture::PLAYER_REGULAR);
        $competitionId = $this->publicEvent(PuzzleFixture::PUZZLE_500_02, $this->dayOf(PuzzleSolvingTimeFixture::TIME_08));

        $crawler = $browser->request('GET', '/en/events/' . self::EVENT_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-link-my-results]', '1 of your results may be from this event.');
        self::assertSame(
            '/en/new-event/' . $competitionId . '/link-results?from=event',
            $crawler->filter('[data-link-my-results-action]')->attr('href'),
        );

        // The Link page lists it, ticked
        $crawler = $browser->request('GET', '/en/new-event/' . $competitionId . '/link-results?from=event');
        self::assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('[data-time-id="' . PuzzleSolvingTimeFixture::TIME_08 . '"] input[type="checkbox"]')->attr('checked'));
        self::assertSelectorExists('[data-event-wizard-link-back-to-event]');

        // Linking from there lands back on the event's page - and the entry is gone
        $browser->request('POST', '/en/new-event/' . $competitionId . '/link-results?from=event', [
            'link_results' => ['times' => [PuzzleSolvingTimeFixture::TIME_08], '_token' => $this->token($crawler)],
        ]);
        self::assertResponseRedirects('/en/events/' . self::EVENT_SLUG);
        $browser->followRedirect();
        self::assertSelectorNotExists('[data-link-my-results]');
        self::assertSame($competitionId, $this->database()->fetchOne('SELECT competition_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_08]));
    }

    public function testAPlayerWithoutMatchingResultsGetsNoEntry(): void
    {
        $browser = $this->client(PlayerFixture::PLAYER_REGULAR);
        // PUZZLE_500_02 a month before TIME_06 - none of his results is from that week
        $this->publicEvent(PuzzleFixture::PUZZLE_500_02, $this->dayOf(PuzzleSolvingTimeFixture::TIME_06)->modify('-30 days'));

        $browser->request('GET', '/en/events/' . self::EVENT_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-link-my-results]');
    }

    public function testAResultLinkedElsewhereOrWaitingIsNotCounted(): void
    {
        $browser = $this->client(PlayerFixture::PLAYER_REGULAR);
        $this->publicEvent(PuzzleFixture::PUZZLE_500_02, $this->dayOf(PuzzleSolvingTimeFixture::TIME_08));
        // Waiting for his own pending event
        $this->bus()->dispatch(new LinkSolvingTimesToEvent(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_08]));

        $browser->request('GET', '/en/events/' . self::EVENT_SLUG);
        self::assertSelectorNotExists('[data-link-my-results]');
    }

    public function testAnEventThatHasNotStartedHasNoEntry(): void
    {
        $browser = $this->client(PlayerFixture::PLAYER_REGULAR);
        $this->publicEvent(PuzzleFixture::PUZZLE_500_02, $this->dayOf(PuzzleSolvingTimeFixture::TIME_08)->modify('+15 days'));

        $browser->request('GET', '/en/events/' . self::EVENT_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-link-my-results]');
    }

    public function testAnEventWithoutPuzzlesCountsTheResultsOfItsDays(): void
    {
        $browser = $this->client(PlayerFixture::PLAYER_REGULAR);
        $this->publicEvent(null, $this->dayOf(PuzzleSolvingTimeFixture::TIME_07));

        $browser->request('GET', '/en/events/' . self::EVENT_SLUG);
        self::assertSelectorExists('[data-link-my-results]');
    }

    public function testTheOwnPendingEventHasTheEntryButNobodyElseSeesIt(): void
    {
        $this->pendingEventOn($this->dayOf(PuzzleSolvingTimeFixture::TIME_08));

        $browser = $this->client(PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::PENDING_URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-link-my-results]');

        // The organiser linking from the event's page lands back there, not on the wizard's Done page
        $crawler = $browser->request('GET', '/en/new-event/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/link-results?from=event');
        $browser->request('POST', '/en/new-event/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/link-results?from=event', [
            'link_results' => ['times' => [PuzzleSolvingTimeFixture::TIME_08], '_token' => $this->token($crawler)],
        ]);
        self::assertResponseRedirects(self::PENDING_URL);

        // Somebody else with a result of that puzzle and day: not their event to link to
        $other = $this->client(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->soloTimeOf(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_02, $this->dayOf(PuzzleSolvingTimeFixture::TIME_08));
        $other->request('GET', self::PENDING_URL);
        self::assertSelectorNotExists('[data-link-my-results]');
    }

    public function testATeamMembersResultIsCounted(): void
    {
        $team = $this->database()->fetchAssociative(<<<SQL
SELECT pst.id, pst.puzzle_id, member.player_id
FROM puzzle_solving_time pst
INNER JOIN puzzling_team_member member ON member.team_id = pst.puzzling_team_id
WHERE pst.puzzling_team_id IS NOT NULL
    AND pst.created_via IS DISTINCT FROM 'event_import'
    AND pst.competition_id IS NULL AND pst.competition_series_id IS NULL
    AND pst.pieces_placed IS NULL AND pst.finished_at_precision IS NULL
    AND member.player_id IS NOT NULL
    AND member.player_id IS DISTINCT FROM pst.player_id
ORDER BY pst.id
LIMIT 1
SQL);
        self::assertIsArray($team, 'the fixtures have a pair/team result with a registered member besides its tracker');

        $browser = $this->client(self::text($team['player_id']));
        $this->publicEvent(self::text($team['puzzle_id']), $this->dayOf(self::text($team['id'])));

        $browser->request('GET', '/en/events/' . self::EVENT_SLUG);
        self::assertSelectorExists('[data-link-my-results]');
    }

    public function testTheEditionPageHasTheEntryToo(): void
    {
        $browser = $this->client(PlayerFixture::PLAYER_REGULAR);
        $day = $this->dayOf(PuzzleSolvingTimeFixture::TIME_08);
        $editionId = Uuid::uuid7()->toString();
        $this->database()->executeStatement(
            "INSERT INTO competition (id, name, slug, series_id, is_online, date_from, date_to, created_at) VALUES (:id, 'Were you there session', 'were-you-there-session', :series, true, :day, :day, " . TestClock::SQL_NOW . ")",
            ['id' => $editionId, 'series' => EventsPageFixture::SERIES_HARBOR_NIGHTS, 'day' => $day->format('Y-m-d')],
        );
        $this->roundWithPuzzle($editionId, PuzzleFixture::PUZZLE_500_02, $day);

        $crawler = $browser->request('GET', '/en/series/harbor-jigsaw-nights/were-you-there-session');
        self::assertResponseIsSuccessful();
        self::assertSame('/en/new-event/' . $editionId . '/link-results?from=event', $crawler->filter('[data-link-my-results-action]')->attr('href'));
    }

    public function testAWaitingLinkIsNotedForWhoeverMayChangeTheTimeOnly(): void
    {
        $this->bus()->dispatch(new LinkSolvingTimesToEvent(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_08]));
        $note = 'Linked to Unapproved Puzzle Event once an admin approves it';

        $owner = $this->client(PlayerFixture::PLAYER_REGULAR);
        $owner->request('GET', '/en/result/' . PuzzleSolvingTimeFixture::TIME_08);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-waiting-event-link]', $note);
        self::assertSelectorCount(1, '[data-waiting-event-link]');

        $owner->request('GET', '/en/my-data');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-row-id="' . PuzzleSolvingTimeFixture::TIME_08 . '"] [data-testid="my-data-waiting-link"]', $note);
        // The table (desktop) and the list (phone) both say it
        self::assertSelectorCount(2, '[data-row-id="' . PuzzleSolvingTimeFixture::TIME_08 . '"] [data-testid="my-data-waiting-link"]');

        $other = $this->client(PlayerFixture::PLAYER_WITH_FAVORITES);
        $other->request('GET', '/en/result/' . PuzzleSolvingTimeFixture::TIME_08);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-waiting-event-link]');

        self::ensureKernelShutdown();
        $guest = self::createClient();
        $guest->request('GET', '/en/result/' . PuzzleSolvingTimeFixture::TIME_08);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-waiting-event-link]');
    }

    public function testATeamMemberSeesTheWaitingLinkOfTheTeamsResult(): void
    {
        $team = $this->database()->fetchAssociative(<<<SQL
SELECT pst.id, pst.player_id AS tracker_id, member.player_id AS member_id
FROM puzzle_solving_time pst
INNER JOIN puzzling_team_member member ON member.team_id = pst.puzzling_team_id
WHERE pst.puzzling_team_id IS NOT NULL
    AND pst.created_via IS DISTINCT FROM 'event_import'
    AND pst.player_id IS NOT NULL
    AND member.player_id IS NOT NULL
    AND member.player_id IS DISTINCT FROM pst.player_id
ORDER BY pst.id
LIMIT 1
SQL);
        self::assertIsArray($team);
        $timeId = self::text($team['id']);

        // Linked by the tracker to an event they may edit - a pending event of their own
        $this->database()->executeStatement('INSERT INTO competition_maintainer (competition_id, player_id) VALUES (:competition, :player) ON CONFLICT DO NOTHING', ['competition' => CompetitionFixture::COMPETITION_UNAPPROVED, 'player' => $team['tracker_id']]);
        $this->bus()->dispatch(new LinkSolvingTimesToEvent(self::text($team['tracker_id']), CompetitionFixture::COMPETITION_UNAPPROVED, null, [$timeId]));
        self::assertSame(1, (int) self::text($this->database()->fetchOne('SELECT COUNT(*) FROM waiting_event_link WHERE puzzle_solving_time_id = :id', ['id' => $timeId])));

        $member = $this->client(self::text($team['member_id']));
        $member->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-waiting-event-link]', 'Unapproved Puzzle Event');
    }

    private function publicEvent(null|string $puzzleId, \DateTimeImmutable $day): string
    {
        $id = Uuid::uuid7()->toString();
        $this->database()->executeStatement(
            "INSERT INTO competition (id, name, slug, location, location_country_code, date_from, date_to, is_online, approved_at, created_at)
             VALUES (:id, :name, :slug, 'Prague', 'cz', :day, :day, false, " . TestClock::SQL_NOW . ", " . TestClock::SQL_NOW . ")",
            ['id' => $id, 'name' => self::EVENT_NAME, 'slug' => self::EVENT_SLUG, 'day' => $day->format('Y-m-d')],
        );

        if ($puzzleId !== null) {
            $this->roundWithPuzzle($id, $puzzleId, $day);
        }

        return $id;
    }

    private function pendingEventOn(\DateTimeImmutable $day): void
    {
        $this->database()->executeStatement('UPDATE competition SET date_from = :day, date_to = :day WHERE id = :id', ['day' => $day->format('Y-m-d'), 'id' => CompetitionFixture::COMPETITION_UNAPPROVED]);
        $this->roundWithPuzzle(CompetitionFixture::COMPETITION_UNAPPROVED, PuzzleFixture::PUZZLE_500_02, $day);
    }

    private function roundWithPuzzle(string $competitionId, string $puzzleId, \DateTimeImmutable $day): void
    {
        $roundId = Uuid::uuid7()->toString();
        $this->database()->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at) VALUES (:id, :competition, 'Final', 60, :startsAt)",
            ['id' => $roundId, 'competition' => $competitionId, 'startsAt' => $day->format('Y-m-d') . ' 10:00:00'],
        );
        $this->database()->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts) VALUES (:id, :round, :puzzle, false)',
            ['id' => Uuid::uuid7()->toString(), 'round' => $roundId, 'puzzle' => $puzzleId],
        );
    }

    private function soloTimeOf(string $playerId, string $puzzleId, \DateTimeImmutable $day): void
    {
        $this->database()->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, puzzling_type, puzzlers_count, first_attempt, unboxed, suspicious)
             VALUES (:id, :player, :puzzle, 1800, :at, :at, true, 'solo', 1, false, false, false)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'puzzle' => $puzzleId, 'at' => $day->format('Y-m-d') . ' 12:00:00'],
        );
    }

    private function dayOf(string $timeId): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::text($this->database()->fetchOne('SELECT CAST(COALESCE(finished_at, tracked_at) AS DATE) FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId])));
    }

    private function token(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="link_results[_token]"]')->attr('value');
    }

    private function client(string $playerId): KernelBrowser
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $bus;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
