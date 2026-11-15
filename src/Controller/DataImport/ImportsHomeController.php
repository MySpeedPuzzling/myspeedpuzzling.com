<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Services\DataImport\ImportHomeCard;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Imports home: the signed-in player's spreadsheets, the newest first (docs/features/data-import/upload.md) - each a
 * card with ONE sentence of status and ONE button (docs/features/data-import/guided-flow.md §5.1, ImportHomeCard),
 * the finished ones folded. Nobody else's imports are ever here: every read takes the signed-in player's id. Two
 * statements, whatever the number of imports and their size.
 */
final class ImportsHomeController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'data_import';
    public const string LOCALES = 'en|cs|de|es|fr|ja';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/import',
        name: 'data_imports',
        requirements: ['_locale' => self::LOCALES],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $imports = $this->getDataImports->ofPlayer($player->playerId);
        // Where each import stands - what it added, what is ready, what waits: one statement for all of them
        $standing = $imports === [] ? [] : $this->getImportAddScreen->addedByImports($player->playerId);
        $now = $this->clock->now();
        // The daily limit is the player's, not an import's
        $resultsToday = array_sum(array_map(static fn (array $of): int => $of['resultsToday'], $standing));
        $cards = array_map(
            static fn (DataImportOverview $import): ImportHomeCard => ImportHomeCard::of($import, $standing[$import->importId] ?? null, $resultsToday, $now),
            $imports,
        );
        $inProgress = array_values(array_filter($cards, static fn (ImportHomeCard $card): bool => $card->finished === false));

        return $this->render('data_import/index.html.twig', [
            'in_progress' => $inProgress,
            'finished' => array_values(array_filter($cards, static fn (ImportHomeCard $card): bool => $card->finished)),
            // "Up to three spreadsheets can be in progress at a time" - said instead of the button. Whether a new one
            // would really be refused is the upload's own check (ImportSlots): an import with nothing left gives its
            // place back before the daily sweep finishes it
            'places_taken' => count(array_filter($inProgress, static fn (ImportHomeCard $card): bool => in_array($card->import->status, DataImportStatus::holdingAPlace(), true) && $card->quiet === false)) >= DataImportLimits::MAX_UNFINISHED_PER_PLAYER,
            'limit' => DataImportLimits::MAX_UNFINISHED_PER_PLAYER,
            'results_a_day' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY,
        ]);
    }
}
