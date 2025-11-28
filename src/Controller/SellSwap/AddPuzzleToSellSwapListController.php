<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\FormData\AddToSellSwapListFormData;
use SpeedPuzzling\Web\FormType\AddToSellSwapListFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToSellSwapList;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class AddPuzzleToSellSwapListController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prodat-vymenit/{puzzleId}/pridat',
            'en' => '/en/sell-swap/{puzzleId}/add',
            'es' => '/es/vender-intercambiar/{puzzleId}/agregar',
            'ja' => '/ja/sell-swap/{puzzleId}/add',
            'fr' => '/fr/vendre-echanger/{puzzleId}/ajouter',
            'de' => '/de/verkaufen-tauschen/{puzzleId}/hinzufuegen',
        ],
        name: 'sellswap_add',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        $isInSellSwapList = $this->getSellSwapListItems->isPuzzleInSellSwapList($loggedPlayer->playerId, $puzzleId);

        $formData = new AddToSellSwapListFormData();
        $form = $this->createForm(AddToSellSwapListFormType::class, $formData);
        $form->handleRequest($request);

        // Handle POST - add to sell/swap list
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$loggedPlayer->activeMembership) {
                $this->addFlash('warning', $this->translator->trans('sell_swap_list.membership_required.message'));

                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            /** @var AddToSellSwapListFormData $formData */
            $formData = $form->getData();

            assert($formData->listingType !== null);
            assert($formData->condition !== null);

            $this->messageBus->dispatch(new AddPuzzleToSellSwapList(
                playerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
                listingType: $formData->listingType,
                price: $formData->price,
                condition: $formData->condition,
                comment: $formData->comment,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

                return $this->render('sell-swap/_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'action' => 'added',
                    'message' => $this->translator->trans('sell_swap_list.flash.added'),
                ]);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('sell_swap_list.flash.added'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle GET - show modal/form
        $templateParams = [
            'puzzle' => $puzzle,
            'form' => $form,
            'is_in_sell_swap_list' => $isInSellSwapList,
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('sell-swap/modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('sell-swap/add_item.html.twig', $templateParams);
    }
}
