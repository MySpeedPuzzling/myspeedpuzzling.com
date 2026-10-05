<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleNamesDiff;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

readonly final class PuzzleChangeRequestOverview
{
    public function __construct(
        public string $id,
        public PuzzleReportStatus $status,
        public DateTimeImmutable $submittedAt,
        public null|DateTimeImmutable $reviewedAt,
        public null|string $rejectionReason,
        public string $puzzleId,
        public string $puzzleName,
        public int $puzzlePiecesCount,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public null|string $puzzleManufacturerName,
        public PuzzleNames $puzzleAlternativeNames,
        // The main title's language when the box has no English title
        public null|string $puzzleNameLanguage,
        // The puzzle's record as it is now (PuzzleRecordVersion) - what the review form sends back
        public string $puzzleRecordVersion,
        public null|string $puzzleManufacturerId,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
        public string $reporterId,
        public null|string $reporterName,
        public null|string $reporterCode,
        public null|string $reviewerId,
        public null|string $reviewerName,
        public null|string $proposedName,
        public null|string $proposedManufacturerId,
        public null|string $proposedManufacturerName,
        public null|int $proposedPiecesCount,
        public null|string $proposedEan,
        public null|string $proposedIdentificationNumber,
        public null|string $proposedImage,
        // The other names as proposed - null = the names are not part of the proposal
        public null|PuzzleNames $proposedAlternativeNames,
        // The main title's language as proposed, together with the other names only
        public null|string $proposedNameLanguage,
        public null|string $originalName,
        public null|string $originalManufacturerId,
        public null|string $originalManufacturerName,
        public null|int $originalPiecesCount,
        public null|string $originalEan,
        public null|string $originalIdentificationNumber,
        public null|string $originalImage,
        // Null on requests older than the names list
        public null|PuzzleNames $originalAlternativeNames,
        public null|string $originalNameLanguage,
        // The brand the proposal created (PuzzleChangeRequest::$createdManufacturerName) - kept when it was deleted unused
        public null|string $createdManufacturerName = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $id = $row['id'];
        assert(is_string($id));
        $status = $row['status'];
        assert(is_string($status));
        $submittedAt = $row['submitted_at'];
        assert(is_string($submittedAt));
        $reviewedAt = $row['reviewed_at'];
        assert(is_string($reviewedAt) || $reviewedAt === null);
        $puzzleId = $row['puzzle_id'];
        assert(is_string($puzzleId));
        $puzzleName = $row['puzzle_name'];
        assert(is_string($puzzleName));
        $puzzlePiecesCount = $row['puzzle_pieces_count'];
        assert(is_int($puzzlePiecesCount));
        $reporterId = $row['reporter_id'];
        assert(is_string($reporterId));
        $puzzleAlternativeNames = PuzzleNames::fromJson(is_string($row['puzzle_alternative_names'] ?? null) ? $row['puzzle_alternative_names'] : null);
        $puzzleNameLanguage = is_string($row['puzzle_name_language'] ?? null) ? $row['puzzle_name_language'] : null;
        $puzzleManufacturerId = is_string($row['puzzle_manufacturer_id'] ?? null) ? $row['puzzle_manufacturer_id'] : null;
        $puzzleEan = is_string($row['puzzle_ean'] ?? null) ? $row['puzzle_ean'] : null;
        $puzzleIdentificationNumber = is_string($row['puzzle_identification_number'] ?? null) ? $row['puzzle_identification_number'] : null;

        return new self(
            id: $id,
            status: PuzzleReportStatus::from($status),
            submittedAt: new DateTimeImmutable($submittedAt),
            reviewedAt: $reviewedAt !== null ? new DateTimeImmutable($reviewedAt) : null,
            rejectionReason: is_string($row['rejection_reason']) ? $row['rejection_reason'] : null,
            puzzleId: $puzzleId,
            puzzleName: $puzzleName,
            puzzlePiecesCount: $puzzlePiecesCount,
            puzzleImage: is_string($row['puzzle_image']) ? $row['puzzle_image'] : null,
            puzzleImageRatio: is_numeric($row['puzzle_image_ratio'] ?? null) ? (float) $row['puzzle_image_ratio'] : null,
            puzzleManufacturerName: is_string($row['puzzle_manufacturer_name']) ? $row['puzzle_manufacturer_name'] : null,
            puzzleAlternativeNames: $puzzleAlternativeNames,
            puzzleNameLanguage: $puzzleNameLanguage,
            // The image as stored - `puzzle_image` hides one under embargo
            puzzleRecordVersion: PuzzleRecordVersion::of(
                name: $puzzleName,
                nameLanguage: $puzzleNameLanguage,
                alternativeNames: $puzzleAlternativeNames,
                manufacturerId: $puzzleManufacturerId,
                piecesCount: $puzzlePiecesCount,
                ean: $puzzleEan,
                identificationNumber: $puzzleIdentificationNumber,
                image: is_string($row['puzzle_record_image'] ?? null) ? $row['puzzle_record_image'] : null,
            ),
            puzzleManufacturerId: $puzzleManufacturerId,
            puzzleEan: $puzzleEan,
            puzzleIdentificationNumber: $puzzleIdentificationNumber,
            reporterId: $reporterId,
            reporterName: is_string($row['reporter_name']) ? $row['reporter_name'] : null,
            reporterCode: is_string($row['reporter_code']) ? $row['reporter_code'] : null,
            reviewerId: is_string($row['reviewer_id']) ? $row['reviewer_id'] : null,
            reviewerName: is_string($row['reviewer_name']) ? $row['reviewer_name'] : null,
            proposedName: is_string($row['proposed_name']) ? $row['proposed_name'] : null,
            proposedManufacturerId: is_string($row['proposed_manufacturer_id']) ? $row['proposed_manufacturer_id'] : null,
            proposedManufacturerName: is_string($row['proposed_manufacturer_name']) ? $row['proposed_manufacturer_name'] : null,
            proposedPiecesCount: is_int($row['proposed_pieces_count']) ? $row['proposed_pieces_count'] : null,
            proposedEan: is_string($row['proposed_ean']) ? $row['proposed_ean'] : null,
            proposedIdentificationNumber: is_string($row['proposed_identification_number']) ? $row['proposed_identification_number'] : null,
            proposedImage: is_string($row['proposed_image']) ? $row['proposed_image'] : null,
            proposedAlternativeNames: is_string($row['proposed_alternative_names'] ?? null) ? PuzzleNames::fromJson($row['proposed_alternative_names']) : null,
            proposedNameLanguage: is_string($row['proposed_name_language'] ?? null) ? $row['proposed_name_language'] : null,
            originalName: is_string($row['original_name']) ? $row['original_name'] : null,
            originalManufacturerId: is_string($row['original_manufacturer_id']) ? $row['original_manufacturer_id'] : null,
            originalManufacturerName: is_string($row['original_manufacturer_name']) ? $row['original_manufacturer_name'] : null,
            originalPiecesCount: is_int($row['original_pieces_count']) ? $row['original_pieces_count'] : null,
            originalEan: is_string($row['original_ean']) ? $row['original_ean'] : null,
            originalIdentificationNumber: is_string($row['original_identification_number']) ? $row['original_identification_number'] : null,
            originalImage: is_string($row['original_image']) ? $row['original_image'] : null,
            originalAlternativeNames: is_string($row['original_alternative_names'] ?? null) ? PuzzleNames::fromJson($row['original_alternative_names']) : null,
            originalNameLanguage: is_string($row['original_name_language'] ?? null) ? $row['original_name_language'] : null,
            createdManufacturerName: is_string($row['created_manufacturer_name'] ?? null) ? $row['created_manufacturer_name'] : null,
        );
    }

    public function hasNameChange(): bool
    {
        return $this->proposedName !== null && $this->proposedName !== $this->originalName;
    }

    /**
     * What the proposal does to the other names (PuzzleNames::diff() of the proposed list against the list when
     * proposed) - empty when it proposes none.
     */
    public function proposedNamesDiff(): PuzzleNamesDiff
    {
        if ($this->proposedAlternativeNames === null) {
            return new PuzzleNamesDiff([], [], []);
        }

        return $this->proposedAlternativeNames->diff($this->originalAlternativeNames ?? new PuzzleNames());
    }

    public function hasAlternativeNamesChange(): bool
    {
        return $this->proposedNamesDiff()->isEmpty() === false;
    }

    public function hasNameLanguageChange(): bool
    {
        return $this->proposedAlternativeNames !== null && $this->proposedNameLanguage !== $this->originalNameLanguage;
    }

    /**
     * The other names the review starts from: the puzzle's names now with the proposal applied as a diff - names
     * changed by somebody else since it was proposed stay.
     */
    public function reviewAlternativeNames(): PuzzleNames
    {
        return $this->proposedNamesDiff()->applyTo($this->puzzleAlternativeNames);
    }

    public function reviewNameLanguage(): null|string
    {
        return $this->hasNameLanguageChange() ? $this->proposedNameLanguage : $this->puzzleNameLanguage;
    }

    public function hasNamesProposal(): bool
    {
        return $this->hasNameChange() || $this->hasNameLanguageChange() || $this->hasAlternativeNamesChange();
    }

    /**
     * What the player proposed for the names, one line each - "Kruh barev: Mušle (Czech)". The admin pages are English.
     *
     * @return list<array{kind: 'main_title'|'main_title_language'|'added'|'changed'|'removed', before: null|string, after: null|string}>
     */
    public function proposedNameChanges(): array
    {
        $changes = [];

        if ($this->hasNameChange()) {
            $changes[] = ['kind' => 'main_title', 'before' => $this->originalName, 'after' => $this->proposedName];
        }

        if ($this->hasNameLanguageChange()) {
            $changes[] = [
                'kind' => 'main_title_language',
                'before' => $this->originalNameLanguage !== null ? PuzzleNameLanguageChoices::label($this->originalNameLanguage, 'en') : null,
                'after' => $this->proposedNameLanguage !== null ? PuzzleNameLanguageChoices::label($this->proposedNameLanguage, 'en') : null,
            ];
        }

        $diff = $this->proposedNamesDiff();

        foreach ($diff->added as $added) {
            $changes[] = ['kind' => 'added', 'before' => null, 'after' => self::shown($added)];
        }

        foreach ($diff->changed as $changed) {
            $changes[] = ['kind' => 'changed', 'before' => self::shown($changed['from']), 'after' => self::shown($changed['to'])];
        }

        foreach ($diff->removed as $removed) {
            $changes[] = ['kind' => 'removed', 'before' => self::shown($removed), 'after' => null];
        }

        return $changes;
    }

    private static function shown(PuzzleName $name): string
    {
        return $name->name . ($name->language !== null ? ' (' . PuzzleNameLanguageChoices::label($name->language, 'en') . ')' : '');
    }

    public function hasManufacturerChange(): bool
    {
        return $this->proposedManufacturerId !== null && $this->proposedManufacturerId !== $this->originalManufacturerId;
    }

    /**
     * The proposal created the brand it proposes - a name the player typed. Approving it approves the brand.
     */
    public function proposesCreatedManufacturer(): bool
    {
        return $this->createdManufacturerName !== null && $this->proposedManufacturerId !== null;
    }

    /**
     * The proposed brand's name - also of a brand the proposal created and the review deleted unused.
     */
    public function proposedManufacturerShownName(): null|string
    {
        return $this->proposedManufacturerName ?? $this->createdManufacturerName;
    }

    public function hasPiecesCountChange(): bool
    {
        return $this->proposedPiecesCount !== null && $this->proposedPiecesCount !== $this->originalPiecesCount;
    }

    public function hasEanChange(): bool
    {
        return $this->proposedEan !== null && $this->proposedEan !== $this->originalEan;
    }

    public function hasIdentificationNumberChange(): bool
    {
        return $this->proposedIdentificationNumber !== null && $this->proposedIdentificationNumber !== $this->originalIdentificationNumber;
    }

    public function hasImageChange(): bool
    {
        return $this->proposedImage !== null;
    }

    public function hasAnyChange(): bool
    {
        return $this->hasNamesProposal()
            || $this->hasManufacturerChange()
            || $this->hasPiecesCountChange()
            || $this->hasEanChange()
            || $this->hasIdentificationNumberChange()
            || $this->hasImageChange();
    }
}
