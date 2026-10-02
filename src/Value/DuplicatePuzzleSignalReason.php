<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a catalogue signal looks like one puzzle entered twice - or, for the counter-hints, like two different ones
 * (docs/features/duplicate-results.md, Layer 4). Stored on the signal as a JSON list of the values; the points are
 * in DuplicatePuzzleSignalScoring.
 */
enum DuplicatePuzzleSignalReason: string
{
    case SameEan = 'same_ean';
    // A catalogue number shared, or one record's number inside the other's EAN (Ravensburger 14940 - 4005556149407)
    case SameCode = 'same_code';
    case SimilarName = 'similar_name';
    case NameContained = 'name_contained';
    case SameBrand = 'same_brand';
    case NotApproved = 'not_approved';
    case FewResults = 'few_results';
    // The newer record was added just before the matching results - "I could not find it, so I added it"
    case AddedAround = 'added_around';
    // Both records added within a minute or so - the new-puzzle form sent twice
    case AddedTogether = 'added_together';
    // Counter-hint: both records well used and the names not alike - two established puzzles (a box, an event)
    case ManyResults = 'many_results';
    // Counter-hint: "Advent Calendar 7" - "Advent Calendar 18", "Exit Puzzle: Garage" - "Exit Puzzle: Attic"
    case SetParts = 'set_parts';

    public function label(): string
    {
        return match ($this) {
            self::SameEan => 'same EAN',
            self::SameCode => 'same code',
            self::SimilarName => 'similar name',
            self::NameContained => 'name inside the other',
            self::SameBrand => 'same brand',
            self::NotApproved => 'not approved',
            self::FewResults => 'few results',
            self::AddedAround => 'added for these results',
            self::AddedTogether => 'added together',
            self::ManyResults => 'many results each',
            self::SetParts => 'parts of one set',
        };
    }

    public function isCounterEvidence(): bool
    {
        return $this === self::ManyResults || $this === self::SetParts;
    }
}
