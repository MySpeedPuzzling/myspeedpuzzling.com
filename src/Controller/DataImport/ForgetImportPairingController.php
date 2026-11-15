<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleChoiceRefused;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Forget that this puzzle was paired this way" (docs/features/data-import/adding-screens.md "Undo"), offered after
 * a row that had landed on the wrong puzzle was taken back: the decision of the row's puzzle is taken back through
 * the pairing's own message (DecideImportPuzzles, `Undecided` - which also forgets the remembered pairing, so the
 * next file does not repeat it), and the player lands in the chooser of that puzzle. POST + CSRF, always a
 * redirect. Another player's import or row is a 404.
 */
final class ForgetImportPairingController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/rows/{rowId}/forget-pairing',
        name: 'data_import_forget_pairing',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'rowId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId, string $rowId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $pairing = $this->getImportAddScreen->pairingOfRow($importId, $player->playerId, $rowId) ?? throw new DataImportNotFound();
        $chooser = $this->redirectToRoute('data_import_puzzle_choose', ['importId' => $importId, 'importPuzzleId' => $pairing['importPuzzleId']]);

        if ($pairing['decided'] === false) {
            return $chooser;
        }

        try {
            $this->messageBus->dispatch(new DecideImportPuzzles($importId, $player->playerId, [
                new ImportPuzzleDecisionChange($pairing['importPuzzleId'], ImportPuzzleChoice::Undecided, null, $pairing['version']),
            ]));
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportPuzzleDecidedMeanwhile || $cause instanceof ImportPuzzleChoiceRefused) {
                $this->addFlash('warning', $this->translator->trans('data_import.puzzles.flash.decided_meanwhile'));

                return $chooser;
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans('data_import.undo.flash.pairing_forgotten'));

        return $chooser;
    }
}
