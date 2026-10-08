<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Value\CollectionSelection;
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
 * The answer to a move, copy or remove of selected collection puzzles: Turbo Streams for the modal (close it, take
 * the cards off the page, the count, one toast), else a redirect back to the collection page with the same message.
 */
readonly final class CollectionSelectionResponder
{
    public function __construct(
        private GetCollectionItems $getCollectionItems,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private Environment $twig,
    ) {
    }

    public function respond(
        Request $request,
        CollectionSelection $selection,
        string $playerId,
        SelectedPuzzlesOutcome $outcome,
        string $toast,
        bool $removeCards,
        null|string $openUrl = null,
        // The cards change in place (a lend badge): load the page again, the message comes as a flash
        bool $refreshPage = false,
    ): Response {
        if ($outcome->alreadyThere > 0) {
            $toast .= ' ' . $this->translator->trans('collection_selection.done.already_there', ['%count%' => $outcome->alreadyThere]);
        }

        if ($outcome->skipped > 0) {
            $toast .= ' ' . $this->translator->trans('collection_selection.done.skipped', ['%count%' => $outcome->skipped]);
        }

        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            if ($refreshPage) {
                $this->flash($toast);

                return new Response(
                    $this->twig->render('_modal_close_stream.html.twig') . '<turbo-stream action="refresh"></turbo-stream>',
                    headers: ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
                );
            }

            return new Response($this->twig->render('collections/_selected_stream.html.twig', [
                'removed_puzzle_ids' => $removeCards ? $selection->puzzleIds : [],
                'remaining_count' => $this->getCollectionItems->countByCollectionAndPlayer($selection->collectionId, $playerId),
                'message' => $toast,
                'open_url' => $openUrl,
            ]), headers: ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        $this->flash($toast);

        return new RedirectResponse($selection->collectionId === null
            ? $this->urlGenerator->generate('system_collection_detail', ['playerId' => $playerId])
            : $this->urlGenerator->generate('collection_detail', ['collectionId' => $selection->collectionId]));
    }

    private function flash(string $message): void
    {
        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
