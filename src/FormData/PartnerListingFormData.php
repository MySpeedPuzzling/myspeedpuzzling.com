<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Value\CountryLanguage;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "List me" / "Edit listing" in the partner finder (docs/features/partner-finder.md, "Listing yourself"). The rules live
 * in PartnerListing and the handlers (PartnerListingInvalid lands on the field it names); the form only asks.
 */
final class PartnerListingFormData
{
    public const string ALONE = 'alone';
    public const string GROUP = 'group';

    // Made when the form is shown, kept through a 422 - a resent form saves nothing twice
    public string $listingId = '';

    public string $iAm = self::ALONE;

    #[Assert\Range(min: 2, max: PartnerListing::GROUP_SIZE_MAX)]
    public null|int $groupSize = 2;

    /** @var list<string> PartnerKind values - none is refused by PartnerListing (the error lands on this field) */
    public array $lookingFor = [];

    #[Assert\Range(min: 1, max: PartnerListing::GROUP_SIZE_MAX)]
    public null|int $needed = null;

    public null|PartnerGoal $goal = null;

    /** @var list<string> PartnerStrength values */
    public array $strengths = [];

    // Comma-separated as typed - partner_strengths_controller.js turns them into chips
    public null|string $customStrengths = null;

    /** @var list<string> ISO 639-1 codes */
    public array $languages = [];

    #[Assert\Length(max: PartnerListing::NOTE_MAX_LENGTH)]
    public null|string $note = null;

    /**
     * A new listing: the kinds the event offers when only one, the team size's "need" for a group, the languages of
     * the player's country plus English.
     *
     * @param list<PartnerKind> $offeredKinds
     */
    public static function forNewListing(string $listingId, array $offeredKinds, null|int $teamSize, null|string $countryCode): self
    {
        $data = new self();
        $data->listingId = $listingId;
        $data->lookingFor = count($offeredKinds) === 1 ? [$offeredKinds[0]->value] : [];
        $data->needed = $teamSize !== null && $teamSize > 2 ? $teamSize - 2 : null;

        $languages = [];

        foreach ([CountryLanguage::of($countryCode), 'en'] as $language) {
            if ($language !== null && strlen($language) === 2 && Languages::exists($language) && in_array($language, $languages, true) === false) {
                $languages[] = $language;
            }
        }

        $data->languages = $languages;

        return $data;
    }

    public static function fromListing(PartnerListing $listing): self
    {
        $data = new self();
        $data->listingId = $listing->id->toString();
        $data->iAm = $listing->isGroup() ? self::GROUP : self::ALONE;
        $data->groupSize = $listing->isGroup() ? $listing->groupSize : 2;
        $data->lookingFor = array_map(static fn (PartnerKind $kind): string => $kind->value, $listing->lookingFor());
        $data->needed = $listing->needed;
        $data->goal = $listing->goal;
        $data->strengths = $listing->strengths;
        $data->customStrengths = $listing->customStrengths() === [] ? null : implode(', ', $listing->customStrengths());
        $data->languages = $listing->languages();
        $data->note = $listing->note;

        return $data;
    }

    public function isGroup(): bool
    {
        return $this->iAm === self::GROUP;
    }

    public function groupSizeValue(): int
    {
        return $this->isGroup() ? ($this->groupSize ?? 2) : 1;
    }

    public function neededValue(): null|int
    {
        return $this->isGroup() ? $this->needed : null;
    }

    /**
     * @return list<PartnerKind>
     */
    public function kinds(): array
    {
        return array_values(array_filter(array_map(PartnerKind::tryFrom(...), $this->lookingFor)));
    }

    /**
     * @return list<string>
     */
    public function customStrengthList(): array
    {
        if ($this->customStrengths === null) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $this->customStrengths)), static fn (string $chip): bool => $chip !== ''));
    }

    public function noteValue(): null|string
    {
        $note = $this->note !== null ? trim($this->note) : '';

        return $note === '' ? null : $note;
    }
}
