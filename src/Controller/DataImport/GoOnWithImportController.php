<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Message\GoOnWithImport;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportClosingOutcome;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Go on with this import" (docs/features/data-import/upload.md "Finished"): POST + CSRF, then back to the import's
 * page. When the player's places are taken the answer is a page that names the imports in progress (422 - a
 * full-page form POST never answers 200). Another player's import is the handler's 404.
 */
final class GoOnWithImportController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private GetDataImports $getDataImports,
        readonly private ImportSlots $importSlots,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/go-on',
        name: 'data_import_go_on',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $outcome = $this->messageBus->dispatch(new GoOnWithImport($importId, $player->playerId))->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof ImportClosingOutcome);

        if ($outcome === ImportClosingOutcome::NoFreePlace) {
            return $this->render('data_import/places_taken.html.twig', [
                'import' => $this->getDataImports->byIdOfPlayer($importId, $player->playerId),
                'held' => $this->importSlots->heldBy($player->playerId),
                'limit' => DataImportLimits::MAX_UNFINISHED_PER_PLAYER,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->addFlash(
            $outcome === ImportClosingOutcome::GoesOn ? 'success' : 'warning',
            $this->translator->trans('data_import.finish.flash.' . $outcome->value),
        );

        return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
    }
}
