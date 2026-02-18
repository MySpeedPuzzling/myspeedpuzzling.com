<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditSystemCollectionFormData;
use SpeedPuzzling\Web\FormType\EditSystemCollectionFormType;
use SpeedPuzzling\Web\Message\EditSystemCollection;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditSystemCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/upravit-puzzle-kolekci/{playerId}',
            'en' => '/en/edit-puzzle-collection/{playerId}',
            'es' => '/es/editar-coleccion-puzzle/{playerId}',
            'ja' => '/ja/パズルコレクションを編集/{playerId}',
            'fr' => '/fr/modifier-collection-puzzle/{playerId}',
            'de' => '/de/puzzle-sammlung-bearbeiten/{playerId}',
        ],
        name: 'edit_system_collection',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedPlayer === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Only allow editing own system collection
        if ($loggedPlayer->playerId !== $playerId) {
            throw new PlayerNotFound();
        }

        $player = $this->getPlayerProfile->byId($playerId);

        $formData = EditSystemCollectionFormData::fromVisibility($player->puzzleCollectionVisibility);

        $form = $this->createForm(EditSystemCollectionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditSystemCollection(
                    playerId: $playerId,
                    visibility: $formData->visibility ?? CollectionVisibility::Private,
                ),
            );

            $this->addFlash('success', $this->translator->trans('collections.flash.system_updated'));

            return $this->redirectToRoute('puzzle_library', ['playerId' => $playerId]);
        }

        $template = $request->headers->get('Turbo-Frame') === 'modal-frame'
            ? 'collections/_edit_system_modal.html.twig'
            : 'collections/edit_system.html.twig';

        return $this->render($template, [
            'form' => $form,
            'player' => $player,
            'cancelUrl' => $this->generateUrl('puzzle_library', ['playerId' => $playerId]),
        ]);
    }
}
