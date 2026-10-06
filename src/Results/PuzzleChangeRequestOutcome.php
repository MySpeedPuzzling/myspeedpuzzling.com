<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\ProposedChangeResult;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

/**
 * What the review of a decided change request did: every proposed change applied, saved differently or left out,
 * and what the reviewer changed on top of the proposal. Read from the approval's line in the decision log - the
 * puzzle before and after (recorded since 2026-10-04), or for older internal API approvals the fields applied as
 * proposed. Older approvals recorded neither: the proposal is shown without a result.
 */
readonly final class PuzzleChangeRequestOutcome
{
    /**
     * @param list<ProposedChangeOutcome> $names The proposed changes of the names, one line each
     * @param list<ProposedChangeOutcome> $fields The other proposed changes
     * @param list<PuzzleHistoryChange> $reviewerChanges What the approval changed beyond the proposal
     */
    public function __construct(
        public array $names,
        public array $fields,
        public array $reviewerChanges,
        // Whether the approval recorded what it applied
        public bool $recorded,
    ) {
    }

    /**
     * @param null|array<mixed> $details The approval's details in the decision log - null for a rejection, or when
     *                                   the approval has no line there
     */
    public static function of(PuzzleChangeRequestOverview $request, null|array $details): self
    {
        $before = is_array($details['before'] ?? null) ? $details['before'] : null;
        $after = is_array($details['after'] ?? null) ? $details['after'] : null;
        $selectedFields = is_array($details['selectedFields'] ?? null) ? $details['selectedFields'] : null;
        $overrides = is_array($details['overrides'] ?? null) ? $details['overrides'] : [];
        $imageChoice = PuzzleImageChoice::tryFrom(is_string($details['image'] ?? null) ? $details['image'] : '');

        if ($request->status !== PuzzleReportStatus::Rejected && $before !== null && $after !== null) {
            return self::fromSnapshots($request, $before, $after, $imageChoice);
        }

        if ($request->status === PuzzleReportStatus::Rejected) {
            $judge = static fn (string $field): ProposedChangeResult => ProposedChangeResult::NotApplied;
        } elseif ($selectedFields !== null) {
            // Applied as proposed, unless the internal API call gave the value to apply instead
            $judge = static fn (string $field): null|ProposedChangeResult => match (true) {
                array_key_exists($field, $overrides) => null,
                in_array($field, $selectedFields, true) => ProposedChangeResult::Applied,
                default => ProposedChangeResult::NotApplied,
            };
        } else {
            $judge = static fn (string $field): null|ProposedChangeResult => null;
        }

        $names = [];

        foreach ($request->proposedNameChanges() as $change) {
            $field = match ($change['kind']) {
                'main_title' => 'name',
                'main_title_language' => 'nameLanguage',
                default => 'alternativeNames',
            };
            $names[] = new ProposedChangeOutcome(self::nameLabel($change['kind']), $change['before'], $change['after'], $judge($field), nameKind: $change['kind']);
        }

        $fields = [];

        foreach (self::proposedFields($request) as $field => [$label, $original, $proposed, $isImage]) {
            $fields[] = new ProposedChangeOutcome($label, $original, $proposed, $judge($field), image: $isImage);
        }

        return new self($names, $fields, [], recorded: $request->status === PuzzleReportStatus::Rejected || $selectedFields !== null);
    }

    public function appliedCount(): int
    {
        return count(array_filter($this->all(), static fn (ProposedChangeOutcome $change): bool => $change->result === ProposedChangeResult::Applied));
    }

    public function alteredCount(): int
    {
        return count(array_filter($this->all(), static fn (ProposedChangeOutcome $change): bool => $change->result === ProposedChangeResult::Altered));
    }

    public function notAppliedCount(): int
    {
        return count(array_filter($this->all(), static fn (ProposedChangeOutcome $change): bool => $change->result === ProposedChangeResult::NotApplied));
    }

    public function proposedCount(): int
    {
        return count($this->all());
    }

    /**
     * @return list<ProposedChangeOutcome>
     */
    private function all(): array
    {
        return [...$this->names, ...$this->fields];
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private static function fromSnapshots(
        PuzzleChangeRequestOverview $request,
        array $before,
        array $after,
        null|PuzzleImageChoice $imageChoice,
    ): self {
        $names = [];
        $afterNames = PuzzleNames::fromArray(is_array($after['alternativeNames'] ?? null) ? $after['alternativeNames'] : []);

        if ($request->hasNameChange()) {
            [$result, $saved] = self::judge($request->proposedName, self::text($before['name'] ?? null), self::text($after['name'] ?? null));
            $names[] = new ProposedChangeOutcome(self::nameLabel('main_title'), $request->originalName, $request->proposedName, $result, $saved, nameKind: 'main_title');
        }

        if ($request->hasNameLanguageChange()) {
            [$result, $saved] = self::judge($request->proposedNameLanguage, self::text($before['nameLanguage'] ?? null), self::text($after['nameLanguage'] ?? null));
            $names[] = new ProposedChangeOutcome(
                self::nameLabel('main_title_language'),
                self::languageLabel($request->originalNameLanguage),
                self::languageLabel($request->proposedNameLanguage),
                $result,
                $result === ProposedChangeResult::Altered ? self::languageLabel($saved) : null,
                nameKind: 'main_title_language',
            );
        }

        $diff = $request->proposedNamesDiff();

        foreach ($diff->added as $added) {
            $kept = self::sameName($afterNames, $added);
            $result = match (true) {
                $kept === null => ProposedChangeResult::NotApplied,
                self::equal($kept, $added) => ProposedChangeResult::Applied,
                default => ProposedChangeResult::Altered,
            };
            $names[] = new ProposedChangeOutcome(self::nameLabel('added'), null, self::shown($added), $result, self::altered($result, $kept), nameKind: 'added');
        }

        foreach ($diff->changed as $changed) {
            $kept = self::sameName($afterNames, $changed['to']);
            $result = match (true) {
                $kept !== null && self::equal($kept, $changed['to']) => ProposedChangeResult::Applied,
                $kept !== null => ProposedChangeResult::Altered,
                default => ProposedChangeResult::NotApplied,
            };
            $names[] = new ProposedChangeOutcome(self::nameLabel('changed'), self::shown($changed['from']), self::shown($changed['to']), $result, self::altered($result, $kept), nameKind: 'changed');
        }

        foreach ($diff->removed as $removed) {
            $result = self::sameName($afterNames, $removed) === null ? ProposedChangeResult::Applied : ProposedChangeResult::NotApplied;
            $names[] = new ProposedChangeOutcome(self::nameLabel('removed'), self::shown($removed), null, $result, nameKind: 'removed');
        }

        $fields = [];

        foreach (self::proposedFields($request) as $field => [$label, $original, $proposed, $isImage]) {
            [$result, $saved] = match ($field) {
                'manufacturer' => self::judge(
                    $request->proposedManufacturerId,
                    self::text($before['manufacturerId'] ?? null),
                    self::text($after['manufacturerId'] ?? null),
                    self::text($after['manufacturerName'] ?? null),
                ),
                'piecesCount' => self::judge($proposed, self::text($before['piecesCount'] ?? null), self::text($after['piecesCount'] ?? null)),
                'ean' => self::judgeCodes(
                    EanList::fromStored($request->proposedEan)->display(),
                    EanList::fromStored(self::text($before['ean'] ?? null))->display(),
                    EanList::fromStored(self::text($after['ean'] ?? null))->display(),
                ),
                'identificationNumber' => self::judgeCodes(
                    BrandCodeList::fromStored($request->proposedIdentificationNumber)->display(),
                    BrandCodeList::fromStored(self::text($before['identificationNumber'] ?? null))->display(),
                    BrandCodeList::fromStored(self::text($after['identificationNumber'] ?? null))->display(),
                ),
                // The proposed image is moved into the puzzle when applied - shown from where it is now
                'image' => match ($imageChoice) {
                    PuzzleImageChoice::Proposed => [ProposedChangeResult::Applied, null],
                    PuzzleImageChoice::Upload => [ProposedChangeResult::Altered, self::text($after['image'] ?? null)],
                    default => [ProposedChangeResult::NotApplied, null],
                },
                default => [null, null],
            };

            if ($field === 'image' && $result === ProposedChangeResult::Applied) {
                $proposed = self::text($after['image'] ?? null) ?? $proposed;
            }

            $fields[] = new ProposedChangeOutcome($label, $original, $proposed, $result, $saved, image: $isImage);
        }

        return new self($names, $fields, self::reviewerChanges($request, $before, $after), recorded: true);
    }

    /**
     * What the approval changed that the player did not propose. The other names count as the reviewer's only when
     * they ended up other than the proposal applied to them.
     *
     * @param array<mixed> $before
     * @param array<mixed> $after
     *
     * @return list<PuzzleHistoryChange>
     */
    private static function reviewerChanges(PuzzleChangeRequestOverview $request, array $before, array $after): array
    {
        $proposedLabels = array_map(PuzzleHistoryChange::label(...), array_keys(self::proposedFields($request)));

        if ($request->hasNameChange()) {
            $proposedLabels[] = PuzzleHistoryChange::label('name');
        }

        if ($request->hasNameLanguageChange()) {
            $proposedLabels[] = PuzzleHistoryChange::label('nameLanguage');
        }

        if ($request->hasAlternativeNamesChange()) {
            $beforeNames = PuzzleNames::fromArray(is_array($before['alternativeNames'] ?? null) ? $before['alternativeNames'] : []);
            $afterNames = PuzzleNames::fromArray(is_array($after['alternativeNames'] ?? null) ? $after['alternativeNames'] : []);

            if ($request->proposedNamesDiff()->applyTo($beforeNames)->toArray() === $afterNames->toArray()) {
                $proposedLabels[] = PuzzleHistoryChange::label('alternativeNames');
            }
        }

        return array_values(array_filter(
            PuzzleHistoryChange::between($before, $after),
            static fn (PuzzleHistoryChange $change): bool => in_array($change->label, $proposedLabels, true) === false,
        ));
    }

    /**
     * The proposed changes other than the names, by the field's key in the decision log.
     *
     * @return array<string, array{string, null|string, null|string, bool}> label, as it was, as proposed, is an image
     */
    private static function proposedFields(PuzzleChangeRequestOverview $request): array
    {
        $fields = [];

        // A brand the proposal created and the review deleted unused shows by its name
        if ($request->hasManufacturerChange() || ($request->createdManufacturerName !== null && $request->proposedManufacturerId === null)) {
            $fields['manufacturer'] = [
                PuzzleHistoryChange::label('manufacturer'),
                $request->originalManufacturerName,
                $request->proposedManufacturerShownName() . ($request->createdManufacturerName !== null ? ' (new brand)' : ''),
                false,
            ];
        }

        if ($request->hasPiecesCountChange()) {
            $fields['piecesCount'] = [
                PuzzleHistoryChange::label('piecesCount'),
                $request->originalPiecesCount !== null ? (string) $request->originalPiecesCount : null,
                (string) $request->proposedPiecesCount,
                false,
            ];
        }

        if ($request->hasEanChange()) {
            $fields['ean'] = [
                PuzzleHistoryChange::label('ean'),
                implode(', ', EanList::fromStored($request->originalEan)->display()),
                implode(', ', EanList::fromStored($request->proposedEan)->display()),
                false,
            ];
        }

        if ($request->hasIdentificationNumberChange()) {
            $fields['identificationNumber'] = [
                PuzzleHistoryChange::label('identificationNumber'),
                implode(', ', BrandCodeList::fromStored($request->originalIdentificationNumber)->display()),
                implode(', ', BrandCodeList::fromStored($request->proposedIdentificationNumber)->display()),
                false,
            ];
        }

        if ($request->hasImageChange()) {
            $fields['image'] = [PuzzleHistoryChange::label('image'), $request->originalImage, $request->proposedImage, true];
        }

        return $fields;
    }

    /**
     * @return array{null|ProposedChangeResult, null|string} The result, and what was saved instead
     */
    private static function judge(null|string|int $proposed, null|string $before, null|string $after, null|string $shownAfter = null): array
    {
        $proposed = $proposed !== null ? (string) $proposed : null;

        return match (true) {
            $after === $proposed => [ProposedChangeResult::Applied, null],
            $after === $before => [ProposedChangeResult::NotApplied, null],
            default => [ProposedChangeResult::Altered, $shownAfter ?? $after],
        };
    }

    /**
     * @param list<string> $proposed
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return array{ProposedChangeResult, null|string}
     */
    private static function judgeCodes(array $proposed, array $before, array $after): array
    {
        return match (true) {
            $after === $proposed => [ProposedChangeResult::Applied, null],
            $after === $before => [ProposedChangeResult::NotApplied, null],
            default => [ProposedChangeResult::Altered, implode(', ', $after)],
        };
    }

    /**
     * The name of the list spelled the same, whatever its language.
     */
    private static function sameName(PuzzleNames $names, PuzzleName $name): null|PuzzleName
    {
        foreach ($names->all() as $candidate) {
            if (mb_strtolower($candidate->name) === mb_strtolower($name->name)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function equal(PuzzleName $a, PuzzleName $b): bool
    {
        return $a->name === $b->name && $a->language === $b->language;
    }

    private static function altered(ProposedChangeResult $result, null|PuzzleName $kept): null|string
    {
        return $result === ProposedChangeResult::Altered && $kept !== null ? self::shown($kept) : null;
    }

    private static function nameLabel(string $kind): string
    {
        return match ($kind) {
            'main_title' => 'Main title',
            'main_title_language' => 'Main title language',
            'added' => 'Added',
            'changed' => 'Changed',
            default => 'Removed',
        };
    }

    // The admin pages are English
    private static function shown(PuzzleName $name): string
    {
        return $name->name . ($name->language !== null ? ' (' . PuzzleNameLanguageChoices::label($name->language, 'en') . ')' : '');
    }

    private static function languageLabel(null|string $language): null|string
    {
        return $language !== null ? PuzzleNameLanguageChoices::label($language, 'en') : null;
    }

    private static function text(mixed $value): null|string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
