<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class UpdateSolvingTimeEndpointTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testUpdateOwnTimeKeepsAttributionToPlayer(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'time' => '00:25:00',
                'comment' => 'Updated via API',
            ]),
        );

        $this->assertResponseIsSuccessful();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{player_id: string, comment: null|string}|false $row */
        $row = $database->fetchAssociative(
            'SELECT player_id, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        self::assertNotFalse($row);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
        self::assertSame('Updated via API', $row['comment']);
    }

    public function testUpdateKeepsCompetitionAndRoundLink(): void
    {
        // The PUT payload carries no event information; the processor must carry the time's current
        // competition through to the handler, otherwise modify() detaches it (regression: it used to pass null).
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_09,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Still a WJPC result']),
        );

        $this->assertResponseIsSuccessful();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{competition_id: null|string, competition_round_id: null|string, comment: null|string}|false $row */
        $row = $database->fetchAssociative(
            'SELECT competition_id, competition_round_id, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_09],
        );

        self::assertNotFalse($row);
        self::assertSame('Still a WJPC result', $row['comment']);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $row['competition_id']);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $row['competition_round_id']);
    }

    public function testGroupMemberCanUpdateTimeTrackedByTeammate(): void
    {
        // TIME_12 was tracked by PLAYER_REGULAR with PLAYER_PRIVATE in the group
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_PRIVATE);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_12,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'time' => '01:05:00',
                'comment' => 'Fixed by the teammate',
                'group_players' => ['#player2'],
            ]),
        );

        $this->assertResponseIsSuccessful();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{player_id: string, seconds_to_solve: int, puzzlers_count: int}|false $row */
        $row = $database->fetchAssociative(
            'SELECT player_id, seconds_to_solve, puzzlers_count FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );

        self::assertNotFalse($row);
        // Still the tracker's row, still a duo
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
        self::assertSame(3900, $row['seconds_to_solve']);
        self::assertSame(2, $row['puzzlers_count']);
    }

    public function testUpdateForeignTimeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_02,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Hijacked']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUpdateUnknownTimeReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/00000000-0000-0000-0000-000000000000',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Whatever']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOAuth2TokenWithWriteScopeCanUpdateOwnTime(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['solving-times:write'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Updated via OAuth2']),
        );

        $this->assertResponseIsSuccessful();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{player_id: string, comment: null|string}|false $row */
        $row = $database->fetchAssociative(
            'SELECT player_id, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        self::assertNotFalse($row);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
        self::assertSame('Updated via OAuth2', $row['comment']);
    }

    public function testOAuth2TokenWithoutWriteScopeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read', 'results:read'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'No write scope']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * docs/features/events-page/high-frequency-series.md "API v1": without competition_id and series_id a series pick
     * stays a series pick - passed on as the series, never as the edition found for it (that would turn it into an
     * explicit link to the edition) - and a series-level time stays series-level. The response reads the stored row.
     */
    public function testOmittedLinkKeepsASeriesPick(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $matched = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', seriesId: $series);
        $seriesLevel = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $scenario->puzzle('Silver Harbor'), '2026-04-20', seriesId: $series);
        $this->authenticate($browser);

        $matchedLink = ['competition_id' => $edition, 'competition_series_id' => $series, 'series_edition_match' => 'puzzle', 'competition_round_id' => $round];
        self::assertSame($matchedLink, $scenario->link($matched));

        $response = $this->putTime($browser, $matched, ['time' => '01:05:00', 'finished_at' => '2026-03-02T00:00:00+00:00', 'comment' => 'Still the jam']);

        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => $round, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($response));
        self::assertSame($matchedLink, $this->scenario()->link($matched));

        $response = $this->putTime($browser, $seriesLevel, ['time' => '01:05:00', 'finished_at' => '2026-04-20T00:00:00+00:00', 'comment' => 'Still no date']);

        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => null, 'competition_id' => null, 'series_id' => $series], self::linkOf($response));
        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $series, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario()->link($seriesLevel),
        );
    }

    /**
     * An explicit link to an edition stays explicit - the series is answered as the edition's
     */
    public function testOmittedLinkKeepsAnExplicitEditionExplicit(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $time = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', competitionId: $edition);
        $this->authenticate($browser);

        $response = $this->putTime($browser, $time, ['time' => '01:05:00', 'finished_at' => '2026-03-02T00:00:00+00:00']);

        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => $round, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($response));
        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => $round],
            $this->scenario()->link($time),
        );
    }

    /**
     * A client echoing the answer of an automatic link - competition_id and series_id unchanged, or series_id alone -
     * keeps the series pick; competition_id alone makes it an explicit link to that edition
     */
    public function testEchoingAnAutomaticLinkKeepsTheSeriesPick(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $time = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', seriesId: $series);
        $this->authenticate($browser);
        $body = ['time' => '01:05:00', 'finished_at' => '2026-03-02T00:00:00+00:00'];
        $automatic = ['competition_id' => $edition, 'competition_series_id' => $series, 'series_edition_match' => 'puzzle', 'competition_round_id' => $round];

        foreach (['both ids echoed' => ['competition_id' => $edition, 'series_id' => $series], 'series_id alone' => ['series_id' => $series]] as $case => $ids) {
            $response = $this->putTime($browser, $time, [...$body, ...$ids]);

            self::assertResponseIsSuccessful($case);
            self::assertSame(['round_id' => $round, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($response), $case);
            self::assertSame($automatic, $this->scenario()->link($time), $case);
        }

        $response = $this->putTime($browser, $time, [...$body, 'competition_id' => $edition]);

        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => $round, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($response));
        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => $round],
            $this->scenario()->link($time),
        );
    }

    /**
     * P18: the response's round_id is the stored round - it used to be null on every PUT
     */
    public function testTheResponseCarriesTheStoredLink(): void
    {
        $browser = self::createClient();
        $this->authenticate($browser);

        $response = $this->putTime($browser, PuzzleSolvingTimeFixture::TIME_09, ['comment' => 'Still a result of the event']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024, 'series_id' => null],
            self::linkOf($response),
        );
    }

    /**
     * competition_id or series_id changes the link: explicit edition -> series pick (matched by the puzzle to another
     * edition) -> explicit edition -> a one-time event
     */
    public function testCompetitionIdOrSeriesIdChangesTheLink(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $explicitEdition = $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $matchingEdition = $scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $round = $scenario->round($matchingEdition, RoundCategory::Solo, '2026-03-20 19:00', puzzleIds: [$puzzle]);
        $time = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', competitionId: $explicitEdition);
        $this->authenticate($browser);
        $body = ['time' => '01:05:00', 'finished_at' => '2026-03-02T00:00:00+00:00'];

        $response = $this->putTime($browser, $time, [...$body, 'series_id' => $series]);
        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => $round, 'competition_id' => $matchingEdition, 'series_id' => $series], self::linkOf($response));
        self::assertSame(
            ['competition_id' => $matchingEdition, 'competition_series_id' => $series, 'series_edition_match' => 'puzzle', 'competition_round_id' => $round],
            $this->scenario()->link($time),
        );

        $response = $this->putTime($browser, $time, [...$body, 'competition_id' => $explicitEdition, 'series_id' => $series]);
        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => null, 'competition_id' => $explicitEdition, 'series_id' => $series], self::linkOf($response));
        self::assertSame(
            ['competition_id' => $explicitEdition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario()->link($time),
        );

        $response = $this->putTime($browser, $time, [...$body, 'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => null, 'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024, 'series_id' => null], self::linkOf($response));
    }

    /**
     * The time's current competition or series is accepted when it is no longer publicly visible (the edit form's
     * include-current rule) - any other one that is not public is a 404 and changes nothing
     */
    public function testTheCurrentCompetitionOrSeriesIsAcceptedWhenNoLongerPublic(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $draftSeries = $scenario->series('Moonlight Sprint League', draft: true);
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $draftEdition = $scenario->edition($series, 'Jam No. 2', '2026-03-09', draft: true);
        $time = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', competitionId: $edition);
        $this->authenticate($browser);
        $body = ['time' => '01:05:00', 'finished_at' => '2026-03-02T00:00:00+00:00'];

        // The series goes back to draft - it and its editions are no longer public
        $this->database()->executeStatement('UPDATE competition_series SET is_draft = true WHERE id = :id', ['id' => $series]);

        $this->putTime($browser, $time, [...$body, 'competition_id' => $edition]);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario()->link($time),
        );

        // The series of the linked edition is the time's current series: a series pick of it - with no public edition
        // to match, a result of the series without an edition
        $response = $this->putTime($browser, $time, [...$body, 'series_id' => $series]);
        self::assertResponseIsSuccessful();
        self::assertSame(['round_id' => null, 'competition_id' => null, 'series_id' => $series], self::linkOf($response));

        $this->putTime($browser, $time, [...$body, 'series_id' => $series, 'comment' => 'Picked again']);
        self::assertResponseIsSuccessful();

        foreach (['another draft series' => ['series_id' => $draftSeries], 'a draft edition it is not linked to' => ['competition_id' => $draftEdition]] as $case => $ids) {
            $this->putTime($browser, $time, [...$body, ...$ids, 'comment' => 'Refused']);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $case);
        }

        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $series, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario()->link($time),
        );
        self::assertSame('Picked again', $this->database()->fetchOne('SELECT comment FROM puzzle_solving_time WHERE id = :id', ['id' => $time]));
    }

    /**
     * Unknown or malformed ids are a 404, ids that disagree a 422 on series_id - the time stays as it was
     */
    public function testUnknownOrDisagreeingIdsChangeNothing(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $otherSeries = $scenario->series('Moonlight Sprint League');
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $otherEdition = $scenario->edition($otherSeries, 'Sprint No. 1', '2026-03-02');
        $time = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', competitionId: $edition);
        $this->authenticate($browser);
        $body = ['time' => '01:05:00', 'finished_at' => '2026-03-02T00:00:00+00:00', 'comment' => 'Refused'];

        foreach (
            [
            'unknown competition' => ['competition_id' => '00000000-0000-0000-0000-000000000000'],
            'malformed competition' => ['competition_id' => 'jam'],
            'unknown series' => ['series_id' => '00000000-0000-0000-0000-000000000000'],
            'malformed series' => ['series_id' => 'jam'],
            'an event waiting for approval' => ['competition_id' => CompetitionFixture::COMPETITION_UNAPPROVED],
            ] as $case => $ids
        ) {
            $this->putTime($browser, $time, [...$body, ...$ids]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $case);
        }

        foreach (
            [
            'its edition + another series' => ['competition_id' => $edition, 'series_id' => $otherSeries],
            'another edition + its series' => ['competition_id' => $otherEdition, 'series_id' => $series],
            'a one-time event + its series' => ['competition_id' => CompetitionFixture::COMPETITION_WJPC_2024, 'series_id' => $series],
            ] as $case => $ids
        ) {
            $this->putTime($browser, $time, [...$body, ...$ids]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            /** @var array{violations: list<array{propertyPath: string}>} $problem */
            $problem = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['series_id'], array_column($problem['violations'], 'propertyPath'), $case);
        }

        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario()->link($time),
        );
        self::assertNull($this->database()->fetchOne('SELECT comment FROM puzzle_solving_time WHERE id = :id', ['id' => $time]));
    }

    /**
     * Keeping a series pick costs the series and the matching rule more than keeping an explicit link to the same
     * edition (docs/features/events-page/high-frequency-series.md "Performance") - both times of the same puzzle,
     * each request on a freshly booted kernel
     */
    public function testWhatKeepingASeriesPickCosts(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $explicit = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', competitionId: $edition, time: '01:04:00');
        $seriesPick = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', seriesId: $series, time: '01:06:00');
        $this->authenticate($browser);

        // The first request reuses the kernel the setup warmed up - the measured ones each get a new one
        $browser->request('GET', '/api/v1/series');
        self::assertResponseIsSuccessful();

        // Each keeps its own time - the same time twice would be a pair of duplicates to detect
        $count = function (string $timeId, string $time) use ($browser): int {
            $this->startCountingQueries($browser);
            $this->putTime($browser, $timeId, ['time' => $time, 'finished_at' => '2026-03-02T00:00:00+00:00']);
            self::assertResponseIsSuccessful();

            return $this->queryCount($browser);
        };

        $explicitCount = $count($explicit, '01:04:00');

        self::assertSame($explicitCount + 2, $count($seriesPick, '01:06:00'), sprintf('Keeping the explicit link: %d statements.', $explicitCount));
    }

    private function scenario(): SeriesEditionScenario
    {
        // A new one every time: the client boots a new kernel for every request after the first
        return new SeriesEditionScenario(self::getContainer());
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function authenticate(KernelBrowser $browser): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{round_id: null|string, competition_id: null|string, series_id: null|string}
     */
    private function putTime(KernelBrowser $browser, string $timeId, array $body): array
    {
        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . $timeId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($body),
        );

        /** @var array{round_id: null|string, competition_id: null|string, series_id: null|string} $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $response;
    }

    /**
     * @param array{round_id: null|string, competition_id: null|string, series_id: null|string} $response
     *
     * @return array{round_id: null|string, competition_id: null|string, series_id: null|string}
     */
    private static function linkOf(array $response): array
    {
        return ['round_id' => $response['round_id'], 'competition_id' => $response['competition_id'], 'series_id' => $response['series_id']];
    }
}
