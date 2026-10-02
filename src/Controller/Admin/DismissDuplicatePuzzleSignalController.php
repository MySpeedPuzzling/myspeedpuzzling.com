<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalAlreadyResolved;
use SpeedPuzzling\Web\Message\DismissDuplicatePuzzleSignal;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Dismiss" on a catalogue signal (docs/features/duplicate-results.md, Layer 4): two different puzzles after all.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class DismissDuplicatePuzzleSignalController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/admin/duplicate-results/puzzle-signals/{signalId}/dismiss',
        name: 'admin_duplicate_puzzle_signal_dismiss',
        methods: ['POST'],
    )]
    public function __invoke(string $signalId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('duplicate-puzzle-signal-' . $signalId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->messageBus->dispatch(new DismissDuplicatePuzzleSignal($signalId));
            $this->addFlash('success', 'Dismissed - this pair will not be raised again.');
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof DuplicatePuzzleSignalAlreadyResolved === false) {
                throw $exception;
            }

            $this->addFlash('warning', 'This signal was already handled in the meantime.');
        }

        return $this->redirectToRoute('admin_duplicate_results', ['_fragment' => 'puzzle-signals']);
    }
}
