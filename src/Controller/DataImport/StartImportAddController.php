<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddImportRows;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
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
 * "Add" (docs/features/data-import/adding-screens.md "The button"): everything that is ready - or, from the list of
 * rows that could not be added, the rows named (`rows[]`), with the player's "add it anyway" to the question that
 * refused them (`anyway` - written first, ConfirmImportRows). POST + CSRF; the form carries `request_id`, chosen
 * when its page was rendered: the same form sent again (a double tap, a resent form, a second tab) answers the same
 * batch (AddImportRows). Always a redirect - the work itself runs on the worker:
 *
 * - started, or the same request as before → the batch's page, which follows it live;
 * - adding is paused, an "Add" of the player still runs, too many "Add"s in an hour, nothing is ready → back where
 *   the player was, with a plain line that says so. Nothing is queued.
 *
 * Another player's import is a 404 (the handler checks it).
 */
final class StartImportAddController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add',
        name: 'data_import_add_start',
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

        $form = $request->request;
        $requestId = $form->getString('request_id');
        $rowIds = array_slice(array_values(array_filter($form->all('rows'), static fn (mixed $id): bool => is_string($id) && Uuid::isValid($id))), 0, 500);
        $named = $rowIds !== [] || $form->has('rows');
        $anyway = $form->getString('anyway');
        $batchId = $form->getString('batch');

        // Where the player sees what is: the list they pressed the button on, else the review
        $back = $named
            ? $this->redirectToRoute('data_import_not_added', array_filter(['importId' => $importId, 'batch' => Uuid::isValid($batchId) ? $batchId : null]))
            : $this->redirectToRoute('data_import_add', ['importId' => $importId]);

        if (Uuid::isValid($requestId) === false || ($named && $rowIds === [])) {
            $this->addFlash('info', $this->translator->trans('data_import.add.flash.nothing_ready'));

            return $back;
        }

        if ($named && in_array($anyway, GetImportAddScreen::WAVABLE, true)) {
            // Written only on rows that wait with this very question - a row this press already queued is passed over
            $this->messageBus->dispatch(new ConfirmImportRows($importId, $player->playerId, $rowIds, [$anyway]));
        }

        $outcome = $this->messageBus->dispatch(new AddImportRows($requestId, $importId, $player->playerId, $named ? $rowIds : null))
            ->last(HandledStamp::class)
            ?->getResult();
        assert($outcome instanceof AddImportRowsOutcome);

        if ($outcome->isStarted() && $outcome->id !== null) {
            return $this->redirectToRoute('data_import_batch', ['importId' => $importId, 'batchId' => $outcome->id]);
        }

        if ($outcome->status === AddImportRowsOutcome::ALREADY_RUNNING) {
            $this->addFlash('info', $this->translator->trans('data_import.add.flash.already_running'));
            $running = $this->getImportAddScreen->summary($importId, $player->playerId)->runningBatchId;

            return $running === null ? $back : $this->redirectToRoute('data_import_batch', ['importId' => $importId, 'batchId' => $running]);
        }

        $this->addFlash($outcome->status === AddImportRowsOutcome::PAUSED ? 'info' : 'warning', match ($outcome->status) {
            AddImportRowsOutcome::PAUSED => $this->translator->trans('data_import.add.flash.paused'),
            AddImportRowsOutcome::RATE_LIMITED => $this->translator->trans('data_import.add.flash.rate_limited'),
            AddImportRowsOutcome::NOT_ADDABLE => $this->translator->trans('data_import.add.flash.not_addable'),
            default => isset($outcome->skipped['over_daily_limit'])
                ? $this->translator->trans('data_import.add.flash.daily_limit', ['%number%' => number_format(DataImportLimits::MAX_RESULTS_ADDED_PER_DAY, 0, '.', ' ')])
                : $this->translator->trans('data_import.add.flash.nothing_ready'),
        });

        return $back;
    }
}
