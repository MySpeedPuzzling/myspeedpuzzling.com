<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Exceptions\PartnerFinderNotAvailable;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Query\GetAllowedPartnerCoMembers;
use SpeedPuzzling\Web\Query\PartnerFinderEligibility;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
use SpeedPuzzling\Web\Results\PartnerStrengthsNormalized;
use SpeedPuzzling\Web\Value\PartnerKind;
use Symfony\Component\Intl\Languages;

/**
 * What ListForPartner and EditPartnerListing check before anything changes (docs/features/partner-finder.md), on top of
 * the rules PartnerListing checks itself: the event has a partner finder and offers every kind looked for, the "Good at"
 * chips (PartnerStrengthNormalizer), the co-members exist and the lister may name them, the languages are ISO 639-1 codes.
 */
readonly final class PartnerListingRules
{
    public function __construct(
        private PartnerFinderEligibility $eligibility,
        private PartnerStrengthNormalizer $strengthNormalizer,
        private GetAllowedPartnerCoMembers $getAllowedPartnerCoMembers,
    ) {
    }

    /**
     * @throws PartnerFinderNotAvailable
     */
    public function offer(string $competitionId): PartnerFinderOffer
    {
        return $this->eligibility->qualifyingOffer($competitionId) ?? throw new PartnerFinderNotAvailable();
    }

    /**
     * @param list<PartnerKind> $lookingFor
     *
     * @throws PartnerListingInvalid
     */
    public function checkKinds(PartnerFinderOffer $offer, array $lookingFor): void
    {
        foreach ($lookingFor as $kind) {
            // The form offers only the event's kinds - anything else was not asked by the form
            if ($offer->offers($kind) === false) {
                throw PartnerListingInvalid::lookingFor();
            }
        }
    }

    /**
     * @param list<string> $presets
     * @param list<string> $custom
     *
     * @throws PartnerListingInvalid
     */
    public function strengths(array $presets, array $custom): PartnerStrengthsNormalized
    {
        return $this->strengthNormalizer->normalize($presets, $custom);
    }

    /**
     * An edit's co-members: the ones the listing had stay while they are still allowed (one who blocked the lister or
     * went private since drops out quietly - the edit must not get stuck on them), the newly named ones are checked
     * like coMembers()
     *
     * @param list<string> $before the listing's co-member ids
     * @param list<string> $coMemberIds what the edit names
     *
     * @return list<string>
     *
     * @throws PartnerListingInvalid
     */
    public function editedCoMembers(string $listerId, array $before, array $coMemberIds): array
    {
        $before = array_map(strtolower(...), $before);
        $named = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => strtolower(trim($id)), $coMemberIds),
            static fn (string $id): bool => $id !== '' && $id !== strtolower($listerId),
        )));

        $kept = array_values(array_intersect($named, $before));
        $stillAllowed = $kept === [] ? [] : $this->getAllowedPartnerCoMembers->of($listerId, $kept);
        $added = $this->coMembers($listerId, array_values(array_diff($named, $before)));

        // In the order named
        return array_values(array_filter($named, static fn (string $id): bool => in_array($id, $stillAllowed, true) || in_array($id, $added, true)));
    }

    /**
     * Lower case, each once, the lister himself left out - every one must be a player the lister may name.
     *
     * @param list<string> $coMemberIds
     *
     * @return list<string>
     *
     * @throws PartnerListingInvalid
     */
    public function coMembers(string $listerId, array $coMemberIds): array
    {
        $ids = [];

        foreach ($coMemberIds as $coMemberId) {
            $coMemberId = strtolower(trim($coMemberId));

            if ($coMemberId !== '' && $coMemberId !== strtolower($listerId) && in_array($coMemberId, $ids, true) === false) {
                $ids[] = $coMemberId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $allowed = $this->getAllowedPartnerCoMembers->of($listerId, $ids);

        if (count(array_diff($ids, $allowed)) > 0) {
            throw PartnerListingInvalid::coMembers();
        }

        return $ids;
    }

    /**
     * @param list<string> $languages
     *
     * @return list<string> lower case ISO 639-1 codes, each once
     *
     * @throws PartnerListingInvalid
     */
    public function languages(array $languages): array
    {
        $codes = [];

        foreach ($languages as $language) {
            $code = strtolower(trim($language));

            if ($code === '') {
                continue;
            }

            if (preg_match('/^[a-z]{2}$/', $code) !== 1 || Languages::exists($code) === false) {
                throw PartnerListingInvalid::languages(PartnerListing::MAX_LANGUAGES);
            }

            if (in_array($code, $codes, true) === false) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
