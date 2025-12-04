<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\FormData\MarkAsSoldSwappedFormData;
use SpeedPuzzling\Web\FormType\MarkAsSoldSwappedFormType;
use SpeedPuzzling\Web\Message\MarkPuzzleAsSoldOrSwapped;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class MarkAsSoldSwappedController extends AbstractController
{
    public function __construct(
        readonly private SellSwapListItemRepository $sellSwapListItemRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetCollectionItems $getCollectionItems,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prodat-vymenit/{itemId}/prodano',
            'en' => '/en/sell-swap/{itemId}/mark-sold',
            'es' => '/es/vender-intercambiar/{itemId}/vendido',
            'ja' => '/ja/sell-swap/{itemId}/mark-sold',
            'fr' => '/fr/vendre-echanger/{itemId}/vendu',
            'de' => '/de/verkaufen-tauschen/{itemId}/verkauft',
        ],
        name: 'sellswap_mark_sold',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $itemId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $sellSwapListItem = $this->sellSwapListItemRepository->get($itemId);
        $puzzleId = $sellSwapListItem->puzzle->id->toString();

        // Verify ownership
        if ($sellSwapListItem->player->id->toString() !== $loggedPlayer->playerId) {
            throw $this->createAccessDeniedException();
        }

        $formData = new MarkAsSoldSwappedFormData();
        $form = $this->createForm(MarkAsSoldSwappedFormType::class, $formData);
        $form->handleRequest($request);

        // Handle POST - mark as sold/swapped
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var MarkAsSoldSwappedFormData $formData */
            $formData = $form->getData();

            $this->messageBus->dispatch(new MarkPuzzleAsSoldOrSwapped(
                sellSwapListItemId: $itemId,
                playerId: $loggedPlayer->playerId,
                buyerInput: $formData->buyerInput,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $context = $request->request->getString('context', 'detail');

                $templateParams = [
                    'item_id' => $itemId,
                    'puzzle_id' => $puzzleId,
                    'message' => $this->translator->trans('sell_swap_list.mark_sold.success'),
                    'context' => $context,
                ];

                // For collection-detail context, fetch collection item for card replacement
                if ($context === 'collection-detail') {
                    $collectionId = $request->request->getString('collection_id');
                    // Handle __system_collection__ marker - treat as null (system collection)
                    $collectionIdForQuery = ($collectionId !== '' && $collectionId !== '__system_collection__') ? $collectionId : null;

                    $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);
                    $collectionItem = $this->getCollectionItems->getByPuzzleIdAndPlayerId(
                        $puzzleId,
                        $loggedPlayer->playerId,
                        $collectionIdForQuery,
                    );

                    $templateParams['item'] = $collectionItem;
                    $templateParams['puzzle_statuses'] = $puzzleStatuses;
                    $templateParams['collection_id'] = $collectionId;
                    $templateParams['logged_user'] = $this->getUser();
                }

                return $this->render('sell-swap/_mark_sold_stream.html.twig', $templateParams);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('sell_swap_list.mark_sold.success'));

            return $this->redirectToRoute('sell_swap_list', ['playerId' => $loggedPlayer->playerId]);
        }

        // Handle GET - show modal/form
        $favoritePlayers = $this->getFavoritePlayers->forPlayerId($loggedPlayer->playerId);

        $templateParams = [
            'item_id' => $itemId,
            'puzzle_id' => $puzzleId,
            'form' => $form,
            'favorite_players' => $favoritePlayers,
            'context' => $request->query->getString('context', 'detail'),
            'collection_id' => $request->query->getString('collection_id', ''),
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('sell-swap/mark_sold_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('sell-swap/mark_sold.html.twig', $templateParams);
    }
}
