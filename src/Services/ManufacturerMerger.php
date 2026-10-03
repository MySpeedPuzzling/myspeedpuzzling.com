<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\ManufacturerSlugRedirect;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\ManufacturerSlugRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;

/**
 * Folds a duplicate brand into another one - the one place that does it, for the
 * approval queue and the internal API alike (docs/features/brand-duplicates.md).
 *
 * Its puzzles and the change requests proposing it move over, what only the
 * duplicate knew is kept (logo, EAN prefixes, approval), its slug keeps answering
 * as a redirect to the survivor, and the duplicate is deleted. A puzzle and a
 * change request proposal are the only things that reference a brand - a new
 * reference must be moved here too. Callers validate first: this only applies.
 */
readonly final class ManufacturerMerger
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private ManufacturerRepository $manufacturerRepository,
        private ManufacturerSlugRedirectRepository $manufacturerSlugRedirectRepository,
        private CatalogueStatsProvider $catalogueStatsProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{movedPuzzles: int, movedChangeRequests: int, redirectedSlugs: list<string>, approvedByMerge: bool}
     */
    public function merge(Manufacturer $duplicate, Manufacturer $into): array
    {
        if ($duplicate->id->equals($into->id)) {
            throw new \LogicException('A brand cannot be merged into itself.');
        }

        $puzzles = $this->puzzleRepository->findByManufacturer($duplicate);

        foreach ($puzzles as $puzzle) {
            $puzzle->manufacturer = $into;
        }

        $changeRequests = $this->puzzleChangeRequestRepository->findByProposedManufacturer($duplicate);

        foreach ($changeRequests as $changeRequest) {
            $changeRequest->proposedManufacturerMergedInto($into);
        }

        if ($into->logo === null && $duplicate->logo !== null) {
            $into->logo = $duplicate->logo;
        }

        $into->eanPrefix = self::unionEanPrefixes($into->eanPrefix, $duplicate->eanPrefix);

        // Like a puzzle merge: the survivor of an approved brand is approved
        $approvedByMerge = $duplicate->approved && $into->approved === false;

        if ($approvedByMerge) {
            $into->approved = true;
        }

        $redirectedSlugs = [];

        foreach ($this->manufacturerSlugRedirectRepository->findByManufacturer($duplicate) as $redirect) {
            $redirect->manufacturerMergedInto($into);
            $redirectedSlugs[] = $redirect->slug;
        }

        if ($duplicate->slug !== null) {
            $this->manufacturerSlugRedirectRepository->save(
                new ManufacturerSlugRedirect($duplicate->slug, $into, $this->clock->now()),
            );
            $redirectedSlugs[] = $duplicate->slug;
        }

        $this->manufacturerRepository->delete($duplicate);

        $this->catalogueStatsProvider->forgetBrands(array_values(array_filter(
            [...$redirectedSlugs, $into->slug],
            static fn (null|string $slug): bool => $slug !== null,
        )));

        return [
            'movedPuzzles' => count($puzzles),
            'movedChangeRequests' => count($changeRequests),
            'redirectedSlugs' => $redirectedSlugs,
            'approvedByMerge' => $approvedByMerge,
        ];
    }

    /**
     * A brand may know several company prefixes (one per country / GS1 range) as a
     * comma-separated list - a merge keeps all of them, never reduces the list.
     */
    private static function unionEanPrefixes(null|string $survivor, null|string $duplicate): null|string
    {
        $prefixes = self::prefixList($survivor);
        $added = false;

        foreach (self::prefixList($duplicate) as $prefix) {
            if (in_array($prefix, $prefixes, true) === false) {
                $prefixes[] = $prefix;
                $added = true;
            }
        }

        // Nothing new: the survivor's value stays exactly as it was written
        return $added ? implode(', ', $prefixes) : $survivor;
    }

    /**
     * @return list<string>
     */
    private static function prefixList(null|string $list): array
    {
        $prefixes = [];

        foreach (explode(',', $list ?? '') as $prefix) {
            $prefix = trim($prefix);

            if ($prefix !== '' && in_array($prefix, $prefixes, true) === false) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }
}
