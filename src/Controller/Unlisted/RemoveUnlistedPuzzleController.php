<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Message\RemoveUnlistedPuzzle;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Remove" on the signed-in player's unlisted puzzle, in two steps like the import's "Take back": the GET shows what
 * would go (so many results, so many marks - for good), the POST with its CSRF token removes them and redirects to
 * the list (PRG). Another player's unlisted puzzle - and any for a guest - is a 404 for both.
 */
final class RemoveUnlistedPuzzleController extends AbstractController
{
    public function __construct(
        readonly private UnlistedPuzzlePage $page,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles/{unlistedPuzzleId}/remove',
        name: 'unlisted_puzzle_remove',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'unlistedPuzzleId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $unlistedPuzzleId): Response
    {
        $playerId = $this->page->ownerPlayerId();

        // First what would go - the results are removed for good, so nothing is removed by one tap
        if ($request->isMethod('GET')) {
            $removal = $this->page->removal($unlistedPuzzleId);

            if ($removal['working']) {
                return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlistedPuzzleId]);
            }

            $response = $this->render('unlisted_puzzles/remove.html.twig', $removal);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        if ($this->isCsrfTokenValid(UnlistedPuzzlePage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $removed = $this->messageBus->dispatch(new RemoveUnlistedPuzzle($unlistedPuzzleId, $playerId))->last(HandledStamp::class)?->getResult();

        if ($removed === false) {
            $this->addFlash('warning', $this->translator->trans('unlisted_puzzles.flash.already_running'));

            return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlistedPuzzleId]);
        }

        $this->addFlash('success', $this->translator->trans('unlisted_puzzles.flash.removed'));

        return $this->redirectToRoute('unlisted_puzzles');
    }
}
