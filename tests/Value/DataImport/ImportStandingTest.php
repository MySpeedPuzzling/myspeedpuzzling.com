<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use DateInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportStanding;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * docs/features/data-import/upload.md "Finished": which imports hold one of the player's places, and which the daily
 * sweep finishes.
 */
final class ImportStandingTest extends TestCase
{
    /**
     * @return iterable<string, array{DataImportStatus, bool, bool, bool, bool, int, bool, bool}>
     */
    public static function standings(): iterable
    {
        // status, took rows, working, has ready, has waiting, hours since the last activity → holds a place, finished by the sweep
        yield 'being read' => [DataImportStatus::Reading, false, false, false, false, 960, true, false];
        yield 'reading failed' => [DataImportStatus::ReadFailed, false, false, false, false, 960, true, false];
        yield 'set up, nothing added, nothing to do' => [DataImportStatus::SettingUp, false, false, false, false, 960, true, false];
        yield 'added everything a moment ago' => [DataImportStatus::SettingUp, true, false, false, false, 1, false, false];
        yield 'added everything two days ago' => [DataImportStatus::SettingUp, true, false, false, false, 48, false, true];
        yield 'added, rows ready' => [DataImportStatus::SettingUp, true, false, true, false, 960, true, false];
        yield 'added, rows ready and waiting' => [DataImportStatus::SettingUp, true, false, true, true, 960, true, false];
        yield 'added, waiting for the player for 6 days' => [DataImportStatus::SettingUp, true, false, false, true, 144, true, false];
        yield 'added, waiting for the player for 8 days' => [DataImportStatus::SettingUp, true, false, false, true, 192, true, true];
        yield 'adding right now' => [DataImportStatus::SettingUp, true, true, false, false, 192, true, false];
        yield 'finished' => [DataImportStatus::Done, true, false, false, true, 192, false, false];
        yield 'discarded' => [DataImportStatus::Discarded, false, false, false, false, 192, false, false];
    }

    #[DataProvider('standings')]
    public function testWhatHoldsAPlaceAndWhatTheSweepFinishes(DataImportStatus $status, bool $tookRows, bool $working, bool $hasReady, bool $hasWaiting, int $hoursAtRest, bool $holdsAPlace, bool $finishedBySweep): void
    {
        $standing = new ImportStanding($status, $tookRows, $working, $hasReady, $hasWaiting, TestClock::now()->sub(new DateInterval('PT' . $hoursAtRest . 'H')));

        self::assertSame($holdsAPlace, $standing->holdsAPlace());
        self::assertSame($finishedBySweep, $standing->isFinishedBySweep(TestClock::now()));
    }
}
