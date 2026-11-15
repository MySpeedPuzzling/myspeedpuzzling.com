<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\DataImport;

use SpeedPuzzling\Web\Message\ChangeImportAddingPaused;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The safety switch of the spreadsheet import (docs/features/data-import/adding.md "The switch"): pausing asks for a
 * reason - it stops what every player adds.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ChangeImportAddingPausedController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'admin-import-adding-paused';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(path: '/admin/imports/adding', name: 'admin_imports_adding', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $admin = $this->retrieveLoggedUserProfile->getProfile();
        assert($admin !== null);

        $paused = $request->request->getString('paused') === '1';
        $reason = trim($request->request->getString('reason'));

        if ($paused && $reason === '') {
            $this->addFlash('danger', 'Pausing needs a reason - it stops what every player adds.');

            return $this->redirectToRoute('admin_imports');
        }

        $this->messageBus->dispatch(new ChangeImportAddingPaused($paused, $admin->playerId, $reason === '' ? null : $reason));
        $this->addFlash('warning', $paused ? 'Adding is paused. Running batches wait after the row they are at.' : 'Adding is on again. Waiting batches go on within five minutes.');

        return $this->redirectToRoute('admin_imports');
    }
}
