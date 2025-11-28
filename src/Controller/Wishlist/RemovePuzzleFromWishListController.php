<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Wishlist;

use SpeedPuzzling\Web\Message\RemovePuzzleFromWishList;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class RemovePuzzleFromWishListController extends AbstractController
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
            'cs' => '/odebrat-z-wish-listu/{puzzleId}',
            'en' => '/en/remove-from-wish-list/{puzzleId}',
            'es' => '/es/eliminar-de-lista-de-deseos/{puzzleId}',
            'ja' => '/ja/ウィッシュリストから削除/{puzzleId}',
            'fr' => '/fr/supprimer-de-liste-de-souhaits/{puzzleId}',
            'de' => '/de/von-wunschliste-entfernen/{puzzleId}',
        ],
        name: 'remove_puzzle_from_wish_list',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $this->messageBus->dispatch(
            new RemovePuzzleFromWishList(
                playerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
            ),
        );

        // Check if this is a Turbo request
        if ($request->headers->has('Turbo-Frame') || TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

            return $this->render('wishlist/_stream.html.twig', [
                'puzzle_id' => $puzzleId,
                'puzzle_statuses' => $puzzleStatuses,
                'action' => 'removed',
                'message' => $this->translator->trans('wish_list.remove.success'),
            ]);
        }

        // Non-Turbo request: redirect with flash message
        $this->addFlash('success', $this->translator->trans('wish_list.remove.success'));

        $returnUrl = $request->request->getString('returnUrl');

        // Validate return URL to prevent open redirects
        if ($returnUrl !== '' && str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')) {
            return $this->redirect($returnUrl);
        }

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
