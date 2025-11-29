<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Message\RemovePuzzleFromSellSwapList;
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

final class RemovePuzzleFromSellSwapListController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prodat-vymenit/{puzzleId}/odebrat',
            'en' => '/en/sell-swap/{puzzleId}/remove',
            'es' => '/es/vender-intercambiar/{puzzleId}/eliminar',
            'ja' => '/ja/sell-swap/{puzzleId}/remove',
            'fr' => '/fr/vendre-echanger/{puzzleId}/supprimer',
            'de' => '/de/verkaufen-tauschen/{puzzleId}/entfernen',
        ],
        name: 'sellswap_remove',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $this->messageBus->dispatch(
            new RemovePuzzleFromSellSwapList(
                playerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
            ),
        );

        // Check if this is a Turbo request
        if ($request->headers->has('Turbo-Frame') || TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $context = $request->request->getString('context', 'detail');

            // Different response based on context
            if ($context === 'list') {
                // Called from sell-swap list page - just remove the item
                return $this->render('sell-swap/_remove_from_list_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'message' => $this->translator->trans('sell_swap_list.flash.removed'),
                ]);
            }

            // Called from puzzle detail page - update badges and dropdown
            $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

            return $this->render('sell-swap/_stream.html.twig', [
                'puzzle_id' => $puzzleId,
                'puzzle_statuses' => $puzzleStatuses,
                'action' => 'removed',
                'message' => $this->translator->trans('sell_swap_list.flash.removed'),
            ]);
        }

        // Non-Turbo request: redirect with flash message
        $this->addFlash('success', $this->translator->trans('sell_swap_list.flash.removed'));

        $returnUrl = $request->request->getString('returnUrl');

        // Validate return URL to prevent open redirects
        if ($returnUrl !== '' && str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')) {
            return $this->redirect($returnUrl);
        }

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
