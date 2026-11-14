<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * docs/features/photo-uploads/README.md "A photo the phone cannot read" - the decisions of assets/photo_readability.js,
 * run as the real module under node:
 *
 * - a photo of 0 bytes, or one the browser refuses to read, is recognised - and nothing else is: a slow read, a check
 *   that misbehaves or a browser without the API leave the photo alone (what the form did before)
 * - a save that died at once with a photo in it may be the connection or the photo: both are said, the photo is named
 *   only when it happens again at once; everything the old message was written for keeps it
 * - "Save without the photo" never takes the photo a new puzzle needs
 * - what the telemetry says about an error carries no file name, path or address
 * - none of it ever throws
 */
final class PhotoReadabilityScriptTest extends TestCase
{
    public function testAPhotoOfNoBytesIsEmpty(): void
    {
        [$empty, $normal, $nothing, $noSize, $probedEmpty, $realEmptyBlob] = $this->answers([
            ['fn' => 'empty', 'file' => ['size' => 0]],
            ['fn' => 'empty', 'file' => ['size' => 812_345]],
            ['fn' => 'empty', 'file' => null],
            ['fn' => 'empty', 'file' => ['size' => null]],
            // Never read: a file without bytes has no first byte to ask for
            ['fn' => 'probe', 'file' => ['size' => 0, 'read' => 'hangs']],
            ['fn' => 'probe', 'file' => ['blob' => '']],
        ]);

        self::assertTrue($empty);
        self::assertFalse($normal);
        self::assertFalse($nothing);
        self::assertFalse($noSize, 'A size the browser does not tell is not "empty"');
        self::assertSame('empty', self::verdict($probedEmpty)['unreadable']);
        self::assertLessThan(40, self::tookMs($probedEmpty));
        self::assertSame('empty', self::verdict($realEmptyBlob)['unreadable']);
    }

    public function testANormalPhotoIsReadable(): void
    {
        [$fake, $realBlob] = $this->answers([
            ['fn' => 'probe', 'file' => ['size' => 812_345, 'read' => 'ok']],
            ['fn' => 'probe', 'file' => ['blob' => base64_encode(ExifPhotos::jpeg(40, 30, null))]],
        ]);

        self::assertSame(['unreadable' => null, 'error' => null], self::verdict($fake));
        self::assertSame(['unreadable' => null, 'error' => null], self::verdict($realBlob));
    }

    public function testAPhotoTheBrowserRefusesToReadIsUnreadable(): void
    {
        [$refused, $gone, $noBytes] = $this->answers([
            ['fn' => 'probe', 'file' => ['size' => 2_000_000, 'read' => 'rejects']],
            ['fn' => 'probe', 'file' => ['size' => 2_000_000, 'read' => 'rejects', 'name' => 'Holiday 2026.jpg', 'error' => ['name' => 'NotFoundError', 'message' => 'A requested file Holiday 2026.jpg could not be found at /storage/emulated/0/DCIM/Camera/Holiday 2026.jpg']]],
            // Says it has bytes and gives none
            ['fn' => 'probe', 'file' => ['size' => 2_000_000, 'read' => 'no-bytes']],
        ]);

        self::assertSame(['unreadable' => 'unreadable', 'error' => 'NotReadableError: The requested file could not be read'], self::verdict($refused));

        $goneError = self::verdict($gone)['error'];
        self::assertSame('unreadable', self::verdict($gone)['unreadable']);
        self::assertIsString($goneError);
        self::assertStringStartsWith('NotFoundError: ', $goneError);
        self::assertStringNotContainsString('Holiday', $goneError);
        self::assertStringNotContainsString('DCIM', $goneError);

        self::assertSame(['unreadable' => 'unreadable', 'error' => 'EmptyRead'], self::verdict($noBytes));
    }

    /**
     * The form must behave as it always did whenever the check cannot tell
     */
    public function testAReadThatDoesNotFinishOrACheckThatMisbehavesLeavesThePhotoAlone(): void
    {
        $answers = $this->answers([
            'a read that never finishes' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'hangs'], 'timeoutMs' => 60],
            'a read slower than the timeout' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'slow', 'afterMs' => 300], 'timeoutMs' => 40],
            'slice() throws' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'slice-throws']],
            'arrayBuffer() throws' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'throws']],
            'arrayBuffer() gives no promise' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'not-a-promise']],
            'no arrayBuffer() at all' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'no-array-buffer']],
            'no slice() at all' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'no-slice']],
            'the read answers garbage' => ['fn' => 'probe', 'file' => ['size' => 5_000_000, 'read' => 'garbage']],
            'no file' => ['fn' => 'probe', 'file' => null],
        ]);

        foreach ($answers as $label => $answer) {
            self::assertIsArray($answer, (string) $label);
            self::assertArrayNotHasKey('rejected', $answer, (string) $label);
            self::assertSame(['unreadable' => null, 'error' => null], self::verdict($answer), (string) $label);
        }

        // Bounded: the form never waits for a device that does not answer
        self::assertGreaterThanOrEqual(50, self::tookMs($answers['a read that never finishes']));
        self::assertLessThan(250, self::tookMs($answers['a read that never finishes']));
        self::assertLessThan(250, self::tookMs($answers['a read slower than the timeout']));
    }

    /**
     * A save that dies at once with a photo in it is also what a broken connection looks like (the first request after
     * the camera, a network change): the first time both are said, only the second time in a row the photo is named
     */
    public function testASaveThatDiedAtOnceWithAPhotoNamesThePhotoOnlyWhenItHappensAgain(): void
    {
        $answers = $this->answers([
            // 2026-11-14: status 0 after 30-80 ms, online, the photo in the form
            'the incident, first tap' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => true, 'photosInForm' => 1]],
            'the incident, second tap' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => true, 'photosInForm' => 1, 'earlierInstantFailures' => 1]],
            'the incident, eleventh tap' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 60, 'online' => true, 'photosInForm' => 1, 'earlierInstantFailures' => 10]],
            'just under a second, first' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 999, 'online' => true, 'photosInForm' => 2]],
            'just under a second, again' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 999, 'online' => true, 'photosInForm' => 2, 'earlierInstantFailures' => 1]],
            // Neither at once nor slow: both are named, however often
            'a few seconds' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 3500, 'online' => true, 'photosInForm' => 1]],
            'a few seconds, after an instant one' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 3500, 'online' => true, 'photosInForm' => 1, 'earlierInstantFailures' => 1]],
            'duration unknown' => ['fn' => 'failure', 'save' => ['status' => 0, 'online' => true, 'photosInForm' => 1, 'earlierInstantFailures' => 3]],
            'duration garbage' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 'soon', 'online' => true, 'photosInForm' => 1]],
            // What the message was written for - whatever happened before
            'slow, then dropped' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 42_000, 'online' => true, 'photosInForm' => 1, 'earlierInstantFailures' => 2]],
            'offline' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 20, 'online' => false, 'photosInForm' => 1, 'earlierInstantFailures' => 2]],
            'the edge gave up on a slow upload' => ['fn' => 'failure', 'save' => ['status' => 504, 'durationMs' => 60_000, 'online' => true, 'photosInForm' => 2]],
            'a deploy moment' => ['fn' => 'failure', 'save' => ['status' => 502, 'durationMs' => 300, 'online' => true, 'photosInForm' => 1, 'earlierInstantFailures' => 1]],
            'no photo in the request' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => true, 'photosInForm' => 0, 'earlierInstantFailures' => 1]],
            'answered 200 and dropped by Turbo' => ['fn' => 'failure', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => true, 'photosInForm' => 1, 'discarded' => true, 'earlierInstantFailures' => 1]],
            'nothing known' => ['fn' => 'failure', 'save' => null],
        ]);

        self::assertSame([
            'the incident, first tap' => 'either',
            'the incident, second tap' => 'photo',
            'the incident, eleventh tap' => 'photo',
            'just under a second, first' => 'either',
            'just under a second, again' => 'photo',
            'a few seconds' => 'either',
            'a few seconds, after an instant one' => 'either',
            'duration unknown' => 'either',
            'duration garbage' => 'either',
            'slow, then dropped' => 'network',
            'offline' => 'network',
            'the edge gave up on a slow upload' => 'network',
            'a deploy moment' => 'network',
            'no photo in the request' => 'network',
            'answered 200 and dropped by Turbo' => 'network',
            'nothing known' => 'network',
        ], $answers);
    }

    /**
     * What counts towards "again": only a failure at once, online, with a photo in the request
     */
    public function testOnlyAnInstantFailureWithAPhotoCountsTowardsNamingThePhoto(): void
    {
        $answers = $this->answers([
            'at once, with a photo' => ['fn' => 'instant', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => true, 'photosInForm' => 1]],
            'after a few seconds' => ['fn' => 'instant', 'save' => ['status' => 0, 'durationMs' => 3500, 'online' => true, 'photosInForm' => 1]],
            'offline' => ['fn' => 'instant', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => false, 'photosInForm' => 1]],
            'an answer came' => ['fn' => 'instant', 'save' => ['status' => 502, 'durationMs' => 45, 'online' => true, 'photosInForm' => 1]],
            'no photo in the request' => ['fn' => 'instant', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => true, 'photosInForm' => 0]],
            'dropped by Turbo' => ['fn' => 'instant', 'save' => ['status' => 0, 'durationMs' => 45, 'online' => true, 'photosInForm' => 1, 'discarded' => true]],
            'duration unknown' => ['fn' => 'instant', 'save' => ['status' => 0, 'online' => true, 'photosInForm' => 1]],
            'nothing known' => ['fn' => 'instant', 'save' => null],
        ]);

        self::assertSame([
            'at once, with a photo' => true,
            'after a few seconds' => false,
            'offline' => false,
            'an answer came' => false,
            'no photo in the request' => false,
            'dropped by Turbo' => false,
            'duration unknown' => false,
            'nothing known' => false,
        ], $answers);
    }

    /**
     * "Save without the photo" must not promise what cannot work: a new puzzle needs its box photo, and its finished
     * photo stands in for it when there is no other
     */
    public function testSaveWithoutThePhotoNeverTakesTheNewPuzzlesPhoto(): void
    {
        $answers = $this->answers([
            'a result: every photo may go' => ['fn' => 'without', 'form' => ['inForm' => ['finishedPuzzlesPhoto']]],
            'a result, hidden box field still holding a file' => ['fn' => 'without', 'form' => ['inForm' => ['finishedPuzzlesPhoto', 'puzzlePhoto'], 'newPuzzle' => false, 'boxPhotoAvailable' => true]],
            'new puzzle, box photo in the request' => ['fn' => 'without', 'form' => ['inForm' => ['puzzlePhoto'], 'newPuzzle' => true, 'boxPhotoAvailable' => true]],
            'new puzzle, both in the request' => ['fn' => 'without', 'form' => ['inForm' => ['finishedPuzzlesPhoto', 'puzzlePhoto'], 'newPuzzle' => true, 'boxPhotoAvailable' => true]],
            'new puzzle, box photo uploaded' => ['fn' => 'without', 'form' => ['inForm' => ['finishedPuzzlesPhoto'], 'newPuzzle' => true, 'boxPhotoAvailable' => true]],
            'new puzzle, the finished photo is its only photo' => ['fn' => 'without', 'form' => ['inForm' => ['finishedPuzzlesPhoto'], 'newPuzzle' => true, 'boxPhotoAvailable' => false]],
            'garbage' => ['fn' => 'without', 'form' => ['inForm' => 'all of them', 'newPuzzle' => true]],
            'nothing known' => ['fn' => 'without', 'form' => null],
        ]);

        self::assertSame([
            'a result: every photo may go' => ['drop' => ['finishedPuzzlesPhoto'], 'required' => false],
            'a result, hidden box field still holding a file' => ['drop' => ['finishedPuzzlesPhoto', 'puzzlePhoto'], 'required' => false],
            'new puzzle, box photo in the request' => ['drop' => [], 'required' => true],
            'new puzzle, both in the request' => ['drop' => ['finishedPuzzlesPhoto'], 'required' => false],
            'new puzzle, box photo uploaded' => ['drop' => ['finishedPuzzlesPhoto'], 'required' => false],
            'new puzzle, the finished photo is its only photo' => ['drop' => [], 'required' => true],
            'garbage' => ['drop' => [], 'required' => true],
            'nothing known' => ['drop' => [], 'required' => false],
        ], $answers);
    }

    /**
     * assets/image_compression.js: a shrunk copy of 0 bytes (a canvas that gave back nothing) never replaces the photo
     */
    public function testAnEmptyShrunkCopyNeverReplacesThePhoto(): void
    {
        $answers = $this->answers([
            'smaller' => ['fn' => 'smaller', 'original' => 5_000_000, 'compressed' => 600_000],
            'empty' => ['fn' => 'smaller', 'original' => 5_000_000, 'compressed' => 0],
            'larger' => ['fn' => 'smaller', 'original' => 600_000, 'compressed' => 700_000],
            'the same size' => ['fn' => 'smaller', 'original' => 600_000, 'compressed' => 600_000],
            'none' => ['fn' => 'smaller', 'original' => 600_000, 'compressed' => null],
        ]);

        self::assertSame(['smaller' => 'compressed', 'empty' => 'original', 'larger' => 'original', 'the same size' => 'original', 'none' => 'original'], $answers);
    }

    public function testAnErrorIsDescribedWithoutFileNamesPathsOrAddresses(): void
    {
        $answers = $this->answers([
            ['fn' => 'describe', 'error' => ['name' => 'TypeError', 'message' => 'Failed to fetch']],
            ['fn' => 'describe', 'error' => ['name' => 'NotReadableError', 'message' => 'The requested file could not be read, typically due to permission problems that have occurred after a reference to a file was acquired.']],
            ['fn' => 'describe', 'error' => ['name' => 'TypeError', 'message' => 'Could not read IMG_20261114_101500.jpg'], 'hide' => ['IMG_20261114_101500.jpg']],
            ['fn' => 'describe', 'error' => ['name' => 'Error', 'message' => "Not allowed to load local resource: file:///storage/emulated/0/DCIM/a.jpg and\nblob:https://example.com/0b1c or C:\\Users\\Someone\\Pictures\\b.jpg or /data/user/0/cache/c.jpg"]],
            ['fn' => 'describe', 'error' => ['name' => 'TypeError', 'message' => str_repeat('x', 500)]],
            ['fn' => 'describe', 'error' => ['name' => '', 'message' => '']],
            ['fn' => 'describe', 'raw' => null],
            ['fn' => 'describe', 'raw' => 'Load failed'],
            ['fn' => 'describe', 'raw' => 42],
            ['fn' => 'describe', 'raw' => ['name' => ['not', 'text'], 'message' => 7]],
        ]);

        self::assertSame('TypeError: Failed to fetch', $answers[0]);
        self::assertSame('NotReadableError: The requested file could not be read, typically due to permission problems that have occurred after a reference to a file was acquired.', $answers[1]);
        self::assertSame('TypeError: Could not read [file]', $answers[2]);
        self::assertSame('Error: Not allowed to load local resource: [url] and [url] or [path] or [path]', $answers[3]);
        self::assertIsString($answers[4]);
        self::assertSame(200, strlen($answers[4]));
        self::assertNull($answers[5]);
        self::assertNull($answers[6]);
        self::assertSame('Load failed', $answers[7]);
        self::assertNull($answers[8]);
        self::assertNull($answers[9]);
    }

    public function testTheUploadStartsRefusalIsReadWithoutEverHoldingTheUploadUp(): void
    {
        $answers = $this->answers([
            ['fn' => 'refusal', 'response' => ['body' => ['error' => 'empty']]],
            ['fn' => 'refusal', 'response' => ['body' => ['error' => 'too_large']]],
            ['fn' => 'refusal', 'response' => ['body' => ['error' => ['empty']]]],
            ['fn' => 'refusal', 'response' => ['body' => 'empty']],
            ['fn' => 'refusal', 'response' => ['body' => null]],
            ['fn' => 'refusal', 'response' => ['body' => 'rejects']],
            ['fn' => 'refusal', 'response' => ['body' => 'throws']],
            ['fn' => 'refusal', 'response' => ['body' => 'hangs']],
        ]);

        self::assertSame(['empty', 'too_large', null, null, null, null, null, null], $answers);
    }

    /**
     * @return array{unreadable: mixed, error: mixed}
     */
    private static function verdict(mixed $answer): array
    {
        self::assertIsArray($answer);

        return ['unreadable' => $answer['unreadable'] ?? null, 'error' => $answer['error'] ?? null];
    }

    private static function tookMs(mixed $answer): int
    {
        self::assertIsArray($answer);
        self::assertIsInt($answer['tookMs'] ?? null);

        return $answer['tookMs'];
    }

    /**
     * @param array<array-key, array<string, mixed>> $cases
     * @return array<array-key, mixed> the answers under the cases' keys
     */
    private function answers(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/photo-readability-harness.mjs']);
        $process->setInput((string) json_encode(array_values($cases), JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<mixed> $answers */
        $answers = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(count($cases), $answers);

        return array_combine(array_keys($cases), $answers);
    }
}
