<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which fields a change request really changes: its proposed value differs from its own original (the puzzle when it
 * was filed). A change request stores a whole snapshot - the name, the brand and the pieces always, older ones the codes
 * too, changed or not - so "proposed" alone says nothing: a field it left as it was is no change, and approving it must
 * keep the puzzle's value of now (another proposal may have changed it since).
 *
 * The one rule for the lock (GetPendingPuzzleProposals::locks()), the approval (ApprovePuzzleChangeRequestHandler), the
 * "already applied" check (PuzzleChangeRequest::nothingLeftToApply()) and the review pages (PuzzleChangeRequestOverview).
 */
readonly final class PuzzleProposalChanges
{
    public function __construct(
        public null|string $proposedName,
        public string $originalName,
        public null|string $proposedManufacturerId,
        public null|string $originalManufacturerId,
        public null|int $proposedPiecesCount,
        public int $originalPiecesCount,
        public null|string $proposedEan,
        public null|string $originalEan,
        public null|string $proposedIdentificationNumber,
        public null|string $originalIdentificationNumber,
        public null|string $proposedImage,
        public null|PuzzleNames $proposedAlternativeNames,
        public null|PuzzleNames $originalAlternativeNames,
        public null|string $proposedNameLanguage,
        public null|string $originalNameLanguage,
    ) {
    }

    public function changes(PuzzleProposalField $field): bool
    {
        return match ($field) {
            PuzzleProposalField::Name => $this->proposedName !== null && $this->proposedName !== $this->originalName,
            // Proposed only together with the other names
            PuzzleProposalField::NameLanguage => $this->proposedAlternativeNames !== null
                && $this->proposedNameLanguage !== $this->originalNameLanguage,
            PuzzleProposalField::AlternativeNames => $this->proposedAlternativeNames !== null
                && $this->proposedAlternativeNames->diff($this->originalAlternativeNames ?? new PuzzleNames())->isEmpty() === false,
            PuzzleProposalField::Manufacturer => $this->proposedManufacturerId !== null
                && strtolower($this->proposedManufacturerId) !== strtolower($this->originalManufacturerId ?? ''),
            PuzzleProposalField::PiecesCount => $this->proposedPiecesCount !== null
                && $this->proposedPiecesCount !== $this->originalPiecesCount,
            // Compared as lists: older requests hold whatever the form had, formatted differently or not changed at all
            PuzzleProposalField::Ean => $this->proposedEan !== null
                && EanList::fromStored($this->proposedEan)->equals(EanList::fromStored($this->originalEan)) === false,
            PuzzleProposalField::IdentificationNumber => $this->proposedIdentificationNumber !== null
                && BrandCodeList::fromStored($this->proposedIdentificationNumber)->equals(BrandCodeList::fromStored($this->originalIdentificationNumber)) === false,
            PuzzleProposalField::Image => $this->proposedImage !== null,
        };
    }

    /**
     * @return list<PuzzleProposalField>
     */
    public function changedFields(): array
    {
        return array_values(array_filter(PuzzleProposalField::cases(), $this->changes(...)));
    }

    /**
     * @return list<PuzzleProposalField>
     */
    public function lockedFields(): array
    {
        return array_values(array_filter($this->changedFields(), static fn (PuzzleProposalField $field): bool => $field->locksWhilePending()));
    }
}
