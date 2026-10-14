<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\OnlineEntryQuota;
use SpeedPuzzling\Web\Value\OnlineEventInput;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;

/**
 * An event of a participant's phone, read before anything is stored (implementation-plan.md §3.1).
 */
final class OnlineEventInputTest extends TestCase
{
    private const string ROUND = '018d0050-0000-0000-0000-000000000101';
    private const string OTHER_ROUND = '018D0050-0000-0000-0000-0000000001AA';
    private const string ID = '019A0000-0000-7000-8000-000000000001';
    private const string MEDIA = '019a0000-0000-7000-8000-0000000000aa';

    public function testAnEventAsThePhoneSendsIt(): void
    {
        $input = OnlineEventInput::read([
            'id' => self::ID,
            'type' => 'finish.button',
            'roundId' => self::OTHER_ROUND,
            'clientMs' => 1_792_002_832_345,
            'perfMs' => 2_832_345.5,
            'offsetMs' => -1234.5,
            'rttMs' => 38,
            'data' => ['burstMediaId' => self::MEDIA, 'photoMediaId' => null],
        ], self::ROUND);

        self::assertInstanceOf(OnlineEventInput::class, $input);
        self::assertSame(strtolower(self::ID), $input->id);
        self::assertSame(OnlineEventType::FinishButton, $input->type);
        self::assertSame(strtolower(self::OTHER_ROUND), $input->roundId);
        self::assertSame(1_792_002_832_345, $input->clientMs);
        self::assertSame(2_832_345.5, $input->perfMs);
        self::assertSame(-1234.5, $input->offsetMs);
        self::assertSame(38.0, $input->rttMs);
        self::assertSame(['burstMediaId' => self::MEDIA, 'photoMediaId' => null], $input->data);
    }

    public function testAnEventWithoutARoundBelongsToTheRequestsRoundAndNeedsNoClockDetails(): void
    {
        $input = OnlineEventInput::read(['id' => self::ID, 'type' => 'page.hidden', 'clientMs' => 1_792_002_832_345.0], self::ROUND);

        self::assertInstanceOf(OnlineEventInput::class, $input);
        self::assertSame(self::ROUND, $input->roundId);
        self::assertSame(1_792_002_832_345, $input->clientMs);
        self::assertNull($input->perfMs);
        self::assertNull($input->offsetMs);
        self::assertNull($input->rttMs);
        self::assertSame([], $input->data);
    }

    public function testAnUnknownTypeIsRefusedAsSuch(): void
    {
        self::assertSame(
            ['id' => strtolower(self::ID), 'reason' => OnlineEventInput::UNKNOWN_TYPE],
            OnlineEventInput::read(['id' => self::ID, 'type' => 'finish.dance', 'clientMs' => 1_792_002_832_345], self::ROUND),
        );
    }

    /**
     * @return iterable<string, array{mixed, null|string}>
     */
    public static function invalidEvents(): iterable
    {
        $valid = ['id' => self::ID, 'type' => 'camera.lost', 'clientMs' => 1_792_002_832_345];

        yield 'not an object' => ['camera.lost', null];
        yield 'no id' => [['type' => 'camera.lost', 'clientMs' => 1_792_002_832_345], null];
        yield 'an id that is no UUID' => [[...$valid, 'id' => 'event-1'], 'event-1'];
        yield 'no moment' => [[...$valid, 'clientMs' => null], strtolower(self::ID)];
        yield 'a moment in seconds' => [[...$valid, 'clientMs' => 1_792_002_832], strtolower(self::ID)];
        yield 'a moment as a string' => [[...$valid, 'clientMs' => '1792002832345'], strtolower(self::ID)];
        yield 'a fraction of a millisecond' => [[...$valid, 'clientMs' => 1_792_002_832_345.5], strtolower(self::ID)];
        yield 'an offset that is no number' => [[...$valid, 'offsetMs' => 'a lot'], strtolower(self::ID)];
        yield 'a round that is no UUID' => [[...$valid, 'roundId' => 'round-1'], strtolower(self::ID)];
        yield 'data that is a list' => [[...$valid, 'data' => [1, 2]], strtolower(self::ID)];
        yield 'too much data' => [[...$valid, 'data' => ['blob' => str_repeat('x', OnlineEntryQuota::MAX_EVENT_DATA_BYTES)]], strtolower(self::ID)];
        yield 'an id in braces' => [[...$valid, 'id' => '{' . self::ID . '}'], '{' . self::ID . '}'];
        yield 'an id as a URN' => [[...$valid, 'id' => 'urn:uuid:' . self::ID], 'urn:uuid:' . self::ID];
        yield 'a round in braces' => [[...$valid, 'roundId' => '{' . self::ROUND . '}'], strtolower(self::ID)];
        yield 'a NUL in a text' => [[...$valid, 'data' => ['browser' => "Safari\u{0000}"]], strtolower(self::ID)];
        yield 'a NUL deep down' => [[...$valid, 'data' => ['camera' => ['label' => ['back', "x\0"]]]], strtolower(self::ID)];
        yield 'a NUL in a key' => [[...$valid, 'data' => ["br\0owser" => 'Safari']], strtolower(self::ID)];
        yield 'an upload of a media in braces' => [[...$valid, 'type' => 'media.uploaded', 'data' => ['mediaId' => '{' . self::MEDIA . '}']], strtolower(self::ID)];
        yield 'a manifest of too many frames' => [[...$valid, 'type' => 'media.uploaded', 'data' => ['mediaId' => self::MEDIA, 'manifest' => array_fill(0, OnlineEntryQuota::MAX_MANIFEST_FRAMES + 1, ['offset' => 0, 'length' => 1, 'captureMs' => 1])]], strtolower(self::ID)];
        yield 'time-up without pieces' => [[...$valid, 'type' => 'timeup.pieces', 'data' => []], strtolower(self::ID)];
        yield 'time-up with negative pieces' => [[...$valid, 'type' => 'timeup.pieces', 'data' => ['pieces' => -1]], strtolower(self::ID)];
        yield 'time-up in an unknown measure' => [[...$valid, 'type' => 'timeup.pieces', 'data' => ['pieces' => 3, 'measure' => 'missing']], strtolower(self::ID)];
        yield 'an upload without its media' => [[...$valid, 'type' => 'media.uploaded', 'data' => ['bytes' => 10]], strtolower(self::ID)];
        yield 'an upload with a broken manifest' => [[...$valid, 'type' => 'media.uploaded', 'data' => ['mediaId' => self::MEDIA, 'manifest' => [['offset' => 0, 'length' => 0, 'captureMs' => 1]]]], strtolower(self::ID)];
        yield 'a tap naming no media' => [[...$valid, 'type' => 'finish.button', 'data' => ['burstMediaId' => 'burst']], strtolower(self::ID)];
    }

    #[DataProvider('invalidEvents')]
    public function testInvalidFieldsAreRefused(mixed $event, null|string $id): void
    {
        self::assertSame(['id' => $id, 'reason' => OnlineEventInput::INVALID_FIELDS], OnlineEventInput::read($event, self::ROUND));
    }

    /**
     * The 2 KB is the event's own data - a burst's manifest is bounded by its frames instead
     */
    public function testAManifestDoesNotCountTowardsTheDataLimit(): void
    {
        $manifest = array_map(
            static fn (int $frame): array => ['offset' => $frame * 15_000, 'length' => 15_000, 'captureMs' => 1_792_002_827_345 + $frame * 100],
            range(0, OnlineEntryQuota::MAX_MANIFEST_FRAMES - 1),
        );

        $input = OnlineEventInput::read(['id' => self::ID, 'type' => 'media.uploaded', 'clientMs' => 1_792_002_832_345, 'data' => ['mediaId' => self::MEDIA, 'manifest' => $manifest]], self::ROUND);

        self::assertInstanceOf(OnlineEventInput::class, $input);
        self::assertCount(OnlineEntryQuota::MAX_MANIFEST_FRAMES, (array) $input->manifest());
    }

    public function testAnEchoedIdIsCut(): void
    {
        $refusal = OnlineEventInput::read(['id' => str_repeat('x', 1_000), 'type' => 'camera.lost', 'clientMs' => 1_792_002_832_345], self::ROUND);

        self::assertIsArray($refusal);
        self::assertSame(64, mb_strlen((string) $refusal['id']));
    }

    public function testATimeUpReport(): void
    {
        $input = OnlineEventInput::read(['id' => self::ID, 'type' => 'timeup.pieces', 'clientMs' => 1_792_002_832_345, 'data' => ['pieces' => 12, 'measure' => 'placed']], self::ROUND);
        self::assertInstanceOf(OnlineEventInput::class, $input);
        self::assertSame(12, $input->pieces());
        self::assertSame(OnlineUnfinishedMeasure::PiecesPlaced, $input->measure());

        $withoutMeasure = OnlineEventInput::read(['id' => self::ID, 'type' => 'timeup.pieces', 'clientMs' => 1_792_002_832_345, 'data' => ['pieces' => 0]], self::ROUND);
        self::assertInstanceOf(OnlineEventInput::class, $withoutMeasure);
        self::assertSame(0, $withoutMeasure->pieces());
        self::assertNull($withoutMeasure->measure());
    }

    public function testAnUploadReportWithABurstsFrames(): void
    {
        $input = OnlineEventInput::read([
            'id' => self::ID,
            'type' => 'media.uploaded',
            'clientMs' => 1_792_002_832_345,
            'data' => [
                'mediaId' => strtoupper(self::MEDIA),
                'bytes' => 1_534_000,
                'manifest' => [
                    ['offset' => 0, 'length' => 15_000, 'captureMs' => 1_792_002_827_345.25],
                    ['offset' => 15_000, 'length' => 14_000, 'captureMs' => 1_792_002_827_445],
                ],
            ],
        ], self::ROUND);

        self::assertInstanceOf(OnlineEventInput::class, $input);
        self::assertSame(self::MEDIA, $input->mediaId());
        self::assertSame([
            ['offset' => 0, 'length' => 15_000, 'captureMs' => 1_792_002_827_345.25],
            ['offset' => 15_000, 'length' => 14_000, 'captureMs' => 1_792_002_827_445],
        ], $input->manifest());

        $photo = OnlineEventInput::read(['id' => self::ID, 'type' => 'media.uploaded', 'clientMs' => 1_792_002_832_345, 'data' => ['mediaId' => self::MEDIA]], self::ROUND);
        self::assertInstanceOf(OnlineEventInput::class, $photo);
        self::assertNull($photo->manifest());
    }

    public function testAFinishCandidateAsThePageSendsIt(): void
    {
        $data = [
            'candidateId' => 1,
            'detectedAtMs' => 1_792_002_832_845,
            'windowFromMs' => 1_792_002_832_345,
            'windowToMs' => 1_792_002_832_845,
            'heldMs' => 500,
            'score' => 0.86,
            'minScore' => 0.71,
            'peakScore' => 0.93,
            'upFrames' => 3,
            'droppedFrames' => 0,
            'straddled' => false,
            'view' => 'angled',
            'mode' => 'on',
            'machineVersion' => 1,
            'fps' => 4.8,
            'inferenceMs' => null,
        ];
        $input = OnlineEventInput::read(['id' => self::ID, 'type' => 'finish.candidate', 'clientMs' => 1_792_002_832_345, 'data' => $data], self::ROUND);

        self::assertInstanceOf(OnlineEventInput::class, $input);
        self::assertSame($data, $input->data, 'stored as sent');

        // Older pages and the bench send fewer fields - every one is optional
        self::assertInstanceOf(OnlineEventInput::class, OnlineEventInput::read(['id' => self::ID, 'type' => 'finish.candidate', 'clientMs' => 1_792_002_832_345, 'data' => []], self::ROUND));

        $wrongs = [
            'a view nobody knows' => ['view' => 'sideways'],
            'a mode nobody knows' => ['mode' => 'loud'],
            'a textual score' => ['score' => 'high'],
            'a moment as text' => ['detectedAtMs' => '1792002832845'],
            'straddled as a number' => ['straddled' => 1],
            'a photo that is no media id' => ['photoMediaId' => 'photo'],
        ];

        foreach ($wrongs as $case => $wrong) {
            self::assertSame(
                ['id' => strtolower(self::ID), 'reason' => OnlineEventInput::INVALID_FIELDS],
                OnlineEventInput::read(['id' => self::ID, 'type' => 'finish.candidate', 'clientMs' => 1_792_002_832_345, 'data' => [...$data, ...$wrong]], self::ROUND),
                $case,
            );
        }
    }

    public function testADetectorChangeAsThePageSendsIt(): void
    {
        $running = ['state' => 'ready', 'running' => true, 'reason' => null, 'view' => 'topDown'];
        $input = OnlineEventInput::read(['id' => self::ID, 'type' => 'detector.changed', 'clientMs' => 1_792_002_832_345, 'data' => $running], self::ROUND);

        self::assertInstanceOf(OnlineEventInput::class, $input);
        self::assertSame(OnlineEventType::DetectorChanged, $input->type);
        self::assertSame($running, $input->data);

        foreach (['slow', 'stalled', 'error', 'loading', 'unsupported', 'failed', 'skipped', 'off'] as $reason) {
            self::assertInstanceOf(
                OnlineEventInput::class,
                OnlineEventInput::read(['id' => self::ID, 'type' => 'detector.changed', 'clientMs' => 1_792_002_832_345, 'data' => ['state' => 'ready', 'running' => false, 'reason' => $reason, 'view' => null]], self::ROUND),
                $reason,
            );
        }

        $wrongs = [
            'no running' => ['state' => 'ready'],
            'running as text' => ['state' => 'ready', 'running' => 'yes'],
            'a state nobody knows' => ['state' => 'sleepy', 'running' => false],
            'no state' => ['running' => false],
            'a reason nobody knows' => ['state' => 'ready', 'running' => false, 'reason' => 'tired'],
            'a view nobody knows' => ['state' => 'ready', 'running' => true, 'view' => 'sideways'],
        ];

        foreach ($wrongs as $case => $wrong) {
            self::assertSame(
                ['id' => strtolower(self::ID), 'reason' => OnlineEventInput::INVALID_FIELDS],
                OnlineEventInput::read(['id' => self::ID, 'type' => 'detector.changed', 'clientMs' => 1_792_002_832_345, 'data' => $wrong], self::ROUND),
                $case,
            );
        }
    }
}
