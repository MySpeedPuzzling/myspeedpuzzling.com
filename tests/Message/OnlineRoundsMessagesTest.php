<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Message;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Message\EnableOnlineRound;
use SpeedPuzzling\Web\Message\GenerateOnlineTokens;
use SpeedPuzzling\Web\Message\RegenerateOnlineToken;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The online rounds' messages that write participants, their tokens or official results take turns with every other
 * participant write of the event (CompetitionParticipantsLock, SerializedByLockMessagesTest).
 */
final class OnlineRoundsMessagesTest extends TestCase
{
    private const string COMPETITION = '018D0050-0000-0000-0000-000000000001';

    public function testTheParticipantWritesLockTheEvent(): void
    {
        $key = CompetitionParticipantsLock::key(self::COMPETITION);

        self::assertSame('participant-import-018d0050-0000-0000-0000-000000000001', $key);
        self::assertSame($key, (new EnableOnlineRound(self::COMPETITION, 'round', 'player'))->lockKey());
        self::assertSame($key, (new GenerateOnlineTokens(self::COMPETITION))->lockKey());
        self::assertSame($key, (new RegenerateOnlineToken(strtolower(self::COMPETITION), 'participant'))->lockKey());
        self::assertSame($key, (new ConfirmOnlineResult(self::COMPETITION, 'round', 'participant', 1_792_002_832_345, OnlineConfirmedSource::Button, null, 'player', RoundEntryResult::none()))->lockKey());
    }
}
