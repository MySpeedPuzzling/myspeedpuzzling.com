<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * docs/features/photo-uploads/README.md "A photo the phone cannot read" - the add/edit-time form's real controller
 * (assets/controllers/submit_prevention_controller.js, real Stimulus, real assets/photo_upload.js) in jsdom
 * (tests/unreadable-photo-form-harness.mjs). The incident of 2026-11-14 must not come back: a photo the phone hands over
 * empty never blocks a save, and a save that dies on a photo is not blamed on the connection.
 *
 * `sent` lists the submits the controller let through, with what each request would carry.
 */
final class UnreadablePhotoFormScriptTest extends TestCase
{
    private const array NOTHING_SENT_AS_FILE = ['finishedPuzzlesPhoto' => null, 'puzzlePhoto' => null];

    public function testAPhotoOfNoBytesIsTakenOutWhenPickedAndTheSaveGoesThrough(): void
    {
        ['pick-empty' => $facts, 'pick-empty-box' => $box] = $this->scenarios(['pick-empty', 'pick-empty-box']);

        // Told next to the field, the field shown empty again, nothing uploaded
        self::assertSame(['UNREADABLE'], self::at($facts, 'afterPick', 'notes', 'finishedPuzzlesPhoto'));
        self::assertSame([], self::at($facts, 'afterPick', 'notes', 'puzzlePhoto'));
        self::assertSame('DROP A FILE', self::at($facts, 'afterPick', 'areas', 'finishedPuzzlesPhoto'));
        self::assertSame(0, self::at($facts, 'afterPick', 'files', 'finishedPuzzlesPhoto'));
        self::assertSame([], self::at($facts, 'afterPick', 'statuses', 'finishedPuzzlesPhoto'));
        self::assertSame([], self::at($facts, 'starts'), 'The upload is never started');
        self::assertFalse(self::at($facts, 'afterPick', 'buttonDisabled'));

        // One tap on Save: sent, without the photo, with everything typed
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($facts, 'sent'));

        self::assertSame(['photo_dropped', 'empty', 'pick', 'finishedPuzzlesPhoto', 0], self::fields(self::at($facts, 'reports', 0), ['event', 'reason', 'detectedOn', 'field', 'bytes']));
        // The save that went through without it is a saved one - never counted as failed
        self::assertSame(['save', 'saved', 1, 'pick'], self::fields(self::at($facts, 'reports', 1), ['event', 'outcome', 'photosDropped', 'droppedOn']));
        self::assertCount(2, self::reports($facts));

        // A new puzzle needs its box photo: no "save without it" there
        self::assertSame(['UNREADABLE REQUIRED'], self::at($box, 'notes', 'puzzlePhoto'));
        self::assertSame(0, self::at($box, 'files', 'puzzlePhoto'));
        self::assertSame([], self::at($box, 'starts'));
    }

    public function testAPhotoTheBrowserCannotReadIsTakenOutWhenPicked(): void
    {
        ['pick-unreadable' => $facts] = $this->scenarios(['pick-unreadable']);

        self::assertSame(['UNREADABLE'], self::at($facts, 'afterPick', 'notes', 'finishedPuzzlesPhoto'));
        self::assertSame(0, self::at($facts, 'afterPick', 'files', 'finishedPuzzlesPhoto'));
        self::assertSame([], self::at($facts, 'starts'));
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($facts, 'sent'));

        // The browser's own words - without the file's name
        self::assertSame(
            ['photo_dropped', 'unreadable', 'pick', 'NotReadableError: The requested file [file] could not be read'],
            self::fields(self::at($facts, 'reports', 0), ['event', 'reason', 'detectedOn', 'error']),
        );
    }

    /**
     * The form as it always was: uploaded in the background, sent as its token
     */
    public function testAPhotoThatCanBeReadIsUploadedAndSentAsBefore(): void
    {
        ['pick-normal' => $facts, 'pick-slow-read' => $slow] = $this->scenarios(['pick-normal', 'pick-slow-read']);

        self::assertSame([], self::at($facts, 'afterPick', 'notes', 'finishedPuzzlesPhoto'));
        self::assertSame(['DONE'], self::at($facts, 'afterPick', 'statuses', 'finishedPuzzlesPhoto'));
        self::assertSame(1, self::at($facts, 'afterPick', 'files', 'finishedPuzzlesPhoto'));
        self::assertSame(1000, self::at($facts, 'starts', 0, 'bytes'));
        self::assertSame(1, self::at($facts, 'puts'));
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => ['photo_stash[finishedPuzzlesPhoto]' => str_repeat('a', 32)], 'dropped' => [], 'comment' => 'typed by the player']], self::at($facts, 'sent'));
        self::assertSame(['upload', 'done'], self::fields(self::at($facts, 'reports', 0), ['event', 'outcome']));

        // A device that does not answer the one-byte read is not judged: uploaded all the same, after the check's timeout
        self::assertSame([], self::at($slow, 'notes', 'finishedPuzzlesPhoto'));
        self::assertSame(['DONE'], self::at($slow, 'statuses', 'finishedPuzzlesPhoto'));
        self::assertSame(1, self::at($slow, 'puts'));
        self::assertGreaterThanOrEqual(1400, self::at($slow, 'tookMs'));
        self::assertLessThan(3500, self::at($slow, 'tookMs'));
    }

    public function testOnlyTheUploadStartsEmptyRefusalTakesThePhotoOut(): void
    {
        ['start-says-empty' => $empty, 'start-says-too-large' => $tooLarge] = $this->scenarios(['start-says-empty', 'start-says-too-large']);

        // `empty`: not "sent with the form" - out, with the note
        self::assertSame(['UNREADABLE'], self::at($empty, 'afterPick', 'notes', 'finishedPuzzlesPhoto'));
        self::assertSame([], self::at($empty, 'afterPick', 'statuses', 'finishedPuzzlesPhoto'));
        self::assertSame(0, self::at($empty, 'afterPick', 'files', 'finishedPuzzlesPhoto'));
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($empty, 'sent'));
        self::assertContains(['photo_dropped', 'empty', 'start'], array_map(static fn (mixed $report): array => self::fields($report, ['event', 'reason', 'detectedOn']), self::reports($empty)));

        // Any other refusal: the fallback exactly as it was - the photo travels with the form
        self::assertSame([], self::at($tooLarge, 'afterPick', 'notes', 'finishedPuzzlesPhoto'));
        self::assertSame(['WITH FORM'], self::at($tooLarge, 'afterPick', 'statuses', 'finishedPuzzlesPhoto'));
        self::assertSame(1, self::at($tooLarge, 'afterPick', 'files', 'finishedPuzzlesPhoto'));
        self::assertSame([['files' => ['finishedPuzzlesPhoto' => 1000, 'puzzlePhoto' => null], 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($tooLarge, 'sent'));
        self::assertNotContains('photo_dropped', array_column(self::reports($tooLarge), 'event'));
    }

    /**
     * The defence right before the request: a photo the page never noticed (an old page, another browser path)
     */
    public function testAnUnreadablePhotoStillInTheFormAtSaveIsTakenOutAndTheSaveGoesOn(): void
    {
        [
            'save-with-empty-photo-in-form' => $empty,
            'save-with-unreadable-photo-in-form' => $unreadable,
            'save-with-slow-read-in-form' => $slow,
        ] = $this->scenarios(['save-with-empty-photo-in-form', 'save-with-unreadable-photo-in-form', 'save-with-slow-read-in-form']);

        // Without background uploads too (the kill switch): one tap, sent without the empty photo
        // ...and tells the next page, where the note next to the field is gone (EmptyPhotoParts)
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'empty'], 'comment' => 'typed by the player']], self::at($empty, 'sent'));
        self::assertSame(['UNREADABLE'], self::at($empty, 'notes', 'finishedPuzzlesPhoto'));

        // The unreadable one is out, the good photo next to it still travels
        self::assertSame([['files' => ['finishedPuzzlesPhoto' => null, 'puzzlePhoto' => 1000], 'tokens' => [], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'unreadable'], 'comment' => 'typed by the player']], self::at($unreadable, 'sent'));
        self::assertSame(['UNREADABLE'], self::at($unreadable, 'notes', 'finishedPuzzlesPhoto'));
        self::assertSame([], self::at($unreadable, 'notes', 'puzzlePhoto'));
        self::assertSame(['photo_dropped', 'unreadable', 'save'], self::fields(self::at($unreadable, 'reports', 0), ['event', 'reason', 'detectedOn']));

        // The check cannot tell: the photo goes with the form as it always did - the save waits for the check's timeout only
        self::assertSame([['files' => ['finishedPuzzlesPhoto' => 2_345_678, 'puzzlePhoto' => null], 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($slow, 'sent'));
        self::assertSame([], self::at($slow, 'notes', 'finishedPuzzlesPhoto'));
        self::assertTrue(self::at($slow, 'whileChecking', 'buttonDisabled'));
        self::assertSame('SAVING', self::at($slow, 'whileChecking', 'label'));
        self::assertGreaterThanOrEqual(1400, self::at($slow, 'tookMs'));
        self::assertLessThan(3500, self::at($slow, 'tookMs'));
    }

    /**
     * At once, online, with a photo in the request: it may be the connection (a blocked request, the first one after the
     * camera) as well as the photo. Said so the first time, with both ways on; the photo is named when it happens again
     */
    public function testASaveThatDiesAtOnceWithAPhotoSaysBothAndNamesThePhotoOnlyTheSecondTime(): void
    {
        [
            'save-dies-at-once-with-photo' => $facts,
            'save-without-after-first-failure' => $without,
            'instant-failures-not-in-a-row' => $notInARow,
        ] = $this->scenarios(['save-dies-at-once-with-photo', 'save-without-after-first-failure', 'instant-failures-not-in-a-row']);

        // The first time: neither "try Wi-Fi" nor "the photo" - both, with "Try again" and "Save without the photo"
        self::assertSame('EITHER', self::at($facts, 'afterFirst', 'alert'));
        self::assertSame('TRY AGAIN', self::at($facts, 'afterFirst', 'tryAgainButton'));
        self::assertSame('WITHOUT', self::at($facts, 'afterFirst', 'saveWithoutButton'));
        self::assertFalse(self::at($facts, 'afterFirst', 'buttonDisabled'), 'Save itself works again too');
        self::assertSame(1, self::at($facts, 'afterFirst', 'files', 'finishedPuzzlesPhoto'), 'The photo stays until the player decides');

        // "Try again" sent the form as it was; it died at once again: now the photo is named
        self::assertSame('PHOTO', self::at($facts, 'afterSecond', 'alert'));
        self::assertSame('WITHOUT', self::at($facts, 'afterSecond', 'saveWithoutButton'));
        self::assertNull(self::at($facts, 'afterSecond', 'tryAgainButton'));

        // One tap on "Save without the photo": the photo is out, the form is sent and tells the next page
        self::assertNull(self::at($facts, 'afterWithout', 'alert'));
        self::assertSame(0, self::at($facts, 'afterWithout', 'files', 'finishedPuzzlesPhoto'));
        self::assertSame([
            ['files' => ['finishedPuzzlesPhoto' => 1000, 'puzzlePhoto' => null], 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player'],
            ['files' => ['finishedPuzzlesPhoto' => 1000, 'puzzlePhoto' => null], 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player'],
            ['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'not_sent'], 'comment' => 'typed by the player'],
        ], self::at($facts, 'sent'));

        self::assertSame(['save', 'failed', 0, 'TypeError: Failed to fetch', 'either', 1], self::fields(self::at($facts, 'reports', 0), ['event', 'outcome', 'status', 'error', 'failure', 'photosInForm']));
        self::assertSame(['save', 'failed', 'photo'], self::fields(self::at($facts, 'reports', 1), ['event', 'outcome', 'failure']));
        self::assertSame(['photo_dropped', 'not_sent', 'save_without'], self::fields(self::at($facts, 'reports', 2), ['event', 'reason', 'detectedOn']));
        self::assertSame(['save', 'saved', 1, 'save_without'], self::fields(self::at($facts, 'reports', 3), ['event', 'outcome', 'photosDropped', 'droppedOn']));

        // "Save without the photo" works right after the first failure too
        self::assertSame(
            ['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'not_sent'], 'comment' => 'typed by the player'],
            self::at($without, 'sent', 1),
        );

        // Only failures in a row count: after anything else in between it is "either" again
        self::assertSame(['EITHER', 'TRY AGAIN', 'WITHOUT'], [self::at($notInARow, 'alert'), self::at($notInARow, 'tryAgainButton'), self::at($notInARow, 'saveWithoutButton')]);
        self::assertSame(['either', null, 'either'], array_map(static fn (mixed $report): mixed => self::fields($report, ['failure'])[0], self::reports($notInARow)));
    }

    /**
     * A new puzzle needs its box photo (the finished photo stands in when there is no other): "Save without the photo"
     * never takes it - a save without it would be a certain "photo required"
     */
    public function testSaveWithoutThePhotoIsNotOfferedForThePhotoANewPuzzleNeeds(): void
    {
        [
            'new-puzzle-box-photo-in-form' => $box,
            'new-puzzle-both-photos-in-form' => $both,
            'new-puzzle-finished-photo-only' => $finishedOnly,
            'new-puzzle-box-photo-uploaded' => $uploaded,
        ] = $this->scenarios(['new-puzzle-box-photo-in-form', 'new-puzzle-both-photos-in-form', 'new-puzzle-finished-photo-only', 'new-puzzle-box-photo-uploaded']);

        // The box photo is the one in the request: "Try again" only, then the message asks for the photo - no button
        self::assertSame(['EITHER REQUIRED', 'TRY AGAIN', null], [self::at($box, 'afterFirst', 'alert'), self::at($box, 'afterFirst', 'tryAgainButton'), self::at($box, 'afterFirst', 'saveWithoutButton')]);
        self::assertSame(['PHOTO REQUIRED', null, null], [self::at($box, 'alert'), self::at($box, 'tryAgainButton'), self::at($box, 'saveWithoutButton')]);
        self::assertSame(1, self::at($box, 'files', 'puzzlePhoto'), 'The box photo is never taken out');

        // Both in the request: only the finished photo goes, the box photo still travels
        self::assertSame(['EITHER', 'WITHOUT'], [self::at($both, 'afterFirst', 'alert'), self::at($both, 'afterFirst', 'saveWithoutButton')]);
        self::assertSame(
            ['files' => ['finishedPuzzlesPhoto' => null, 'puzzlePhoto' => 1000], 'tokens' => [], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'not_sent'], 'comment' => 'typed by the player'],
            self::at($both, 'sent', 1),
        );

        // The finished photo is the new puzzle's only photo: it cannot go either
        self::assertSame(['EITHER REQUIRED', 'TRY AGAIN', null], [self::at($finishedOnly, 'alert'), self::at($finishedOnly, 'tryAgainButton'), self::at($finishedOnly, 'saveWithoutButton')]);

        // The box photo went up in the background: the finished photo may go, the box photo's token stays
        self::assertSame(['EITHER', 'WITHOUT'], [self::at($uploaded, 'afterFirst', 'alert'), self::at($uploaded, 'afterFirst', 'saveWithoutButton')]);
        self::assertSame(
            ['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => ['photo_stash[puzzlePhoto]' => str_repeat('a', 32)], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'not_sent'], 'comment' => 'typed by the player'],
            self::at($uploaded, 'sent', 1),
        );
    }

    /**
     * The note next to the field is gone with the form in the same tap: a photo taken out while saving goes with the
     * form as `photo_dropped[<field>]` - and only while it stays out
     */
    public function testAPhotoTakenOutWhileSavingTellsTheNextPage(): void
    {
        [
            'start-says-empty-while-saving' => $whileSaving,
            'photo-picked-again-after-save-without' => $pickedAgain,
            'pick-empty' => $atPick,
        ] = $this->scenarios(['start-says-empty-while-saving', 'photo-picked-again-after-save-without', 'pick-empty']);

        // Save was waiting for the upload when its start called the photo empty
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'empty'], 'comment' => 'typed by the player']], self::at($whileSaving, 'sent'));

        // Another photo picked for the field: nothing is "left out" any more
        self::assertSame(['photo_dropped[finishedPuzzlesPhoto]' => 'not_sent'], self::at($pickedAgain, 'sent', 1, 'dropped'));
        self::assertSame(
            ['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => ['photo_stash[finishedPuzzlesPhoto]' => str_repeat('a', 32)], 'dropped' => [], 'comment' => 'typed by the player'],
            self::at($pickedAgain, 'sent', 2),
        );

        // Taken out when it was picked: the player read the note next to the field - nothing to tell later
        self::assertSame([], self::at($atPick, 'sent', 0, 'dropped'));
    }

    /**
     * The kill switch (no background uploads): a picked photo is checked all the same
     */
    public function testAPickedPhotoIsCheckedWithoutBackgroundUploadsToo(): void
    {
        ['pick-empty-without-uploads' => $facts] = $this->scenarios(['pick-empty-without-uploads']);

        self::assertSame([['UNREADABLE'], 0], [self::at($facts, 'afterEmpty', 'notes', 'finishedPuzzlesPhoto'), self::at($facts, 'afterEmpty', 'files', 'finishedPuzzlesPhoto')]);
        self::assertSame([['UNREADABLE'], 0], [self::at($facts, 'afterUnreadable', 'notes', 'finishedPuzzlesPhoto'), self::at($facts, 'afterUnreadable', 'files', 'finishedPuzzlesPhoto')]);
        // A photo that can be read stays in its input and goes with the form, as it always did there
        self::assertSame([[], 1], [self::at($facts, 'afterNormal', 'notes', 'finishedPuzzlesPhoto'), self::at($facts, 'afterNormal', 'files', 'finishedPuzzlesPhoto')]);
        self::assertSame([['files' => ['finishedPuzzlesPhoto' => 1000, 'puzzlePhoto' => null], 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($facts, 'sent'));
        self::assertSame([], self::at($facts, 'starts'));
    }

    /**
     * The pace check's "Yes, time is correct" (ppm_validator_controller.js) on the same form: with a photo travelling in
     * the request Save detours for the one-byte read before the dialog AND after the confirmation - the first of each
     * pair is stopped before the pace check sees it, so its "confirmed" is still there for the one that is sent
     */
    public function testConfirmingThePaceCheckSavesExactlyOnce(): void
    {
        [
            'ppm-confirm-with-photo-in-form' => $withPhoto,
            'ppm-confirm-without-photo' => $withoutPhoto,
            'ppm-confirm-with-empty-photo-in-form' => $emptyPhoto,
        ] = $this->scenarios(['ppm-confirm-with-photo-in-form', 'ppm-confirm-without-photo', 'ppm-confirm-with-empty-photo-in-form']);

        // Asked first: nothing sent while the dialog is open
        self::assertSame(0, self::at($withPhoto, 'whileAsked', 'sent'));
        self::assertSame(0, self::at($withoutPhoto, 'whileAsked', 'sent'));

        self::assertSame([['files' => ['finishedPuzzlesPhoto' => 1000, 'puzzlePhoto' => null], 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($withPhoto, 'sent'));
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => [], 'comment' => 'typed by the player']], self::at($withoutPhoto, 'sent'));
        // An empty photo is out before the dialog, and the confirmed save says so once
        self::assertSame([['files' => self::NOTHING_SENT_AS_FILE, 'tokens' => [], 'dropped' => ['photo_dropped[finishedPuzzlesPhoto]' => 'empty'], 'comment' => 'typed by the player']], self::at($emptyPhoto, 'sent'));
    }

    public function testTheConnectionMessageStaysForWhatItWasWrittenFor(): void
    {
        [
            'save-dies-slowly-with-photo' => $slow,
            'save-dies-after-seconds-with-photo' => $seconds,
            'save-dies-offline-with-photo' => $offline,
            'save-dies-without-photo' => $withoutPhoto,
        ] = $this->scenarios(['save-dies-slowly-with-photo', 'save-dies-after-seconds-with-photo', 'save-dies-offline-with-photo', 'save-dies-without-photo']);

        self::assertSame(['NETWORK', null], [self::at($slow, 'alert'), self::at($slow, 'saveWithoutButton')]);
        self::assertSame('network', self::at($slow, 'reports', 0, 'failure'));

        self::assertSame(['NETWORK', null], [self::at($offline, 'alert'), self::at($offline, 'saveWithoutButton')]);
        self::assertSame('network', self::at($offline, 'reports', 0, 'failure'));

        self::assertNull(self::at($slow, 'tryAgainButton'));
        self::assertNull(self::at($offline, 'tryAgainButton'));

        // Neither at once nor slow: both possibilities named, both ways on
        self::assertSame(['EITHER', 'TRY AGAIN', 'WITHOUT'], [self::at($seconds, 'alert'), self::at($seconds, 'tryAgainButton'), self::at($seconds, 'saveWithoutButton')]);
        self::assertSame('either', self::at($seconds, 'reports', 0, 'failure'));

        // No photo in the request: resent once by itself, as before - no message yet
        self::assertNull(self::at($withoutPhoto, 'alert'));
        self::assertSame('RETRYING', self::at($withoutPhoto, 'label'));
        self::assertSame([], self::at($withoutPhoto, 'reports'));
    }

    /**
     * @param list<string> $keys
     * @return list<mixed>
     */
    private static function fields(mixed $report, array $keys): array
    {
        self::assertIsArray($report);

        return array_map(static fn (string $key): mixed => $report[$key] ?? null, $keys);
    }

    /**
     * What a scenario observed at the end of a path of keys
     */
    private static function at(mixed $facts, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($facts);
            self::assertArrayHasKey($key, $facts);
            $facts = $facts[$key];
        }

        return $facts;
    }

    /**
     * @return array<mixed>
     */
    private static function reports(mixed $facts): array
    {
        $reports = self::at($facts, 'reports');
        self::assertIsArray($reports);

        return $reports;
    }

    /**
     * @param list<string> $names
     * @return array<string, array<string, mixed>> what each scenario observed
     */
    private function scenarios(array $names): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/unreadable-photo-form-harness.mjs']);
        $process->setInput((string) json_encode($names, JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->mustRun();

        /** @var list<array<string, mixed>> $answers */
        $answers = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(count($names), $answers);

        foreach ($answers as $index => $answer) {
            $crashed = $answer['crashed'] ?? null;
            self::assertNull($crashed, $names[$index] . ': ' . (is_string($crashed) ? $crashed : ''));
        }

        return array_combine($names, $answers);
    }
}
