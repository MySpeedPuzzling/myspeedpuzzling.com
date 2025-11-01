<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\CollectionFormData;
use SpeedPuzzling\Web\FormType\CollectionFormType;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateCollectionController extends AbstractController
{
    public function __construct(
        readonly private PlayerRepository $playerRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/vytvorit-kolekci',
            'en' => '/en/create-collection',
            'es' => '/es/crear-coleccion',
            'ja' => '/ja/コレクションを作成',
            'fr' => '/fr/creer-collection',
            'de' => '/de/sammlung-erstellen',
        ],
        name: 'create_collection',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Check if user has active membership to create collections
        if ($player->activeMembership === false) {
            $this->addFlash('info', $this->translator->trans('collections.flash.membership_required'));

            return $this->redirectToRoute('player_collections', ['playerId' => $player->playerId]);
        }

        // Get Player entity for Message
        $playerEntity = $this->playerRepository->getByUserIdCreateIfNotExists($user->getUserIdentifier());
        $formData = new CollectionFormData();

        $form = $this->createForm(CollectionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new CreateCollection(
                    collectionId: Uuid::uuid7()->toString(),
                    playerId: $playerEntity->id->toString(),
                    name: $formData->name ?? '',
                    description: $formData->description,
                    visibility: $formData->visibility ?? CollectionVisibility::Private,
                ),
            );

            $this->addFlash('success', $this->translator->trans('collections.flash.created'));

            return $this->redirectToRoute('player_collections', ['playerId' => $player->playerId]);
        }

        return $this->render('collections/create.html.twig', [
            'form' => $form,
            'player' => $player,
            'cancelUrl' => $this->generateUrl('player_collections', ['playerId' => $player->playerId]),
        ]);
    }
}
