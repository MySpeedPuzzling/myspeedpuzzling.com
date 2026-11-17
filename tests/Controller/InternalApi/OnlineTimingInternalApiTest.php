<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Online timing through the internal API (docs/features/internal-api.md "Online timing"): turning it on and its
 * settings (PUT /internal-api/rounds/{id}/online-timing), adding participants into rounds (POST
 * /internal-api/competitions/{id}/participants) and the personal links (GET /internal-api/competitions/{id}/online-links)
 * - the same messages and refusals as the web pages. The Online Test Cup (a draft) has one solo round with online
 * timing, Olivia and Arthur with links, Nora going without one.
 */
final class OnlineTimingInternalApiTest extends WebTestCase
{
    use InternalApiRequests;
    use LiveVideoTesting;

    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string UNKNOWN = '018d0050-0000-0000-0000-00000000ffff';

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testTheSettingsOfARoundUsingOnlineTimingAreSaved(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PUT', self::timingUrl(self::ROUND), [
            'detectionMode' => 'shadow',
            'unfinishedMeasure' => 'placed',
            'missingPieceRule' => 'penalty_5s',
            'videoEnabled' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($answer['turnedOn']);
        self::assertSame(0, $answer['linksGiven']);
        self::assertFalse($answer['detectionModeLocked']);
        $timing = self::map($answer['onlineTiming']);
        self::assertSame(['shadow', 'placed', 'penalty_5s', true, false], [
            $timing['detectionMode'], $timing['unfinishedMeasure'], $timing['missingPieceRule'], $timing['videoEnabled'], $timing['participantsSeeEachOther'],
        ]);
        self::assertSame(['shadow', 'placed', 'penalty_5s'], self::settings(self::ROUND));

        // A field left out keeps its value
        self::callInternalApi($browser, 'PUT', self::timingUrl(self::ROUND), ['missingPieceRule' => 'complete_required']);
        self::assertSame(['shadow', 'placed', 'complete_required'], self::settings(self::ROUND));

        // The event's rounds carry it
        $competition = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . self::COMPETITION);
        $round = self::roundOf($competition, self::ROUND);
        self::assertSame('shadow', self::map($round['onlineTiming'])['detectionMode']);
    }

    public function testOnlineTimingIsTurnedOnWithThePagesDefaultsAndGivesTheMissingLinks(): void
    {
        $browser = self::createClient();
        $roundId = $this->createRound($browser, 'Second Round', 'solo');

        $competition = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . self::COMPETITION);
        self::assertNull(self::roundOf($competition, $roundId)['onlineTiming']);

        $answer = self::callInternalApi($browser, 'PUT', self::timingUrl($roundId));

        self::assertResponseIsSuccessful();
        self::assertTrue($answer['turnedOn']);
        // Nora, going without a link, got hers
        self::assertSame(1, $answer['linksGiven']);
        self::assertSame(['off', 'left', 'complete_required'], self::settings($roundId));
        self::assertNotNull(self::token(OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN));

        // Again: nothing turned on twice
        $again = self::callInternalApi($browser, 'PUT', self::timingUrl($roundId), ['detectionMode' => 'on']);
        self::assertFalse($again['turnedOn']);
        self::assertSame(1, self::connection()->fetchOne('SELECT COUNT(*) FROM online_round WHERE round_id = :round', ['round' => $roundId]));
        self::assertSame('on', self::settings($roundId)[0] ?? null);
    }

    public function testRoundsThatCannotUseOnlineTimingAreRefused(): void
    {
        $browser = self::createClient();

        // An in-person event
        $answer = self::callInternalApi($browser, 'PUT', self::timingUrl(OfficialResultsFixture::ROUND_GROUP_A));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('in_person_event', self::text($answer['error']));
        self::assertNull(self::settings(OfficialResultsFixture::ROUND_GROUP_A));

        // A pair round of an online event
        $pairs = $this->createRound($browser, 'Pairs Round', 'duo');
        self::callInternalApi($browser, 'PUT', self::timingUrl($pairs));
        self::assertResponseStatusCodeSame(409);
        self::assertNull(self::settings($pairs));

        self::callInternalApi($browser, 'PUT', self::timingUrl(self::UNKNOWN));
        self::assertResponseStatusCodeSame(404);

        $invalid = self::callInternalApi($browser, 'PUT', self::timingUrl(self::ROUND), ['detectionMode' => 'always', 'colour' => 'red']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['colour', 'detectionMode'], array_keys(self::map($invalid['errors'])));
    }

    public function testFinishDetectionIsLockedFromTheStartUntilAReset(): void
    {
        $browser = self::createClient();
        self::connection()->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = :startedAt WHERE id = :id",
            ['startedAt' => TestClock::now()->modify('-5 minutes')->format('Y-m-d H:i:s'), 'id' => self::ROUND],
        );

        $refused = self::callInternalApi($browser, 'PUT', self::timingUrl(self::ROUND), ['detectionMode' => 'on', 'unfinishedMeasure' => 'placed']);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Finish detection is fixed', self::text($refused['error']));
        self::assertSame(['off', 'left', 'complete_required'], self::settings(self::ROUND), 'nothing saved - not the other settings either');

        // The same mode with other settings is saved
        $answer = self::callInternalApi($browser, 'PUT', self::timingUrl(self::ROUND), ['detectionMode' => 'off', 'unfinishedMeasure' => 'placed']);
        self::assertResponseIsSuccessful();
        self::assertTrue($answer['detectionModeLocked']);
        self::assertSame(['off', 'placed', 'complete_required'], self::settings(self::ROUND));
    }

    public function testWithoutAMediaServerTheVideoSwitchesCannotChange(): void
    {
        $this->turnLiveVideoOff();
        $browser = self::createClient();

        $refused = self::callInternalApi($browser, 'PUT', self::timingUrl(self::ROUND), ['videoEnabled' => true, 'detectionMode' => 'shadow']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('video_not_configured', $refused['code']);
        self::assertSame(['off', 'left', 'complete_required'], self::settings(self::ROUND));

        // Their stored values are fine
        $answer = self::callInternalApi($browser, 'PUT', self::timingUrl(self::ROUND), ['videoEnabled' => false, 'detectionMode' => 'shadow']);
        self::assertResponseIsSuccessful();
        self::assertFalse($answer['videoAvailable']);
        self::assertSame('shadow', self::settings(self::ROUND)[0] ?? null);
    }

    public function testParticipantsAreAddedIntoRoundsOnceEach(): void
    {
        $browser = self::createClient();
        $second = $this->createRound($browser, 'Second Round', 'solo');
        $before = self::participantCount();

        $body = [
            'participants' => [
                ['name' => 'Load Tester 1', 'country' => 'CZ'],
                ['name' => 'Load Tester 2', 'country' => null],
                // Olivia, spelled otherwise - already a participant (in the first round)
                ['name' => 'olivia  ONLINE'],
                // The same person twice in one body
                ['name' => 'load tester 1'],
            ],
            'roundIds' => [self::ROUND, $second],
        ];
        $added = self::listOf(self::callInternalApi($browser, 'POST', self::participantsUrl(self::COMPETITION), $body));

        self::assertResponseStatusCodeSame(201);
        self::assertCount(3, $added);
        self::assertSame(['Load Tester 1', 'Load Tester 2', 'Olivia Online'], array_column($added, 'name'));
        self::assertSame([true, true, false], array_column($added, 'created'));
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $added[2]['participantId']);

        foreach ($added as $participant) {
            $roundIds = self::strings($participant['roundIds']);
            sort($roundIds);
            $expected = [self::ROUND, $second];
            sort($expected);
            self::assertSame($expected, $roundIds);
        }

        self::assertSame($before + 2, self::participantCount());
        self::assertSame('cz', self::connection()->fetchOne('SELECT country FROM competition_participant WHERE id = :id', ['id' => $added[0]['participantId']]));

        // Sent again: nobody added twice, the same ids
        $again = self::listOf(self::callInternalApi($browser, 'POST', self::participantsUrl(self::COMPETITION), $body));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(array_column($added, 'participantId'), array_column($again, 'participantId'));
        self::assertSame([false, false, false], array_column($again, 'created'));
        self::assertSame($before + 2, self::participantCount());
    }

    public function testParticipantRefusals(): void
    {
        $browser = self::createClient();
        $before = self::participantCount();

        self::callInternalApi($browser, 'POST', self::participantsUrl(self::UNKNOWN), ['participants' => [['name' => 'X']], 'roundIds' => []]);
        self::assertResponseStatusCodeSame(404);

        // A round of another event
        self::callInternalApi($browser, 'POST', self::participantsUrl(self::COMPETITION), ['participants' => [['name' => 'X']], 'roundIds' => [OfficialResultsFixture::ROUND_GROUP_A]]);
        self::assertResponseStatusCodeSame(404);

        $invalid = self::callInternalApi($browser, 'POST', self::participantsUrl(self::COMPETITION), ['participants' => [['name' => ''], ['nick' => 'Y']]]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['roundIds', 'participants[0].name', 'participants[1].nick', 'participants[1].name'], array_keys(self::map($invalid['errors'])));

        // The sheet's rules: an unknown country - nothing added, not the valid person either
        $refused = self::callInternalApi($browser, 'POST', self::participantsUrl(self::COMPETITION), [
            'participants' => [['name' => 'Valid Person'], ['name' => 'Bad Country', 'country' => 'xx']],
            'roundIds' => [self::ROUND],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('refused', $refused['code']);
        self::assertStringContainsString('invalid_country', self::text($refused['error']));

        // A name two participants share is never guessed
        self::connection()->insert('competition_participant', [
            'id' => '018d0050-0000-0000-0000-0000000002d1',
            'name' => 'Arthur Anonymous',
            'competition_id' => self::COMPETITION,
            'source' => 'manual',
        ]);
        $ambiguous = self::callInternalApi($browser, 'POST', self::participantsUrl(self::COMPETITION), ['participants' => [['name' => 'Arthur Anonymous']], 'roundIds' => [self::ROUND]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ambiguous_name', $ambiguous['code']);

        self::assertSame($before + 1, self::participantCount());
    }

    public function testThePersonalLinksOfEverybodyGoing(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $logs = self::captureLogs();

        $added = self::listOf(self::callInternalApi($browser, 'POST', self::participantsUrl(self::COMPETITION), [
            'participants' => [['name' => 'Load Tester 1']],
            'roundIds' => [self::ROUND],
        ]));
        $tester = self::text($added[0]['participantId']);

        $links = self::listOf(self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . self::COMPETITION . '/online-links'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $nora = self::token(OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        $testerToken = self::token($tester);
        self::assertNotNull($nora, 'Nora got her link');
        self::assertNotNull($testerToken, 'the person added got theirs');

        // By name, the language-free address, the rounds they are in
        self::assertSame([
            [OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, 'Arthur Anonymous', [self::ROUND], 'http://localhost/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS],
            [$tester, 'Load Tester 1', [self::ROUND], 'http://localhost/compete/' . $testerToken],
            [OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN, 'Nora Notoken', [], 'http://localhost/compete/' . $nora],
            [OnlineRoundsFixture::PARTICIPANT_CONNECTED, 'Olivia Online', [self::ROUND], 'http://localhost/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED],
        ], array_map(static fn (array $link): array => [$link['participantId'], $link['name'], $link['roundIds'], $link['url']], $links));

        // A second call writes nothing and answers the same
        self::assertSame($links, self::listOf(self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . self::COMPETITION . '/online-links')));

        // No personal link in any log line
        foreach ($logs->getRecords() as $record) {
            $line = $record->message . json_encode($record->context) . json_encode($record->extra);

            foreach ([$nora, $testerToken, OnlineRoundsFixture::TOKEN_ANONYMOUS, OnlineRoundsFixture::TOKEN_CONNECTED] as $token) {
                self::assertStringNotContainsString($token, $line);
            }
        }

        self::assertNotEmpty(array_filter($logs->getRecords(), static fn (LogRecord $record): bool => str_starts_with($record->message, 'Internal API write')), 'the audit log is captured');
    }

    public function testLinksOfAnEventWithoutOnlineTimingAreRefused(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/online-links');
        self::assertResponseStatusCodeSame(409);

        self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . self::UNKNOWN . '/online-links');
        self::assertResponseStatusCodeSame(404);
    }

    private function createRound(KernelBrowser $browser, string $name, string $category): string
    {
        $round = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . self::COMPETITION . '/rounds', [
            'name' => $name,
            'category' => $category,
            'startsAt' => TestClock::now()->modify('+30 days')->format('Y-m-d\TH:i:sP'),
            'minutesLimit' => 45,
        ]);
        self::assertResponseStatusCodeSame(201);

        return self::text($round['roundId']);
    }

    private static function captureLogs(): TestHandler
    {
        $handler = new TestHandler();

        foreach (['logger', 'monolog.logger.internal_api_audit'] as $id) {
            $logger = self::getContainer()->get($id);
            assert($logger instanceof Logger);
            $logger->pushHandler($handler);
        }

        return $handler;
    }

    private static function timingUrl(string $roundId): string
    {
        return '/internal-api/rounds/' . $roundId . '/online-timing';
    }

    private static function participantsUrl(string $competitionId): string
    {
        return '/internal-api/competitions/' . $competitionId . '/participants';
    }

    private static function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function participantCount(): int
    {
        $count = self::connection()->fetchOne('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id', ['id' => self::COMPETITION]);
        self::assertIsInt($count);

        return $count;
    }

    private static function token(string $participantId): null|string
    {
        $token = self::connection()->fetchOne('SELECT online_token FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($token) ? $token : null;
    }

    /**
     * @return null|array{string, string, string}
     */
    private static function settings(string $roundId): null|array
    {
        $row = self::connection()->fetchAssociative(
            'SELECT detection_mode, unfinished_measure, missing_piece_rule FROM online_round WHERE round_id = :round',
            ['round' => $roundId],
        );

        if ($row === false) {
            return null;
        }

        return [self::text($row['detection_mode']), self::text($row['unfinished_measure']), self::text($row['missing_piece_rule'])];
    }

    /**
     * @param array<string, mixed> $competition
     *
     * @return array<string, mixed>
     */
    private static function roundOf(array $competition, string $roundId): array
    {
        foreach (self::listOf($competition['rounds']) as $round) {
            if ($round['roundId'] === $roundId) {
                return $round;
            }
        }

        self::fail('No round ' . $roundId);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        /** @var list<array<string, mixed>> $value */
        return $value;
    }
}
