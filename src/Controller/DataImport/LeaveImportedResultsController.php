<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Message\LeaveImportedResults;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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
 * "These are not mine - take me off them" on the notification "<importer> added N results with you from a
 * spreadsheet" (docs/features/data-import/people.md "A partner can get out"): one action, POST + CSRF, always a
 * redirect back to the notifications. The signed-in player is who leaves - nobody can be taken off by somebody else.
 */
final class LeaveImportedResultsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'imported_results_leave';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/imported-results/{importerId}/leave',
        name: 'imported_results_leave',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importerId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importerId): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $left = $this->messageBus->dispatch(new LeaveImportedResults($player->playerId, $importerId))->last(HandledStamp::class)?->getResult();

        $this->addFlash('success', $this->translator->trans('data_import.notification.left', ['%count%' => is_int($left) ? $left : 0]));

        return $this->redirectToRoute('notifications');
    }
}
