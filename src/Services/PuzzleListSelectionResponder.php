<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Value\PuzzleList;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/**
 * The answer to an action on the puzzles selected on a list page (wishlist, sell/swap, unsolved, lend/borrow;
 * docs/features/collections/bulk-actions.md "Other lists"): Turbo Streams taking the cards off the page, the page
 * loaded again (a badge or another tab's count changed), or only the toast - one message either way. Without the
 * modal frame a redirect back to the list page with the same message.
 */
readonly final class PuzzleListSelectionResponder
{
    public function __construct(
        private GetWishListItems $getWishListItems,
        private GetSellSwapListItems $getSellSwapListItems,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private Environment $twig,
    ) {
    }

    /**
     * @param list<string> $removedPuzzleIds cards to take off the page (only on a list with streamTargets())
     */
    public function respond(
        Request $request,
        PuzzleList $list,
        string $playerId,
        SelectedPuzzlesOutcome $outcome,
        string $toast,
        array $removedPuzzleIds = [],
        bool $refreshPage = false,
        null|string $openUrl = null,
    ): Response {
        if ($outcome->alreadyThere > 0) {
            $toast .= ' ' . $this->translator->trans('puzzle_selection.done.already', ['%count%' => $outcome->alreadyThere]);
        }

        if ($outcome->skipped > 0) {
            $toast .= ' ' . $this->translator->trans('puzzle_selection.done.skipped', ['%count%' => $outcome->skipped]);
        }

        if ($request->headers->get('Turbo-Frame') !== 'modal-frame') {
            $this->flash($toast);

            return new RedirectResponse($this->urlGenerator->generate($list->pageRoute(), ['playerId' => $playerId]));
        }

        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        if ($refreshPage) {
            $this->flash($toast);

            return new Response(
                $this->twig->render('_modal_close_stream.html.twig') . '<turbo-stream action="refresh"></turbo-stream>',
                headers: ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        $targets = $list->streamTargets();

        return new Response($this->twig->render('puzzle_selection/_selected_stream.html.twig', [
            'targets' => $targets,
            'removed_puzzle_ids' => $targets !== null ? $removedPuzzleIds : [],
            'remaining_count' => $targets !== null && $removedPuzzleIds !== [] ? $this->remainingCount($list, $playerId) : null,
            'message' => $toast,
            'open_url' => $openUrl,
        ]), headers: ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }

    private function remainingCount(PuzzleList $list, string $playerId): int
    {
        return match ($list) {
            PuzzleList::Wishlist => $this->getWishListItems->countByPlayerId($playerId),
            PuzzleList::SellSwap => $this->getSellSwapListItems->countByPlayerId($playerId),
            PuzzleList::Unsolved, PuzzleList::LendBorrow => 0,
        };
    }

    private function flash(string $message): void
    {
        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
