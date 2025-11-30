<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Wishlist;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditWishListFormData;
use SpeedPuzzling\Web\FormType\EditWishListFormType;
use SpeedPuzzling\Web\Message\EditWishList;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditWishListController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/upravit-wish-list/{playerId}',
            'en' => '/en/edit-wish-list/{playerId}',
            'es' => '/es/editar-lista-de-deseos/{playerId}',
            'ja' => '/ja/ウィッシュリストを編集/{playerId}',
            'fr' => '/fr/modifier-liste-de-souhaits/{playerId}',
            'de' => '/de/wunschliste-bearbeiten/{playerId}',
        ],
        name: 'edit_wish_list',
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        // Only allow editing own wish list
        if ($loggedPlayer->playerId !== $playerId) {
            throw $this->createAccessDeniedException();
        }

        $formData = EditWishListFormData::fromVisibility($loggedPlayer->wishListVisibility);

        $form = $this->createForm(EditWishListFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditWishList(
                    playerId: $playerId,
                    visibility: $formData->visibility ?? CollectionVisibility::Private,
                ),
            );

            $this->addFlash('success', $this->translator->trans('wish_list.flash.updated'));

            return $this->redirectToRoute('puzzle_library', ['playerId' => $playerId]);
        }

        return $this->render('wishlist/edit.html.twig', [
            'form' => $form,
            'player' => $loggedPlayer,
            'cancelUrl' => $this->generateUrl('puzzle_library', ['playerId' => $playerId]),
        ]);
    }
}
