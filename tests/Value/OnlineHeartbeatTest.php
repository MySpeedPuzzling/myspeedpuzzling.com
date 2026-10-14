<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\OnlineHeartbeat;

/**
 * A phone's heartbeat - the device id required, the telemetry kept only as the fields it names.
 */
final class OnlineHeartbeatTest extends TestCase
{
    public function testTheHeartbeatOfTheContract(): void
    {
        $heartbeat = OnlineHeartbeat::read([
            'deviceId' => 'phone-1',
            'roundId' => '018D0050-0000-0000-0000-000000000101',
            'visible' => true,
            'camera' => ['ok' => true, 'w' => 1920, 'h' => 1080, 'fps' => 30],
            'detector' => ['ok' => true, 'fps' => 4.8, 'mode' => 'on'],
            'clock' => ['offsetMs' => -1234.5, 'rttMs' => 38],
            'outbox' => ['events' => 0, 'media' => 2],
        ]);

        self::assertNotNull($heartbeat);
        self::assertSame('phone-1', $heartbeat->deviceId);
        self::assertSame([
            'deviceId' => 'phone-1',
            'roundId' => '018d0050-0000-0000-0000-000000000101',
            'visible' => true,
            'camera' => ['ok' => true, 'w' => 1920, 'h' => 1080, 'fps' => 30.0],
            'detector' => ['ok' => true, 'fps' => 4.8, 'mode' => 'on'],
            'clock' => ['offsetMs' => -1234.5, 'rttMs' => 38.0],
            'outbox' => ['events' => 0, 'media' => 2],
        ], $heartbeat->telemetry);
    }

    public function testWhatAPhoneMadeUpIsNotKept(): void
    {
        $heartbeat = OnlineHeartbeat::read([
            'deviceId' => ' phone-1 ',
            'roundId' => '{018d0050-0000-0000-0000-000000000101}',
            'visible' => 'yes',
            'camera' => ['ok' => 1, 'w' => -5, 'h' => '1080', 'fps' => 'fast', 'secret' => 'x'],
            'detector' => ['ok' => true, 'fps' => 5, 'mode' => "on\0"],
            'clock' => [1, 2],
            'battery' => 0.5,
        ]);

        self::assertNotNull($heartbeat);
        self::assertSame([
            'deviceId' => 'phone-1',
            'roundId' => null,
            'visible' => null,
            'camera' => ['ok' => null, 'w' => null, 'h' => null, 'fps' => null],
            'detector' => ['ok' => true, 'fps' => 5.0, 'mode' => null],
            'clock' => null,
            'outbox' => null,
        ], $heartbeat->telemetry);
    }

    public function testThePhaseTwoDetectorTelemetryIsKeptWhenSentAndCheckedByValue(): void
    {
        $heartbeat = OnlineHeartbeat::read([
            'deviceId' => 'phone-1',
            'detector' => ['ok' => true, 'fps' => 4.6, 'mode' => 'on', 'state' => 'ready', 'inferenceMs' => 61.4, 'view' => 'topDown'],
        ]);

        self::assertNotNull($heartbeat);
        self::assertSame(['ok' => true, 'fps' => 4.6, 'mode' => 'on', 'state' => 'ready', 'inferenceMs' => 61.4, 'view' => 'topDown'], $heartbeat->telemetry['detector']);

        // A state or view nobody knows, a negative or textual inference time: kept as null, never stored as sent
        $made = OnlineHeartbeat::read([
            'deviceId' => 'phone-1',
            'detector' => ['ok' => false, 'fps' => null, 'mode' => 'shadow', 'state' => 'melting', 'inferenceMs' => -3, 'view' => 'sideways'],
        ]);
        self::assertNotNull($made);
        self::assertSame(['ok' => false, 'fps' => null, 'mode' => 'shadow', 'state' => null, 'inferenceMs' => null, 'view' => null], $made->telemetry['detector']);

        $textual = OnlineHeartbeat::read(['deviceId' => 'phone-1', 'detector' => ['state' => 'unsupported', 'inferenceMs' => '80', 'view' => null]]);
        self::assertNotNull($textual);
        self::assertSame(['ok' => null, 'fps' => null, 'mode' => null, 'state' => 'unsupported', 'inferenceMs' => null, 'view' => null], $textual->telemetry['detector']);
    }

    public function testADeviceIdIsRequired(): void
    {
        self::assertNull(OnlineHeartbeat::read([]));
        self::assertNull(OnlineHeartbeat::read(['deviceId' => '']));
        self::assertNull(OnlineHeartbeat::read(['deviceId' => 12]));
        self::assertNull(OnlineHeartbeat::read(['deviceId' => str_repeat('d', OnlineHeartbeat::MAX_DEVICE_ID_LENGTH + 1)]));
        // Postgres refuses a NUL in a text column - and no device id has a control character
        self::assertNull(OnlineHeartbeat::read(['deviceId' => "phone\0"]));
        self::assertNull(OnlineHeartbeat::read(['deviceId' => "phone\nb"]));
        self::assertNull(OnlineHeartbeat::read(['deviceId' => "\xC3\x28"]));
        self::assertNotNull(OnlineHeartbeat::read(['deviceId' => str_repeat('d', OnlineHeartbeat::MAX_DEVICE_ID_LENGTH)]));
    }
}
