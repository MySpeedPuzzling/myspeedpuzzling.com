<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\CreateCollectionFolder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateCollectionFolderController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce/vytvorit-slozku',
            'en' => '/en/collection/create-folder',
        ],
        name: 'create_collection_folder',
        methods: ['POST']
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface|null $user): Response
    {
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayerProfile === null) {
            throw $this->createAccessDeniedException();
        }

        $name = $request->request->get('name', '');
        $color = $request->request->get('color');
        $description = $request->request->get('description');

        if (empty($name)) {
            $this->addFlash('error', $this->translator->trans('collection.folder_name_required'));
            return $this->redirectToRoute('player_collection', ['playerId' => $loggedPlayerProfile->playerId]);
        }

        $this->messageBus->dispatch(new CreateCollectionFolder(
            $loggedPlayerProfile->playerId,
            $name,
            $color,
            $description,
        ));

        $this->addFlash('success', $this->translator->trans('collection.folder_created'));

        return $this->redirectToRoute('player_collection', ['playerId' => $loggedPlayerProfile->playerId]);
    }
}