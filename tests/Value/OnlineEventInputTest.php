<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        yield 'too much data' => [[...$valid, 'data' => ['blob' => str_repeat('x', OnlineEventInput::MAX_DATA_BYTES)]], strtolower(self::ID)];
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
}
