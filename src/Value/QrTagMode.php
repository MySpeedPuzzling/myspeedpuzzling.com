<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The organiser's chosen QR tag mode of a competition (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §2, §3.1) - stored in competition.qr_tag_mode, NULL = not chosen (person name tags, as
 * before). It only decides what the print page offers first: every printed tag kind keeps resolving whatever the mode.
 */
enum QrTagMode: string
{
    // A tag per competitor for the whole competition: a person, or a pair/team by its line-up
    case Entrant = 'entrant';
    // A tag per table for the whole competition: whoever sits there in the current round
    case Table = 'table';
    // A tag per competitor per round: that round's entry with its table
    case RoundEntry = 'round_entry';
}
