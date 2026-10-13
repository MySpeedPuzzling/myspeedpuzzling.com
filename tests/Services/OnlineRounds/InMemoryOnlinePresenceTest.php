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
}
