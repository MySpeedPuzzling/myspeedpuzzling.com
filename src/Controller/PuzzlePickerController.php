<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPlayerCollectionsWithCounts;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Query\GetPuzzlePickerSuggestions;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PuzzlePickerSuggestion;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use SpeedPuzzling\Web\Value\PuzzlePickerPreset;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "What should I solve next?" — the puzzle picker.
 *
 * One public route, two experiences: signed-in players get the tool (their
 * shelf, their history, their filters); guests get an indexable landing page
 * with a live demo drawn from all approved puzzles. The seed is generated
 * server-side and never redirected into the URL, so the bare URL stays the
 * canonical one — it only travels in the "Pick another" links.
 *
 * Insights layer: members get difficulty tiers and specific collections;
 * members who have not opted out of time predictions additionally get the
 * prediction row on the card and the gap / order / personal-budget filters.
 * Non-eligible input is stripped by the criteria, so the gating lives in one place.
 *
 * Remembered filters: for signed-in players the last used filters are kept in
 * the session (`puzzle_picker.filters`) after every request that carries query
 * parameters, and applied again on a bare visit — rendered on the bare URL, no
 * redirect, with a "Reset" link to `?reset=1` that forgets them. Guests never
 * touch the session: anonymous requests must stay session-free.
 */
final class PuzzlePickerController extends AbstractController
{
    public const string SESSION_KEY = 'puzzle_picker.filters';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPuzzlePickerSuggestions $getPuzzlePickerSuggestions,
        readonly private GetPlayerPredictions $getPlayerPredictions,
        readonly private GetManufacturers $getManufacturers,
        readonly private GetPlayerCollectionsWithCounts $getPlayerCollectionsWithCounts,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/co-skladat-dal',
            'en' => '/en/what-to-solve-next',
            'es' => '/es/que-puzzle-armar',
            'fr' => '/fr/quel-puzzle-faire',
            'de' => '/de/welches-puzzle-als-naechstes',
            'ja' => '/ja/次のパズル',
        ],
        name: 'puzzle_picker',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $insightsAllowed = $profile !== null && $profile->activeMembership;
        $predictionsAllowed = $profile !== null && $insightsAllowed && $profile->timePredictionsOptedOut === false;

        $criteria = PuzzlePickerCriteria::fromRequest(
            $request,
            isAuthenticated: $profile !== null,
            insightsAllowed: $insightsAllowed,
            predictionsAllowed: $predictionsAllowed,
        );
        $filtersRemembered = false;

        if ($profile !== null) {
            $session = $request->getSession();

            if ($request->query->has('reset')) {
                $session->remove(self::SESSION_KEY);
            } elseif ($request->query->count() === 0) {
                $stored = $session->get(self::SESSION_KEY);

                if (is_array($stored) && $stored !== []) {
                    $criteria = PuzzlePickerCriteria::fromQuery($stored, true, $insightsAllowed, $predictionsAllowed);
                    $filtersRemembered = $criteria->isDefault() === false;
                }
            } else {
                // Any query string is a filter change (chips, form, presets, spin) - remember it
                // without the seed; a return to the bare defaults forgets the previous filters
                $filters = $criteria->withSeed(null)->toQueryParams();

                if ($filters === []) {
                    $session->remove(self::SESSION_KEY);
                } else {
                    $session->set(self::SESSION_KEY, $filters);
                }
            }
        }

        $seed = $criteria->seed ?? self::generateSeed();

        // A signed-in player's bare visit is the intro: no draw yet, just the presets, the
        // filters (remembered ones included) and a "Surprise me" CTA - the first puzzle should
        // be something the player asked for, not something that appeared. Any query string
        // (preset, filters, seed) is a draw. Guests keep the landing demo.
        $intro = $profile !== null && $request->query->count() === 0;

        $pick = $intro ? null : $this->getPuzzlePickerSuggestions->pick(
            $criteria->withSeed($seed),
            $profile?->playerId,
            PuzzlePickerCriteria::PICK_SIZE,
        );

        // The card shows the full prediction (value, range, personalised or
        // statistical) for the picked puzzles only - the exact same numbers the
        // puzzle detail page shows, from the same calculator
        $predictions = [];

        if ($pick !== null && $profile !== null && $predictionsAllowed && $pick->isEmpty() === false) {
            $predictions = $this->getPlayerPredictions->forPuzzles(
                $profile->playerId,
                array_map(static fn (PuzzlePickerSuggestion $suggestion): string => $suggestion->puzzleId, $pick->suggestions),
            );
        }

        $collections = $profile !== null && $insightsAllowed ? $this->collectionsOf($profile) : [];
        $collectionNames = [];

        foreach ($collections as $collection) {
            $collectionNames[$collection['id']] = $collection['name'];
        }

        return $this->render($profile !== null ? 'puzzle_picker/index.html.twig' : 'puzzle_picker/landing.html.twig', [
            'criteria' => $criteria,
            'seed' => $seed,
            'next_seed' => self::generateSeed(),
            'intro' => $intro,
            'pick' => $pick,
            'predictions' => $predictions,
            'brand_names' => array_map(static fn (array $brand): string => $brand['name'], $brands = $this->getManufacturers->namesAndLogosByIds($criteria->brandIds)),
            'brand_logos' => array_filter(array_map(static fn (array $brand): null|string => $brand['logo'], $brands)),
            'collections' => $collections,
            'collection_names' => $collectionNames,
            'presets' => PuzzlePickerPreset::cases(),
            'active_preset' => $criteria->activePreset(),
            'filters_remembered' => $filtersRemembered,
            'reset_url' => $profile !== null
                ? $this->generateUrl('puzzle_picker', ['reset' => 1])
                : $this->generateUrl('puzzle_picker'),
        ]);
    }

    /**
     * The player's collections for the "only these collections" checkbox list:
     * the system collection first (its id is the sentinel - it has no row), then
     * the custom ones.
     *
     * @return list<array{id: string, name: string, count: int, system: bool}>
     */
    private function collectionsOf(PlayerProfile $profile): array
    {
        $collections = [[
            'id' => Collection::SYSTEM_ID,
            'name' => $this->translator->trans('collections.system_name'),
            'count' => $this->getPlayerCollectionsWithCounts->countSystemCollection($profile->playerId),
            'system' => true,
        ]];

        foreach ($this->getPlayerCollectionsWithCounts->byPlayerId($profile->playerId) as $collection) {
            if ($collection->collectionId === null) {
                continue;
            }

            $collections[] = [
                'id' => $collection->collectionId,
                'name' => $collection->name,
                'count' => $collection->itemCount,
                'system' => false,
            ];
        }

        return $collections;
    }

    private static function generateSeed(): string
    {
        return bin2hex(random_bytes(4));
    }
}
