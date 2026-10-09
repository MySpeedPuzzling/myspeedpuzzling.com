<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\OpenApiAssertions;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CreateSolvingTimeEndpointTest extends WebTestCase
{
    use OpenApiAssertions;
    use QueryCountAssertions;

    public function testWithoutTokenReturnsUnauthorized(): void
    {
        $browser = self::createClient();

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCreateWithoutRoundId(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseIsSuccessful();

        $timeId = $this->extractTimeId($browser->getResponse()->getContent());

        /** @var array{competition_round_id: null|string, player_id: string}|false $row */
        $row = $this->database()->fetchAssociative(
            'SELECT competition_round_id, player_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        self::assertNotFalse($row);
        self::assertNull($row['competition_round_id']);
        // Guards against the time being attributed to a phantom player created from the player uuid
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
    }

    public function testCreateWithValidRoundIdLinksRoundAndCompetition(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
                'round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            ]),
        );

        $this->assertResponseIsSuccessful();

        $timeId = $this->extractTimeId($browser->getResponse()->getContent());

        /** @var array{competition_round_id: null|string, competition_id: null|string}|false $row */
        $row = $this->database()->fetchAssociative(
            'SELECT competition_round_id, competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        self::assertNotFalse($row);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $row['competition_round_id']);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $row['competition_id']);
    }

    public function testInvalidRoundIdReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
                'round_id' => '00000000-0000-0000-0000-000000000000',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * A round of an event nobody may see - a draft, one waiting for approval - does not exist for the API either
     * (docs/features/organizations/README.md "Drafts"): 404, nothing saved
     */
    public function testRoundOfAnEventThatIsNotPublicReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $send = static function () use ($browser): void {
            $browser->request(
                'POST',
                '/api/v1/me/solving-times',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string) json_encode([
                    'puzzle_id' => PuzzleFixture::PUZZLE_3000,
                    'time' => '55:00',
                    'round_id' => OrganizationFixture::ROUND_DRAFT_NIGHT,
                ]),
            );
        };

        $send();
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Published but waiting for approval: still not public
        $this->database()->executeStatement(
            'UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id',
            ['id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );
        $send();
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertSame(0, $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :roundId',
            ['roundId' => OrganizationFixture::ROUND_DRAFT_NIGHT],
        ));

        // Approved and published, the same request saves
        $this->database()->executeStatement(
            'UPDATE competition SET approved_at = NOW() WHERE id = :id',
            ['id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );
        $send();
        $this->assertResponseIsSuccessful();
    }

    /**
     * Pins the role name to what the bundle derives from the scope
     * (ROLE_OAUTH2_SOLVING-TIMES:WRITE, hyphen kept). The check used to spell it
     * with an underscore, so no OAuth2 token could ever write - and the PAT-only
     * tests above never noticed, because ROLE_PAT short-circuits the "or".
     */
    public function testOAuth2TokenWithWriteScopeCanCreate(): void
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
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseIsSuccessful();

        $timeId = $this->extractTimeId($browser->getResponse()->getContent());

        /** @var array{player_id: string}|false $row */
        $row = $this->database()->fetchAssociative(
            'SELECT player_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        self::assertNotFalse($row);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
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
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * A client_credentials token has no user behind it, so it must never reach
     * the processor (which asserts an ApiUser and would 500) - even when it
     * somehow carries the write scope.
     */
    public function testClientCredentialsTokenReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            ['solving-times:write'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRetryWithTheSameIdempotencyKeyAnswersTheSavedResult(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $send = function (string $idempotencyKey, string $comment) use ($browser): string {
            $browser->request(
                'POST',
                '/api/v1/me/solving-times',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey],
                content: (string) json_encode([
                    'puzzle_id' => PuzzleFixture::PUZZLE_1500_02,
                    'time' => '1:02:33',
                    'comment' => $comment,
                    'finished_at' => '2026-09-20T00:00:00+00:00',
                ]),
            );

            $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

            return (string) $browser->getResponse()->getContent();
        };

        $first = $send('retry-key-1', 'Morning');
        // The retry differs on purpose: it is still answered with what the first request saved
        $retry = $send('retry-key-1', 'Changed in the retry');

        self::assertSame($first, $retry);

        $timeId = $this->extractTimeId($first);
        /** @var array{created_via: string, comment: string} $row */
        $row = $this->database()->fetchAssociative('SELECT created_via, comment FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertSame(['created_via' => 'api', 'comment' => 'Morning'], $row);

        /** @var int|string $copies */
        $copies = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId AND seconds_to_solve = 3753',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_1500_02],
        );
        self::assertSame(1, (int) $copies);
        self::assertSame('resend_caught', $this->database()->fetchOne(
            "SELECT kind FROM result_duplicate_prevention WHERE time_id = :id AND via = 'api'",
            ['id' => $timeId],
        ));

        // Another key is another result
        $other = $send('retry-key-2', 'Evening');
        self::assertNotSame($timeId, $this->extractTimeId($other));
    }

    public function testAKeyReusedForAnotherResultIsRefused(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $send = function (string $time) use ($browser): void {
            $browser->request(
                'POST',
                '/api/v1/me/solving-times',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'reused-key'],
                content: (string) json_encode([
                    'puzzle_id' => PuzzleFixture::PUZZLE_1500_02,
                    'time' => $time,
                    'finished_at' => '2026-09-20T00:00:00+00:00',
                ]),
            );
        };

        $send('1:02:33');
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // The app reused the key of the previous result for a new one
        $send('1:05:00');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');

        /** @var array{type: string, title: string, detail: string, status: int} $problem */
        $problem = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/errors/idempotency_key_reused', $problem['type']);
        self::assertSame(422, $problem['status']);
        self::assertStringContainsString('Idempotency-Key', $problem['detail']);

        /** @var int|string $saved */
        $saved = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId AND seconds_to_solve = 3900',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_1500_02],
        );
        self::assertSame(0, $saved, 'Nothing is saved');
    }

    /**
     * H12 13 (docs/features/events-page/high-frequency-series.md "API v1"): series_id is a series pick - MySpeedPuzzling
     * finds the edition by the puzzle, with its round, and the response reads the link from the stored row
     */
    public function testSeriesIdIsMatchedToTheEditionAndItsRound(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $this->authenticate($browser);

        $response = $this->postTime($browser, ['puzzle_id' => $puzzle, 'time' => '1:05:00', 'finished_at' => '2026-03-02T20:30:00+00:00', 'series_id' => $series]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['round_id' => $round, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($response));
        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => $series, 'series_edition_match' => 'puzzle', 'competition_round_id' => $round],
            $this->scenario()->link($response['time_id']),
        );
    }

    /**
     * Nothing to match - a series without any edition (H13): the time is a result of the series without an edition, a
     * valid answer the API never asks about
     */
    public function testSeriesIdWithoutAnEditionToMatchIsASeriesLevelResult(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $this->authenticate($browser);

        $response = $this->postTime($browser, ['puzzle_id' => $puzzle, 'time' => '1:05:00', 'finished_at' => '2026-03-02T20:30:00+00:00', 'series_id' => strtoupper($series)]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['round_id' => null, 'competition_id' => null, 'series_id' => $series], self::linkOf($response));
        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $series, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario()->link($response['time_id']),
        );
    }

    /**
     * H12 1 + 13: competition_id links a one-time event explicitly (its round derived as for the web form) and an
     * edition explicitly - never as a series pick, even when another edition would match the puzzle
     */
    public function testCompetitionIdLinksAOneTimeEventOrAnEditionExplicitly(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $matching = $scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $scenario->round($matching, RoundCategory::Solo, '2026-03-20 19:00', puzzleIds: [$puzzle]);
        $this->authenticate($browser);

        $oneTime = $this->postTime($browser, ['puzzle_id' => PuzzleFixture::PUZZLE_500_01, 'time' => '10:00', 'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024, 'series_id' => null], self::linkOf($oneTime));
        self::assertSame(
            ['competition_id' => CompetitionFixture::COMPETITION_WJPC_2024, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
            $this->scenario()->link($oneTime['time_id']),
        );

        $explicit = $this->postTime($browser, ['puzzle_id' => $puzzle, 'time' => '1:05:00', 'finished_at' => '2026-03-20T20:30:00+00:00', 'competition_id' => $edition]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        // series_id = the series of the linked edition
        self::assertSame(['round_id' => null, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($explicit));
        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario()->link($explicit['time_id']),
        );
    }

    /**
     * Precedence round_id > competition_id > series_id: ids sent together that agree link the most specific one,
     * explicitly - every pair and all three
     */
    public function testAgreeingIdsLinkTheMostSpecificOneExplicitly(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $this->authenticate($browser);

        // Explicit: no series pick, the round derived or sent
        $explicit = ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => $round];

        // Each with its own time - an identical request within seconds would be answered with the first one's result
        foreach (
            [
            'round + competition' => [['round_id' => $round, 'competition_id' => $edition], '1:01:00'],
            'round + series' => [['round_id' => $round, 'series_id' => $series], '1:02:00'],
            'competition + series' => [['competition_id' => $edition, 'series_id' => $series], '1:03:00'],
            'all three' => [['round_id' => $round, 'competition_id' => $edition, 'series_id' => $series], '1:04:00'],
            ] as $case => [$ids, $time]
        ) {
            $response = $this->postTime($browser, ['puzzle_id' => $puzzle, 'time' => $time, 'finished_at' => '2026-03-02T20:30:00+00:00', ...$ids]);

            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $case);
            self::assertSame(['round_id' => $round, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($response), $case);
            self::assertSame($explicit, $this->scenario()->link($response['time_id']), $case);
        }
    }

    /**
     * Ids sent together that disagree are refused with a violation on the field (problem+json, 422) - nothing saved
     */
    public function testDisagreeingIdsAreRefusedOnTheField(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $otherSeries = $scenario->series('Moonlight Sprint League');
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $otherEdition = $scenario->edition($otherSeries, 'Sprint No. 7', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $this->authenticate($browser);

        foreach (
            [
            'round + another competition' => [['round_id' => $round, 'competition_id' => $otherEdition], 'competition_id'],
            'round + a one-time event' => [['round_id' => $round, 'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024], 'competition_id'],
            'round + another series' => [['round_id' => $round, 'series_id' => $otherSeries], 'series_id'],
            'round of a one-time event + a series' => [['round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'series_id' => $series], 'series_id'],
            'edition + another series' => [['competition_id' => $otherEdition, 'series_id' => $series], 'series_id'],
            'one-time event + a series' => [['competition_id' => CompetitionFixture::COMPETITION_WJPC_2024, 'series_id' => $series], 'series_id'],
            'all three, the series another' => [['round_id' => $round, 'competition_id' => $edition, 'series_id' => $otherSeries], 'series_id'],
            ] as $case => [$ids, $field]
        ) {
            $this->postTime($browser, ['puzzle_id' => $puzzle, 'time' => '1:05:00', 'finished_at' => '2026-03-02T20:30:00+00:00', ...$ids]);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
            /** @var array{violations: list<array{propertyPath: string, message: string}>} $problem */
            $problem = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([$field], array_column($problem['violations'], 'propertyPath'), $case);
        }

        self::assertSame(0, $this->timesOf($puzzle), 'Nothing is saved');
    }

    /**
     * Unknown, malformed or not publicly visible ids are a 404 before anything is saved - like round_id: a draft, an
     * event waiting for approval, a rejected one, a draft edition, an edition of a series that is not public, a draft
     * or pending series. A 404 wins over a disagreement.
     */
    public function testUnknownMalformedOrNotPublicIdsAreNotFound(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $draftSeries = $scenario->series('Moonlight Sprint League', draft: true);
        $pendingSeries = $scenario->series('Harbor Puzzle Club Nights', public: false);
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 8', '2026-03-08');
        $draftEdition = $scenario->edition($series, 'Jam No. 9', '2026-03-09', draft: true);
        $editionOfPendingSeries = $scenario->edition($pendingSeries, 'Club Night 1', '2026-03-09');
        $this->authenticate($browser);

        foreach (
            [
            'unknown competition' => ['competition_id' => '00000000-0000-0000-0000-000000000000'],
            'malformed competition' => ['competition_id' => 'not-a-uuid'],
            'draft event' => ['competition_id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
            'event waiting for approval' => ['competition_id' => CompetitionFixture::COMPETITION_UNAPPROVED],
            'rejected event' => ['competition_id' => CompetitionApiFixture::COMPETITION_API_REJECTED],
            'draft edition' => ['competition_id' => $draftEdition],
            'edition of a pending series' => ['competition_id' => $editionOfPendingSeries],
            'edition of a draft series' => ['competition_id' => OrganizationFixture::EDITION_QUIET_PINES_1],
            'unknown series' => ['series_id' => '00000000-0000-0000-0000-000000000000'],
            'malformed series' => ['series_id' => 'lantern'],
            'draft series' => ['series_id' => $draftSeries],
            'pending series' => ['series_id' => $pendingSeries],
            'public edition beside an unknown series' => ['competition_id' => $edition, 'series_id' => '00000000-0000-0000-0000-000000000000'],
            'draft edition beside its series' => ['competition_id' => $draftEdition, 'series_id' => $series],
            'public edition beside a draft series' => ['competition_id' => $edition, 'series_id' => $draftSeries],
            ] as $case => $ids
        ) {
            $this->postTime($browser, ['puzzle_id' => $puzzle, 'time' => '1:05:00', 'finished_at' => '2026-03-09T20:30:00+00:00', ...$ids]);

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $case);
        }

        self::assertSame(0, $this->timesOf($puzzle), 'Nothing is saved');
    }

    /**
     * The Idempotency-Key replay answers with the link as it is stored now - here the series-level time was matched
     * meanwhile: the first edition of the series appeared (the series reconcile linked it by its date)
     */
    public function testTheReplayCarriesTheStoredLink(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $this->authenticate($browser);

        $send = function () use ($browser, $puzzle, $series): string {
            $browser->request(
                'POST',
                '/api/v1/me/solving-times',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'series-pick-replay'],
                content: (string) json_encode(['puzzle_id' => $puzzle, 'time' => '1:05:00', 'finished_at' => '2026-03-02T20:30:00+00:00', 'series_id' => $series]),
            );

            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

            return (string) $browser->getResponse()->getContent();
        };

        $first = $send();
        self::assertSame($first, $send(), 'A retry is answered with exactly what the first request saved');

        /** @var array{time_id: string, round_id: null|string, competition_id: null|string, series_id: null|string} $firstResponse */
        $firstResponse = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['round_id' => null, 'competition_id' => null, 'series_id' => $series], self::linkOf($firstResponse));

        $edition = $this->scenario()->edition($series, 'Jam No. 1', '2026-03-02');

        /** @var array{time_id: string, round_id: null|string, competition_id: null|string, series_id: null|string} $replay */
        $replay = json_decode($send(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($firstResponse['time_id'], $replay['time_id']);
        self::assertSame(['round_id' => null, 'competition_id' => $edition, 'series_id' => $series], self::linkOf($replay));
    }

    /**
     * What an event link adds to a create (docs/features/events-page/high-frequency-series.md "Performance"), the
     * response reading the saved time from memory: competition_id = the competition and its visibility (2) + the round
     * derived from it (its lookup and the round, 2) - what linking an edition cost before; series_id = the series (1),
     * the matching rule (1), the edition found (1) + the same round derivation (2). Every request saves the first
     * solve of a puzzle nobody has solved yet on a freshly booted kernel, so everything else costs the same.
     */
    public function testWhatAnEventLinkAddsToTheCreate(): void
    {
        $browser = self::createClient();
        $scenario = $this->scenario();
        $series = $scenario->series();
        $withoutEvent = $scenario->puzzle('Copper Lighthouse');
        $withEdition = $scenario->puzzle('Silver Harbor');
        $withSeries = $scenario->puzzle('Amber Windmill');
        $edition = $scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$withEdition, $withSeries]);
        $this->authenticate($browser);

        // The first request reuses the kernel the setup warmed up - the measured ones each get a new one
        $browser->request('GET', '/api/v1/series');
        self::assertResponseIsSuccessful();

        $plain = $this->statementsOfACreate($browser, ['puzzle_id' => $withoutEvent]);

        self::assertSame($plain + 4, $this->statementsOfACreate($browser, ['puzzle_id' => $withEdition, 'competition_id' => $edition]), sprintf('Without an event: %d statements.', $plain));
        self::assertSame($plain + 5, $this->statementsOfACreate($browser, ['puzzle_id' => $withSeries, 'series_id' => $series]), sprintf('Without an event: %d statements.', $plain));
    }

    /**
     * The OpenAPI document carries the new fields of the inputs and the responses (JSON snake_case)
     */
    public function testOpenApiDocumentsTheEventLinkFields(): void
    {
        $browser = self::createClient();

        $document = $this->openApiDocument($browser);

        /** @var array{schemas: array<string, array{properties: array<string, mixed>}>} $components */
        $components = $document['components'];
        $schemas = $components['schemas'];

        foreach (['CreateSolvingTime', 'UpdateSolvingTime', 'CreateSolvingTime.SolvingTimeResponse', 'UpdateSolvingTime.SolvingTimeResponse'] as $schema) {
            self::assertArrayHasKey('competition_id', $schemas[$schema]['properties'], $schema);
            self::assertArrayHasKey('series_id', $schemas[$schema]['properties'], $schema);
        }

        self::assertArrayHasKey('round_id', $schemas['CreateSolvingTime']['properties']);
        self::assertArrayHasKey('round_id', $schemas['UpdateSolvingTime.SolvingTimeResponse']['properties']);

        /** @var array<string, array<string, array{description: string, responses: array<int|string, mixed>}>> $paths */
        $paths = $document['paths'];
        self::assertStringContainsString('series_id', $paths['/api/v1/me/solving-times']['post']['description']);
        self::assertArrayHasKey('404', $paths['/api/v1/me/solving-times']['post']['responses']);
        self::assertStringContainsString('series_id', $paths['/api/v1/me/solving-times/{timeId}']['put']['description']);
    }

    private function scenario(): SeriesEditionScenario
    {
        // A new one every time: the client boots a new kernel for every request after the first
        return new SeriesEditionScenario(self::getContainer());
    }

    private function authenticate(KernelBrowser $browser): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{time_id: string, round_id: null|string, competition_id: null|string, series_id: null|string}
     */
    private function postTime(KernelBrowser $browser, array $body): array
    {
        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($body),
        );

        /** @var array{time_id: string, round_id: null|string, competition_id: null|string, series_id: null|string} $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $response;
    }

    /**
     * @param array<string, string> $body
     */
    private function statementsOfACreate(KernelBrowser $browser, array $body): int
    {
        $this->startCountingQueries($browser);
        $this->postTime($browser, ['time' => '1:05:00', 'finished_at' => '2026-03-02T20:30:00+00:00', ...$body]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->queryCount($browser);
    }

    /**
     * @param array{time_id: string, round_id: null|string, competition_id: null|string, series_id: null|string} $response
     *
     * @return array{round_id: null|string, competition_id: null|string, series_id: null|string}
     */
    private static function linkOf(array $response): array
    {
        return ['round_id' => $response['round_id'], 'competition_id' => $response['competition_id'], 'series_id' => $response['series_id']];
    }

    /**
     * The token owner's times of the puzzle
     */
    private function timesOf(string $puzzleId): int
    {
        /** @var int|string $count */
        $count = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => $puzzleId],
        );

        return (int) $count;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function extractTimeId(string|false $responseContent): string
    {
        self::assertIsString($responseContent);

        /** @var array{time_id: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        return $response['time_id'];
    }
}
