<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Message\MarkPuzzleAsSoldOrSwapped;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MarkAsSoldToPlayerController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerRepository $playerRepository,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/sell-swap/{itemId}/mark-sold-to-player/{buyerPlayerId}',
        name: 'sellswap_mark_sold_to_player',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $itemId,
        string $buyerPlayerId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $buyer = $this->playerRepository->get($buyerPlayerId);

        $this->messageBus->dispatch(new MarkPuzzleAsSoldOrSwapped(
            sellSwapListItemId: $itemId,
            playerId: $loggedPlayer->playerId,
            buyerInput: '#' . $buyer->code,
        ));

        $this->addFlash('success', $this->translator->trans('sell_swap_list.mark_sold.success'));

        $referer = $request->headers->get('referer');
        if ($referer !== null && $referer !== '') {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('sell_swap_list', ['playerId' => $loggedPlayer->playerId]);
    }
}
