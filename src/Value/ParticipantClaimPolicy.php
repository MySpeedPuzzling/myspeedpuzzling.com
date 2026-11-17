<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a claim (a player saying "this participant is me" from a name, without proof) does at an event -
 * `competition.participant_claims` (docs/features/competitions-management/participant-linking.md D3, §3.2): `open` links
 * at once, `approval` waits for the organiser. NULL stored = automatic: resolve().
 */
enum ParticipantClaimPolicy: string
{
    case Open = 'open';
    case Approval = 'approval';

    /**
     * The one rule (D3, R9): the stored choice, else `approval` while time proposals are effective - competitor pages
     * and time proposals on, at an event that is not online by the series-aware rule (EventIsOnline: an edition by its
     * series' flag) - else `open`. Only there a claim before the start would buy a cheat (proposing somebody else's time).
     */
    public static function resolve(null|self $stored, bool $competitorPages, bool $timeProposals, bool $online): self
    {
        if ($stored !== null) {
            return $stored;
        }

        return self::proposalsEffective($competitorPages, $timeProposals, $online) ? self::Approval : self::Open;
    }

    public static function proposalsEffective(bool $competitorPages, bool $timeProposals, bool $online): bool
    {
        return $competitorPages && $timeProposals && $online === false;
    }
}
