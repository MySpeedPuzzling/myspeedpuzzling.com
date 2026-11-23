<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The one list of what a puzzle change request proposes - a new proposable field is a new case here (and a column
 * pair on PuzzleChangeRequest, a line in PuzzleProposalChanges). The values are the internal API's `selectedFields`.
 *
 * Several change requests of one puzzle may wait at once as long as they change different fields: a field is locked
 * while a pending change request changes it (PuzzleProposalChanges, GetPendingPuzzleProposals::locks()). The names
 * are never locked - they apply as a diff, so several proposals of them may wait next to each other.
 */
enum PuzzleProposalField: string
{
    case Name = 'name';
    case NameLanguage = 'nameLanguage';
    case AlternativeNames = 'alternativeNames';
    case Manufacturer = 'manufacturer';
    case PiecesCount = 'piecesCount';
    case Ean = 'ean';
    case IdentificationNumber = 'identificationNumber';
    case Image = 'image';
    // "The box has no barcode (EAN)" - Puzzle::$eanAbsentConfirmedAt
    case EanAbsent = 'eanAbsent';

    /**
     * Whether a pending change request changing it keeps every other proposal from changing it too.
     */
    public function locksWhilePending(): bool
    {
        return match ($this) {
            self::Name, self::NameLanguage, self::AlternativeNames => false,
            default => true,
        };
    }

    /**
     * The fields locked together with it: the EAN and "no barcode on the box" say the opposite of each other, so a
     * pending proposal of either keeps both from being proposed.
     *
     * @return list<self>
     */
    public function lockedTogether(): array
    {
        return match ($this) {
            self::Ean, self::EanAbsent => [self::Ean, self::EanAbsent],
            default => [$this],
        };
    }

    /**
     * @return list<self>
     */
    public static function lockable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $field): bool => $field->locksWhilePending()));
    }

    /**
     * The field's label in the player's form (translation key).
     */
    public function formLabel(): string
    {
        return match ($this) {
            self::Name => 'puzzle_names.main_title',
            self::NameLanguage => 'puzzle_names.language',
            self::AlternativeNames => 'puzzle_names.other_names',
            self::Manufacturer => 'puzzle_report.form.brand',
            self::PiecesCount => 'puzzle_report.form.pieces_count',
            self::Ean => 'puzzle_report.form.ean',
            self::IdentificationNumber => 'puzzle_report.form.identification_number',
            self::Image => 'puzzle_report.form.photo',
            self::EanAbsent => 'puzzle_report.form.ean_absent',
        };
    }
}
