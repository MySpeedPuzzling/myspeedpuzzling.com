<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * When the camera scanner hands a code over (assets/barcode_acceptance.js, docs/features/barcode-scanner/README.md
 * §5.7): Google's reader and zbar side by side on Android. A wrong code handed over finds the wrong puzzle, a code
 * handed over late makes scanning feel broken - both are pinned here. Runs the real module under node.
 */
final class BarcodeAcceptanceScriptTest extends TestCase
{
    private const string RAVENSBURGER = '4005555017585';
    // Google's parity misread of it, reported as 12 UPC-A digits (Vanja's Galaxy S25, 2026-10-18)
    private const string MISREAD = '045555017585';
    private const string OTHER_BOX = '5900511100331';

    public function testGooglesReaderAloneNeedsTenReads(): void
    {
        $result = $this->accept(['native'], self::reads('native', self::RAVENSBURGER, 10));

        self::assertSame([...array_fill(0, 9, null), 'native'], $result['results']);
        self::assertSame(['native' => 900], $result['solo']);
    }

    public function testZbarAloneNeedsFourGoodReadsAndEightForCodesStartingWithZero(): void
    {
        self::assertSame([null, null, null, 'zbar'], $this->accept(['zbar'], self::reads('zbar', self::RAVENSBURGER, 4))['results']);
        self::assertSame([...array_fill(0, 7, null), 'zbar'], $this->accept(['zbar'], self::reads('zbar', '0045555017585', 8))['results']);
        // A read only two scanlines agree on counts for nothing
        self::assertSame(array_fill(0, 6, null), $this->accept(['zbar'], self::reads('zbar', self::RAVENSBURGER, 6, quality: 2))['results']);
    }

    public function testBothReadersAgreeingTwiceAcceptAtOnce(): void
    {
        $result = $this->accept(['native', 'zbar'], self::alternating(self::RAVENSBURGER, self::RAVENSBURGER, 2));

        self::assertSame([null, null, null, 'both'], $result['results']);
        // Neither would have accepted alone yet
        self::assertSame(['native' => null, 'zbar' => null], $result['solo']);
    }

    /**
     * Vanja's session: Google's reader now and then returns the shortened misread among the right reads. zbar never
     * saw it, so it does not hold up the code both readers agree on.
     */
    public function testAStrayMisreadOfOneReaderDoesNotHoldUpAnAgreedCode(): void
    {
        $reads = [
            ['reader' => 'native', 'code' => self::MISREAD, 'at' => 0],
            ['reader' => 'native', 'code' => self::RAVENSBURGER, 'at' => 100],
            ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 40, 'at' => 150],
            ['reader' => 'native', 'code' => self::RAVENSBURGER, 'at' => 200],
            ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 40, 'at' => 250],
        ];

        self::assertSame([null, null, null, null, 'both'], $this->accept(['native', 'zbar'], $reads)['results']);
    }

    /**
     * The other way round: Google's reader repeats its misread ten times, zbar reads the box right - the misread
     * is never handed over while zbar contradicts it.
     */
    public function testZbarContradictingGooglesReaderStopsItsMisread(): void
    {
        $reads = [];
        for ($i = 0; $i < 10; $i++) {
            $reads[] = ['reader' => 'native', 'code' => self::MISREAD, 'at' => $i * 100];
            if ($i % 3 === 0) {
                $reads[] = ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 1, 'at' => $i * 100 + 50];
            }
        }
        // zbar reads too weak to count (one scanline) do not stop it - only reads that count do
        self::assertContains('native', $this->accept(['native', 'zbar'], $reads)['results']);

        $reads = [];
        for ($i = 0; $i < 10; $i++) {
            $reads[] = ['reader' => 'native', 'code' => self::MISREAD, 'at' => $i * 100];
            if ($i === 8) {
                $reads[] = ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 30, 'at' => $i * 100 + 50];
            }
        }
        self::assertNotContains('native', $this->accept(['native', 'zbar'], $reads)['results']);
    }

    /**
     * A phone whose reader misreads the box on every frame: zbar reads it right and is not blocked by the misread
     * (a deadlock found in the browser run - neither would ever have been accepted).
     */
    public function testZbarReadsTheBoxWhileGooglesReaderKeepsMisreadingIt(): void
    {
        $reads = [];
        for ($i = 0; $i < 4; $i++) {
            $reads[] = ['reader' => 'native', 'code' => self::MISREAD, 'at' => $i * 100];
            $reads[] = ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 30, 'at' => $i * 100 + 50];
        }

        self::assertSame([...array_fill(0, 7, null), 'zbar'], $this->accept(['native', 'zbar'], $reads)['results']);
    }

    /**
     * A US box: Google's reader says 12 digits, zbar 13 for the same code - one code, not two blocking each other.
     */
    public function testTwelveAndThirteenDigitFormsOfOneCodeAgree(): void
    {
        $result = $this->accept(['native', 'zbar'], self::alternating('019962198588', '0019962198588', 2), canonical: ['019962198588', '4005555017585', '12345678']);

        self::assertSame([null, null, null, 'both'], $result['results']);
        self::assertSame(['0019962198588', '4005555017585', '12345678'], $result['canonical']);
    }

    public function testTwoBoxesSeenByBothReadersAreNeverAgreedOn(): void
    {
        $reads = [
            ['reader' => 'native', 'code' => self::OTHER_BOX, 'at' => 0],
            ['reader' => 'zbar', 'code' => self::OTHER_BOX, 'quality' => 30, 'at' => 10],
            ...self::alternating(self::RAVENSBURGER, self::RAVENSBURGER, 3, startAt: 100),
        ];

        self::assertSame(array_fill(0, 8, null), $this->accept(['native', 'zbar'], $reads)['results']);
    }

    public function testAFrameWithTwoCodesBlocksBothForTwoSeconds(): void
    {
        $reads = [
            ['reader' => 'zbar', 'together' => [self::RAVENSBURGER, self::OTHER_BOX], 'at' => 0],
            ...self::reads('zbar', self::RAVENSBURGER, 4, startAt: 100),
            ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 30, 'at' => 2100],
        ];

        self::assertSame([null, null, null, null, null, 'zbar'], $this->accept(['zbar'], $reads)['results']);
    }

    public function testAgreementNeedsBothReadsRecent(): void
    {
        $reads = [
            ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 30, 'at' => 0],
            ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 30, 'at' => 100],
            ['reader' => 'native', 'code' => self::RAVENSBURGER, 'at' => 5000],
            ['reader' => 'native', 'code' => self::RAVENSBURGER, 'at' => 5100],
        ];

        self::assertSame([null, null, null, null], $this->accept(['native', 'zbar'], $reads)['results']);
    }

    /**
     * A misread now and then over a long session never adds up: only reads of the last 3 s count.
     */
    public function testReadsSpreadOverALongSessionNeverAddUp(): void
    {
        $reads = [];
        for ($i = 0; $i < 12; $i++) {
            $reads[] = ['reader' => 'native', 'code' => self::MISREAD, 'at' => $i * 2500];
        }

        self::assertSame(array_fill(0, 12, null), $this->accept(['native'], $reads)['results']);
        self::assertSame(array_fill(0, 12, null), $this->accept(['zbar'], array_map(static fn (array $read): array => ['reader' => 'zbar', 'code' => self::RAVENSBURGER, 'quality' => 30, 'at' => $read['at']], $reads))['results']);
    }

    public function testAnAcceptedCodeStartsCountingAgain(): void
    {
        $reads = [...self::reads('zbar', self::RAVENSBURGER, 4), ...self::reads('zbar', self::RAVENSBURGER, 4, startAt: 1000)];

        self::assertSame([null, null, null, 'zbar', null, null, null, 'zbar'], $this->accept(['zbar'], $reads)['results']);
    }

    public function testZbarWaitsForGooglesReaderToSetItsPaceAndTakesAQuarterOfTheTime(): void
    {
        $result = $this->budget([
            ['may', 0],
            ['p', 80], ['p', 90], ['p', 80], ['p', 85],
            ['may', 0],
            ['p', 80],
            ['may', 0],
            // zbar's crop + decode took 150 ms, done at 1000: next at 1000 + 3 x 150
            ['h', 150, 1000],
            ['may', 1449],
            ['may', 1450],
        ]);

        self::assertSame([false, null, null, null, null, false, null, true, null, false, true], $result['answers']);
        self::assertSame(80, $result['baselineMs']);
    }

    /**
     * The Galaxy A33 of 2026-10-18: Google's reader went from 83 to 418 ms a frame beside zbar. zbar steps back
     * until it keeps its pace, and comes back once it does.
     */
    public function testZbarStepsBackWhileGooglesReaderIsSlowedDownAndReturnsAfterwards(): void
    {
        $slowed = [['p', 83], ['p', 80], ['p', 85], ['p', 83], ['p', 83]];
        for ($i = 0; $i < 4; $i++) {
            $slowed = [...$slowed, ['p', 418], ['p', 400], ['p', 420], ['p', 410], ['p', 405]];
        }
        $result = $this->budget([...$slowed, ['h', 200, 10000], ['may', 10000 + 200 * 3 * 16 - 1], ['may', 10000 + 200 * 3 * 16]]);

        self::assertSame(16, $result['backoff']);
        self::assertSame(16, $result['maxBackoff']);
        self::assertSame([false, true], array_slice($result['answers'], -2));

        // Two good windows are not enough to come back, the third is
        $good = array_fill(0, 5, ['p', 88]);
        self::assertSame(16, $this->budget([...$slowed, ...$good, ...$good])['backoff']);
        $result = $this->budget([...$slowed, ...$good, ...$good, ...$good]);
        self::assertSame(8, $result['backoff']);
        self::assertSame(16, $result['maxBackoff']);
    }

    /**
     * One slow frame in five - zbar running now and then beside it - still counts: the average decides.
     */
    public function testOccasionalSlowFramesCountThroughTheAverage(): void
    {
        $events = [['p', 40], ['p', 40], ['p', 40], ['p', 40], ['p', 40], ['p', 390], ['p', 40], ['p', 40], ['p', 40], ['p', 40]];

        self::assertSame(2, $this->budget($events)['backoff']);
    }

    public function testAFewMillisecondsMoreOnAFastPhoneAreNoReasonToStepBack(): void
    {
        $result = $this->budget([['p', 20], ['p', 20], ['p', 20], ['p', 20], ['p', 20], ['p', 45], ['p', 48], ['p', 46], ['p', 44], ['p', 47]]);

        self::assertSame(1, $result['backoff']);
    }

    /**
     * @param list<array{0: string, 1: int, 2?: int}> $events
     * @return array{answers: list<null|bool>, baselineMs: null|int, backoff: int, maxBackoff: int}
     */
    private function budget(array $events): array
    {
        /** @var array{answers: list<null|bool>, baselineMs: null|int, backoff: int, maxBackoff: int} $result */
        $result = $this->harness([['budget' => $events]])[0];

        return $result;
    }

    /**
     * @return list<array{reader: string, code: string, quality?: int, at: int}>
     */
    private static function reads(string $reader, string $code, int $count, int $startAt = 0, int $quality = 30): array
    {
        $reads = [];
        for ($i = 0; $i < $count; $i++) {
            $read = ['reader' => $reader, 'code' => $code, 'at' => $startAt + $i * 100];
            if ($reader === 'zbar') {
                $read['quality'] = $quality;
            }
            $reads[] = $read;
        }

        return $reads;
    }

    /**
     * native, zbar, native, zbar, …
     *
     * @return list<array{reader: string, code: string, quality?: int, at: int}>
     */
    private static function alternating(string $nativeCode, string $zbarCode, int $eachCount, int $startAt = 0): array
    {
        $reads = [];
        for ($i = 0; $i < $eachCount; $i++) {
            $reads[] = ['reader' => 'native', 'code' => $nativeCode, 'at' => $startAt + $i * 100];
            $reads[] = ['reader' => 'zbar', 'code' => $zbarCode, 'quality' => 30, 'at' => $startAt + $i * 100 + 50];
        }

        return $reads;
    }

    /**
     * @param list<string> $readers
     * @param list<array<string, mixed>> $reads
     * @param list<string> $canonical
     * @return array{results: list<null|string>, solo: array<string, null|int>, canonical: list<string>}
     */
    private function accept(array $readers, array $reads, array $canonical = []): array
    {
        /** @var array{results: list<null|string>, solo: array<string, null|int>, canonical: list<string>} $result */
        $result = $this->harness([['readers' => $readers, 'reads' => $reads, 'canonical' => $canonical]])[0];

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<mixed>
     */
    private function harness(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/barcode-acceptance-harness.mjs']);
        $process->setInput(json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
