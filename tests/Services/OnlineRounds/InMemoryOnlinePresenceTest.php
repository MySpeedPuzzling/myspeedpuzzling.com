<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\OnlineRounds\InMemoryOnlinePresence;

final class InMemoryOnlinePresenceTest extends TestCase
{
    private const string ROUND = '018D0050-0000-0000-0000-000000000101';
    private const string OTHER_ROUND = '018d0050-0000-0000-0000-000000000199';
    private const string PARTICIPANT = '018D0050-0000-0000-0000-000000000201';

    public function testTheLastBeatOfEveryParticipantPerRound(): void
    {
        $presence = new InMemoryOnlinePresence();

        $presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'a', 'visible' => true], 1_000);
        $presence->beat(strtolower(self::ROUND), strtolower(self::PARTICIPANT), ['deviceId' => 'b', 'visible' => false], 16_000);
        $presence->beat(self::ROUND, '018d0050-0000-0000-0000-000000000202', ['deviceId' => 'c'], 5_000);

        self::assertSame([
            strtolower(self::PARTICIPANT) => ['deviceId' => 'b', 'visible' => false, 'lastSeenMs' => 16_000],
            '018d0050-0000-0000-0000-000000000202' => ['deviceId' => 'c', 'lastSeenMs' => 5_000],
        ], $presence->forRound(self::ROUND));
        self::assertSame([], $presence->forRound(self::OTHER_ROUND));
    }

    public function testAnotherDevicesBeatIsKeptApart(): void
    {
        $presence = new InMemoryOnlinePresence();
        $only = '018d0050-0000-0000-0000-000000000202';

        $presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'phone', 'visible' => true], 1_000);
        $presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'laptop', 'visible' => false], 2_000, holdsEntry: false);
        $presence->beat(self::ROUND, $only, ['deviceId' => 'tablet'], 3_000, holdsEntry: false);

        self::assertSame([
            strtolower(self::PARTICIPANT) => ['deviceId' => 'phone', 'visible' => true, 'lastSeenMs' => 1_000, 'otherDevice' => ['deviceId' => 'laptop', 'lastSeenMs' => 2_000]],
            $only => ['otherDevice' => ['deviceId' => 'tablet', 'lastSeenMs' => 3_000]],
        ], $presence->forRound(self::ROUND));

        // The laptop takes the link: its beats are the presence now, the phone's older one as "other" is no other device
        $presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'laptop', 'visible' => true], 4_000);
        self::assertSame(['deviceId' => 'laptop', 'visible' => true, 'lastSeenMs' => 4_000], $presence->forRound(self::ROUND)[strtolower(self::PARTICIPANT)]);
    }
}
