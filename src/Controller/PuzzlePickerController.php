<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Query\GetPuzzlePickerSuggestions;
use SpeedPuzzling\Web\Results\PuzzlePickerSuggestion;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "What should I solve next?" — the puzzle picker.
 *
 * One public route, two experiences: signed-in players get the tool (their
 * shelf, their history, their filters); guests get an indexable landing page
 * with a live demo drawn from all approved puzzles. The seed is generated
 * server-side and never redirected into the URL, so the bare URL stays the
 * canonical one — it only travels in the "Pick another" links.
 *
 * Insights layer: members get difficulty tiers; members who have not opted out
 * of time predictions additionally get the prediction row on the card and the
 * gap / order / personal-budget filters. Non-eligible input is stripped by the
 * criteria, so the gating lives in one place.
 */
final class PuzzlePickerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPuzzlePickerSuggestions $getPuzzlePickerSuggestions,
        readonly private GetPlayerPredictions $getPlayerPredictions,
        readonly private GetManufacturers $getManufacturers,
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
        $seed = $criteria->seed ?? self::generateSeed();

        $pick = $this->getPuzzlePickerSuggestions->pick(
            $criteria->withSeed($seed),
            $profile?->playerId,
            PuzzlePickerCriteria::PICK_SIZE,
        );

        // The card shows the full prediction (value, range, personalised or
        // statistical) for the picked puzzles only - the exact same numbers the
        // puzzle detail page shows, from the same calculator
        $predictions = [];

        if ($profile !== null && $predictionsAllowed && $pick->isEmpty() === false) {
            $predictions = $this->getPlayerPredictions->forPuzzles(
                $profile->playerId,
                array_map(static fn (PuzzlePickerSuggestion $suggestion): string => $suggestion->puzzleId, $pick->suggestions),
            );
        }

        return $this->render($profile !== null ? 'puzzle_picker/index.html.twig' : 'puzzle_picker/landing.html.twig', [
            'criteria' => $criteria,
            'seed' => $seed,
            'next_seed' => self::generateSeed(),
            'pick' => $pick,
            'predictions' => $predictions,
            'brand_names' => $this->getManufacturers->namesByIds($criteria->brandIds),
        ]);
    }

    private static function generateSeed(): string
    {
        return bin2hex(random_bytes(4));
    }
}
