<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Results\PartnerStrengthsNormalized;
use SpeedPuzzling\Web\Value\PartnerStrength;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * The "Good at" chips of a listing as stored (docs/features/partner-finder.md D3). Presets are PartnerStrength values,
 * kept in the order picked, each once. A custom chip is trimmed with its whitespace collapsed; an empty one is dropped;
 * one longer than 30 characters or holding `<`, `>` or a control character is refused; one that reads like a preset
 * (its label in any locale, or its value) becomes that preset; customs folding equal keep the first spelling. Too many
 * of either is refused - never cut silently.
 */
readonly final class PartnerStrengthNormalizer
{
    public function __construct(
        private PartnerStrengthCatalogue $catalogue,
    ) {
    }

    /**
     * @param list<string> $presets PartnerStrength values
     * @param list<string> $custom as typed
     *
     * @throws PartnerListingInvalid
     */
    public function normalize(array $presets, array $custom): PartnerStrengthsNormalized
    {
        $strengths = [];

        foreach ($presets as $value) {
            $strength = PartnerStrength::tryFrom(trim($value)) ?? throw PartnerListingInvalid::unknownStrength($value);

            if (in_array($strength, $strengths, true) === false) {
                $strengths[] = $strength;
            }
        }

        $chips = [];

        foreach ($custom as $chip) {
            $chip = trim(preg_replace('/[\s\p{Z}]+/u', ' ', mb_scrub($chip, 'UTF-8')) ?? '');

            if ($chip === '') {
                continue;
            }

            if (preg_match('/[<>\p{Cc}\p{Cf}]/u', $chip) === 1) {
                throw PartnerListingInvalid::customStrengthCharacters();
            }

            if (mb_strlen($chip) > PartnerStrengthsNormalized::MAX_CUSTOM_LENGTH) {
                throw PartnerListingInvalid::customStrengthTooLong($chip, PartnerStrengthsNormalized::MAX_CUSTOM_LENGTH);
            }

            $preset = $this->catalogue->find($chip);

            if ($preset !== null) {
                if (in_array($preset, $strengths, true) === false) {
                    $strengths[] = $preset;
                }

                continue;
            }

            $folded = SearchText::fold($chip);

            // Nothing but apostrophes or marks - nothing to show
            if ($folded === '') {
                continue;
            }

            $chips[$folded] ??= $chip;
        }

        if (count($strengths) > PartnerStrengthsNormalized::MAX_PRESETS) {
            throw PartnerListingInvalid::tooManyStrengths(PartnerStrengthsNormalized::MAX_PRESETS);
        }

        if (count($chips) > PartnerStrengthsNormalized::MAX_CUSTOM) {
            throw PartnerListingInvalid::tooManyCustomStrengths(PartnerStrengthsNormalized::MAX_CUSTOM);
        }

        return new PartnerStrengthsNormalized($strengths, array_values($chips));
    }
}
