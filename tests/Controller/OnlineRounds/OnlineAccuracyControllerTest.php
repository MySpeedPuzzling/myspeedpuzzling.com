<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Controller\OnlineRounds\OnlineAccuracyController;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Message\MarkOnlineMomentGap;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The accuracy report of an online round, Phase 1 (online_accuracy, docs/features/online-competitions/README.md §5.9,
 * stream C2): the reviewer's adjustment (confirmed − tap) per participant and its distribution, as a page and as CSV -
 * every CSV cell through SpreadsheetSafeValue, numbers staying numbers.
 */
final class OnlineAccuracyControllerTest extends WebTestCase
{
    use OnlineReviewSeeding;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string PAGE = '/en/online-rounds/' . self::ROUND . '/accuracy';
    private const string FORMULA_NAME = '=HYPERLINK("https://evil.example","Prize")';
    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';
    private const string ANDROID = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheReportShowsEachAdjustmentAndTheirSpread(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->seedConfirmedRound();

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('3', $crawler->filter('[data-accuracy-count]')->attr('data-accuracy-count'));
        self::assertSame('3 confirmed finishes', $crawler->filter('[data-accuracy-count]')->text());
        // −1200, 0, +400 ms: the median is the tap itself, the middle half spans 0.8 s
        self::assertSame('0.0 s', $crawler->filter('[data-accuracy-median]')->text());
        self::assertSame('0.8 s', $crawler->filter('[data-accuracy-iqr]')->text());

        // In finishing order, unconfirmed Arthur last
        self::assertSame(['Olivia Online', self::FORMULA_NAME, 'Paula Pace', 'Arthur Anonymous'], $crawler->filter('[data-accuracy-row] td:first-child')->each(static fn ($cell): string => $cell->text()));
        self::assertSame(['−1.2 s', '+0.4 s', '0.0 s', ''], $crawler->filter('[data-accuracy-adjustment]')->each(static fn ($cell): string => $cell->text()));
        self::assertCount(1, $crawler->filter('[data-accuracy-csv][href="' . self::PAGE . '?format=csv"]'));
    }

    public function testTheCsvIsFormulaSafeAndItsNumbersStayNumbers(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->seedConfirmedRound();

        $browser->request('GET', self::PAGE . '?format=csv');

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('accuracy.csv', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $content = (string) $response->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", substr($content, 3)), static fn (string $line): bool => $line !== '')),
        );

        self::assertSame(OnlineAccuracyController::CSV_COLUMNS, $lines[0]);
        $rows = array_column(array_slice($lines, 1), null, 0);
        self::assertCount(4, $rows);

        // Text a spreadsheet would run as a formula gets the apostrophe
        self::assertArrayHasKey("'" . self::FORMULA_NAME, $rows);
        $formula = $rows["'" . self::FORMULA_NAME];
        self::assertSame('confirmed', $formula[1]);
        self::assertSame((string) ($startMs + 2_000_000), $formula[2]);
        self::assertSame('400', $formula[6]);
        self::assertSame('reviewer', $formula[10]);

        // A negative adjustment is a number, not text with an apostrophe
        self::assertSame('-1200', $rows['Olivia Online'][6]);
        self::assertSame('1000000', $rows['Olivia Online'][3]);
        self::assertSame('998800', $rows['Olivia Online'][5]);
        self::assertSame('3500', $rows['Olivia Online'][11]);
        self::assertSame('998', $rows['Olivia Online'][12]);
        // Detection off: no detection columns filled, no misses counted
        self::assertSame(['', '', ''], array_slice($rows['Olivia Online'], 7, 3));
        self::assertSame(['', ''], array_slice($rows['Olivia Online'], 14, 2));
        self::assertSame(array_merge(['Arthur Anonymous', 'no_finish'], array_fill(0, count(OnlineAccuracyController::CSV_COLUMNS) - 2, '')), $rows['Arthur Anonymous']);
    }

    public function testWithDetectionTheReportMeasuresTheDetectionByPhoneAndView(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->detectionMode(OnlineDetectionMode::On);
        $startMs = $this->startOnlineRound();

        // Olivia: raised her hands, tapped 1.5 s later, confirmed 300 ms after the detected moment - on an iPhone
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $this->phoneEvent($olivia, OnlineEventType::DeviceReady, $startMs - 60_000, ['userAgent' => self::IPHONE, 'detector' => ['state' => 'ready', 'view' => 'angled']]);
        $raiseMs = $startMs + 1_000_000;
        // Once the detector fired when nobody raised their hands - not the finish, not marked, no tap near it
        $this->finishCandidate($olivia, $startMs + 500_000);
        $raiseId = $this->finishCandidate($olivia, $raiseMs);
        $this->evidence($olivia, OnlineMediaKind::Burst, $raiseMs, $raiseId, self::burstManifest($raiseMs));
        $this->finishTap($olivia, $raiseMs + 1_500);
        $this->confirmResult(OnlineRoundsFixture::PARTICIPANT_CONNECTED, (int) round(self::burstManifest($raiseMs)[53]['captureMs']), OnlineConfirmedSource::Reviewer);

        // Paula: the detector missed her raise - only a tap; and once it fired when she was not done - on Android
        $paulaEntry = $this->enterParticipant('Paula Pace');
        $paula = $this->onlineEntryOf($paulaEntry->id->toString());
        $this->phoneEvent($paula, OnlineEventType::DeviceReady, $startMs - 30_000, ['userAgent' => self::ANDROID, 'detector' => ['state' => 'ready', 'view' => 'topDown']], deviceId: 'paula-phone');
        $paulaRaise = $this->finishCandidate($paula, $startMs + 1_500_000, ['view' => 'topDown'], deviceId: 'paula-phone');
        // Tapped on the phone that was ready with its detector watching: a missed raise
        $this->phoneEvent($paula, OnlineEventType::FinishButton, $startMs + 2_000_000, deviceId: 'paula-phone');
        // The reviewer saw the gap on the raise's photo (G - stream RG: a premature raise, not the detector's fault) and
        // confirmed her tap
        $this->markGap($paulaEntry->participant->id->toString(), $paulaRaise);
        $this->confirmResult($paulaEntry->participant->id->toString(), $startMs + 2_000_000, OnlineConfirmedSource::Button);

        // Una: her phone could not run the detector - a tap without a detection is no missed raise (stream P2F); Vic:
        // his detector stopped mid-round as too slow (`detector.changed`) before his tap
        $unaEntry = $this->enterParticipant('Una Unsupported');
        $una = $this->onlineEntryOf($unaEntry->id->toString());
        $this->phoneEvent($una, OnlineEventType::DeviceReady, $startMs - 20_000, ['detector' => ['state' => 'unsupported', 'view' => null, 'setupTest' => null, 'loadMs' => null]], deviceId: 'una-phone');
        $this->phoneEvent($una, OnlineEventType::FinishButton, $startMs + 2_200_000, deviceId: 'una-phone');
        $this->confirmResult($unaEntry->participant->id->toString(), $startMs + 2_200_000, OnlineConfirmedSource::Button);
        $vicEntry = $this->enterParticipant('Vic Slow');
        $vic = $this->onlineEntryOf($vicEntry->id->toString());
        $this->phoneEvent($vic, OnlineEventType::DeviceReady, $startMs - 20_000, ['detector' => ['state' => 'ready', 'view' => 'angled', 'setupTest' => 'passed', 'loadMs' => 900]], deviceId: 'vic-phone');
        $this->phoneEvent($vic, OnlineEventType::DetectorChanged, $startMs + 60_000, ['state' => 'ready', 'running' => false, 'reason' => 'slow', 'view' => null], deviceId: 'vic-phone');
        $vicTap = $this->phoneEvent($vic, OnlineEventType::FinishButton, $startMs + 2_300_000, deviceId: 'vic-phone');
        // His tap's photo showed a gap - a premature tap
        $this->markGap($vicEntry->participant->id->toString(), $vicTap);

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame('on', $crawler->filter('[data-accuracy-detection]')->attr('data-accuracy-detection'));
        self::assertSame('1', $crawler->filter('[data-accuracy-measure="detection_error"] [data-measure-count]')->attr('data-measure-count'));
        self::assertSame('+0.3 s', $crawler->filter('[data-accuracy-measure="detection_error"] [data-measure-median]')->text());
        self::assertSame('1', $crawler->filter('[data-accuracy-measure="delta"] [data-measure-count]')->attr('data-measure-count'));
        self::assertSame('+1.5 s', $crawler->filter('[data-accuracy-measure="delta"] [data-measure-median]')->text());

        $olivia = $crawler->filter('[data-accuracy-row="' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '"]');
        self::assertSame('+0.3 s', $olivia->filter('[data-accuracy-detection-error]')->text());
        self::assertSame('+1.5 s', $olivia->filter('[data-accuracy-delta]')->text());
        self::assertSame('0 missed / 0 premature / 1 false', $olivia->filter('[data-accuracy-misses]')->text());
        self::assertSame('Angled (body)', $olivia->filter('[data-accuracy-view]')->text());
        self::assertSame('iPhone · Safari 18', $olivia->filter('[data-accuracy-device]')->text());

        $paulaRow = $crawler->filter('[data-accuracy-row="' . $paulaEntry->participant->id->toString() . '"]');
        self::assertSame('1 missed / 1 premature / 0 false', $paulaRow->filter('[data-accuracy-misses]')->text());
        self::assertSame('Top-down (hands)', $paulaRow->filter('[data-accuracy-view]')->text());
        self::assertCount(0, $paulaRow->filter('[data-accuracy-not-running-row]'));

        foreach ([$unaEntry, $vicEntry] as $entry) {
            $row = $crawler->filter('[data-accuracy-row="' . $entry->participant->id->toString() . '"]');
            self::assertStringStartsWith('0 missed / 0 premature / 0 false', $row->filter('[data-accuracy-misses]')->text(), $entry->participant->name);
            self::assertSame('Detector not running', $row->filter('[data-accuracy-not-running-row]')->text());
        }

        // The split in the summary (stream RG), and the premature tap
        self::assertSame(['1', '1', '1'], $crawler->filter('[data-accuracy-raises] [data-accuracy-raises-count] dd')->each(static fn ($cell): string => $cell->text()));
        self::assertSame(['missed', 'premature', 'falseDetections'], $crawler->filter('[data-accuracy-raises-count]')->each(static fn ($item): string => (string) $item->attr('data-accuracy-raises-count')));
        self::assertSame('1', $crawler->filter('[data-accuracy-premature-taps]')->attr('data-accuracy-premature-taps'));
        self::assertSame('1 premature tap', $crawler->filter('[data-accuracy-row="' . $vicEntry->participant->id->toString() . '"] [data-accuracy-premature-taps-row]')->text());
        self::assertStringContainsString('premature raise', $crawler->filter('.online-accuracy')->text());
        // ... and in the breakdowns: Android (Paula) the premature raise, iPhone (Olivia) the false detection
        self::assertSame(['1', '0', '0'], $crawler->filter('[data-accuracy-breakdown="device"] [data-accuracy-group-premature]')->each(static fn ($cell): string => $cell->text()));
        self::assertSame(['0', '1', '0'], $crawler->filter('[data-accuracy-breakdown="device"] [data-accuracy-group-false-detections]')->each(static fn ($cell): string => $cell->text()));
        self::assertSame(['0', '1', '0'], $crawler->filter('[data-accuracy-breakdown="view"] [data-accuracy-group-premature]')->each(static fn ($cell): string => $cell->text()), 'angled, top-down, not known');

        self::assertSame('2', $crawler->filter('[data-accuracy-not-running]')->attr('data-accuracy-not-running'));
        self::assertSame(['0', '0', '2'], $crawler->filter('[data-accuracy-breakdown="device"] [data-accuracy-group-not-running]')->each(static fn ($cell): string => $cell->text()), 'the phones without a label');

        self::assertSame(['Android · Chrome 141', 'iPhone · Safari 18', ''], $crawler->filter('[data-accuracy-breakdown="device"] [data-accuracy-group]')->each(static fn ($row): string => (string) $row->attr('data-accuracy-group')));
        self::assertSame(['angled', 'topDown', ''], $crawler->filter('[data-accuracy-breakdown="view"] [data-accuracy-group]')->each(static fn ($row): string => (string) $row->attr('data-accuracy-group')));

        $browser->request('GET', self::PAGE . '?format=csv');
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", substr((string) $browser->getResponse()->getContent(), 3)), static fn (string $line): bool => $line !== '')),
        );
        $rows = array_column(array_slice($lines, 1), null, 0);
        $columns = array_flip(OnlineAccuracyController::CSV_COLUMNS);
        $oliviaCsv = $rows['Olivia Online'];
        self::assertSame((string) $raiseMs, $oliviaCsv[$columns['detected_moment_ms']]);
        self::assertSame('300', $oliviaCsv[$columns['detection_error_ms']]);
        self::assertSame('1500', $oliviaCsv[$columns['delta_ms']]);
        self::assertSame(['0', '1', 'angled', 'iPhone', 'Safari 18'], array_slice($oliviaCsv, $columns['missed_raises'], 5));
        // `false_raises` keeps its meaning (the premature raise without a tap near it counts there) - the split follows
        self::assertSame(['1', '1', 'topDown', 'Android', 'Chrome 141'], array_slice($rows['Paula Pace'], $columns['missed_raises'], 5));
        self::assertSame(['0', '1', '0'], [$oliviaCsv[$columns['premature_raises']], $oliviaCsv[$columns['false_detections']], $oliviaCsv[$columns['premature_taps']]]);
        self::assertSame(['1', '0', '0'], [$rows['Paula Pace'][$columns['premature_raises']], $rows['Paula Pace'][$columns['false_detections']], $rows['Paula Pace'][$columns['premature_taps']]]);
        self::assertSame(['0', '0', '1'], [$rows['Vic Slow'][$columns['premature_raises']], $rows['Vic Slow'][$columns['false_detections']], $rows['Vic Slow'][$columns['premature_taps']]]);
        self::assertSame('0', $oliviaCsv[$columns['detector_not_running']]);
        self::assertSame(['0', '1'], [$rows['Una Unsupported'][$columns['missed_raises']], $rows['Una Unsupported'][$columns['detector_not_running']]]);
        self::assertSame(['0', '1'], [$rows['Vic Slow'][$columns['missed_raises']], $rows['Vic Slow'][$columns['detector_not_running']]]);
        // A new column goes to the end: Phase 3's completeness columns came after detector_not_running, stream RG's after them
        self::assertSame(['detector_not_running', 'completeness_verdict', 'completeness_outcome', 'premature_raises', 'false_detections', 'premature_taps'], array_slice($lines[0], -6));
        self::assertSame(['missed_raises', 'false_raises', 'view'], array_slice($lines[0], 14, 3), 'the existing columns stay where they are');
    }

    public function testARoundWithDetectionOffShowsThePhaseOneReport(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->seedConfirmedRound();
        // G on the taps (stream RG): Olivia's photo at the tap showed a gap (she was confirmed 1.2 s before it - a
        // premature tap); Paula's too, but the reviewer confirmed her at that very tap - the confirmation wins
        $this->markGap(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $this->tapAt($startMs + 1_000_000));
        $paulaId = $this->participantNamed('Paula Pace');
        $this->markGap($paulaId, $this->tapAt($startMs + 2_500_000));

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-accuracy-measure]'));
        self::assertCount(0, $crawler->filter('[data-accuracy-breakdown]'));
        self::assertCount(0, $crawler->filter('[data-accuracy-detection-error]'));
        self::assertCount(0, $crawler->filter('[data-accuracy-raises]'));
        self::assertCount(8, $crawler->filter('.online-accuracy-table thead th'));
        self::assertSame('1', $crawler->filter('[data-accuracy-premature-taps]')->attr('data-accuracy-premature-taps'));
        self::assertSame('1 premature tap', $crawler->filter('[data-accuracy-row="' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '"] [data-accuracy-premature-taps-row]')->text());
        self::assertCount(0, $crawler->filter('[data-accuracy-row="' . $paulaId . '"] [data-accuracy-premature-taps-row]'));

        $browser->request('GET', self::PAGE . '?format=csv');
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", substr((string) $browser->getResponse()->getContent(), 3)), static fn (string $line): bool => $line !== '')),
        );
        $rows = array_column(array_slice($lines, 1), null, 0);
        $columns = array_flip(OnlineAccuracyController::CSV_COLUMNS);
        // Detection off: no raises counted, the premature taps are (none without a tap)
        self::assertSame(['', '', '1'], [$rows['Olivia Online'][$columns['premature_raises']], $rows['Olivia Online'][$columns['false_detections']], $rows['Olivia Online'][$columns['premature_taps']]]);
        self::assertSame('0', $rows['Paula Pace'][$columns['premature_taps']]);
        self::assertSame('', $rows['Arthur Anonymous'][$columns['premature_taps']]);
    }

    /**
     * The rehearsal of Test Cup #1 (2026-10-14): a participant confirmed as unfinished (U - the pieces, no moment) read
     * "a frame picked in the review" as the source, in the report, its CSV and the review's own line. An unfinished
     * confirmation has no moment, so it names no source.
     */
    public function testAnUnfinishedConfirmationNamesNoSource(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->seedConfirmedRound();
        $this->endOnlineRound();

        $quinnEntry = $this->enterParticipant('Quinn Unfinished');
        $quinn = $this->onlineEntryOf($quinnEntry->id->toString());
        $this->phoneEvent($quinn, OnlineEventType::TimeUpPieces, $startMs + 1_790_000, ['pieces' => 37, 'measure' => 'left']);
        $quinnId = $quinnEntry->participant->id->toString();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ConfirmOnlineResult(
            self::COMPETITION,
            self::ROUND,
            $quinnId,
            null,
            OnlineConfirmedSource::Reviewer,
            463,
            PlayerFixture::PLAYER_ADMIN,
            RoundEntryResult::none(),
        ));

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-accuracy-row="' . $quinnId . '"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('463', $row->text());
        self::assertStringNotContainsString('frame picked', $row->text());
        // A frame the reviewer did pick still says so
        self::assertStringContainsString('a frame picked in the review', $crawler->filter('[data-accuracy-row="' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '"]')->text());

        $browser->request('GET', self::PAGE . '?format=csv');
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", substr((string) $browser->getResponse()->getContent(), 3)), static fn (string $line): bool => $line !== '')),
        );
        $columns = array_flip(OnlineAccuracyController::CSV_COLUMNS);
        $rows = array_column(array_slice($lines, 1), null, 0);
        self::assertSame('', $rows['Quinn Unfinished'][$columns['source']]);
        self::assertSame('reviewer', $rows['Olivia Online'][$columns['source']]);

        // The review's own line ("Confirmed from …") reads the same field
        $browser->request('GET', '/en/online-rounds/' . self::ROUND . '/review/' . $quinnId, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        /** @var array{entry: array{status: string, confirmed: mixed}} $detail */
        $detail = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('confirmed', $detail['entry']['status']);
        $confirmed = $detail['entry']['confirmed'];
        self::assertIsArray($confirmed);
        self::assertArrayHasKey('momentMs', $confirmed);
        self::assertArrayHasKey('source', $confirmed);
        self::assertNull($confirmed['momentMs']);
        self::assertNull($confirmed['source']);
    }

    public function testOnlyTheEventsOrganisersWithTheFeatureSeeIt(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', self::PAGE . '?format=csv');
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Olivia confirmed 1.2 s before her tap (a frame), a participant with a formula for a name 0.4 s after it, Paula at
     * her tap; Arthur's phone never sent anything.
     *
     * @return int the round's start
     */
    private function seedConfirmedRound(): int
    {
        $startMs = $this->startOnlineRound();

        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapMs = $startMs + 1_000_000;
        $tapId = $this->finishTap($olivia, $tapMs, $tapMs + 3_500);
        $this->evidence($olivia, OnlineMediaKind::Burst, $tapMs, $tapId, self::burstManifest($tapMs));
        $this->confirmResult(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $tapMs - 1_200, OnlineConfirmedSource::Reviewer);

        $formulaEntry = $this->enterParticipant(self::FORMULA_NAME);
        $formula = $this->onlineEntryOf($formulaEntry->id->toString());
        $formulaTapMs = $startMs + 2_000_000;
        $formulaTapId = $this->finishTap($formula, $formulaTapMs);
        $this->evidence($formula, OnlineMediaKind::Burst, $formulaTapMs, $formulaTapId, self::burstManifest($formulaTapMs));
        $this->confirmResult($formulaEntry->participant->id->toString(), $formulaTapMs + 400, OnlineConfirmedSource::Reviewer);

        $paulaEntry = $this->enterParticipant('Paula Pace');
        $paula = $this->onlineEntryOf($paulaEntry->id->toString());
        $paulaTapMs = $startMs + 2_500_000;
        $this->finishTap($paula, $paulaTapMs);
        $this->confirmResult($paulaEntry->participant->id->toString(), $paulaTapMs, OnlineConfirmedSource::Button);

        return $startMs;
    }

    /**
     * The reviewer's G on a moment (stream RG)
     */
    private function markGap(string $participantId, string $eventId): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MarkOnlineMomentGap(self::COMPETITION, self::ROUND, $participantId, $eventId, PlayerFixture::PLAYER_ADMIN));
    }

    private function tapAt(int $momentMs): string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne("SELECT id::text FROM online_entry_event WHERE type = 'finish.button' AND client_ms = :moment", ['moment' => $momentMs]);
        self::assertIsString($id);

        return $id;
    }

    private function participantNamed(string $name): string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne('SELECT id::text FROM competition_participant WHERE name = :name', ['name' => $name]);
        self::assertIsString($id);

        return $id;
    }

    private function confirmResult(string $participantId, int $momentMs, OnlineConfirmedSource $source): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ConfirmOnlineResult(
            self::COMPETITION,
            self::ROUND,
            $participantId,
            $momentMs,
            $source,
            null,
            PlayerFixture::PLAYER_ADMIN,
            // Nothing official yet for any of them
            RoundEntryResult::none(),
        ));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);
        $browser->disableReboot();

        return $browser;
    }
}
