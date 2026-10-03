<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetMarketplaceEventsHintState;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\ResolvePuzzleListInsights;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SellSwapListDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private ResolvePuzzleListInsights $resolvePuzzleListInsights,
        readonly private IsHintDismissed $isHintDismissed,
        readonly private GetMarketplaceEventsHintState $getMarketplaceEventsHintState,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/prodej-vymena/{playerId}',
            'en' => '/en/sell-swap-list/{playerId}',
            'es' => '/es/lista-venta-intercambio/{playerId}',
            'ja' => '/ja/売買リスト/{playerId}',
            'fr' => '/fr/liste-vente-echange/{playerId}',
            'de' => '/de/verkaufs-tausch-liste/{playerId}',
        ],
        name: 'sell_swap_list_detail',
    )]
    public function __invoke(string $playerId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));
            return $this->redirectToRoute('ladder');
        }

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $items = $this->getSellSwapListItems->byPlayerId($player->playerId);
        $isOwnProfile = $playerId === $loggedPlayerProfile?->playerId;

        // "Marketplace at events" banner - the owner's own list only, until dismissed
        $eventsHintState = null;
        $eventsBanner = null;

        if ($isOwnProfile && ($this->isHintDismissed)($loggedPlayerProfile->playerId, HintType::MarketplaceAtEvents) === false) {
            $eventsHintState = $this->getMarketplaceEventsHintState->forPlayer($loggedPlayerProfile->playerId);
            $eventsBanner = $eventsHintState->banner($loggedPlayerProfile->activeMembership);
        }

        return $this->render('sell-swap/detail.html.twig', [
            'items' => $items,
            'player' => $player,
            'isOwnProfile' => $isOwnProfile,
            'settings' => $player->sellSwapListSettings,
            'events_banner' => $eventsBanner,
            'events_hint_state' => $eventsHintState,
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($player->playerId),
            ...$this->resolvePuzzleListInsights->forViewer($loggedPlayerProfile, array_column($items, 'puzzleId'))->templateParameters(),
        ]);
    }
}
