<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditUnsolvedPuzzlesFormData;
use SpeedPuzzling\Web\FormType\EditUnsolvedPuzzlesFormType;
use SpeedPuzzling\Web\Message\EditUnsolvedPuzzles;
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

final class EditUnsolvedPuzzlesController extends AbstractController
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
            'cs' => '/upravit-neposlkadane-puzzle/{playerId}',
            'en' => '/en/edit-unsolved-puzzles/{playerId}',
            'es' => '/es/editar-puzzles-sin-resolver/{playerId}',
            'ja' => '/ja/未解決パズルを編集/{playerId}',
            'fr' => '/fr/modifier-puzzles-non-resolus/{playerId}',
            'de' => '/de/ungeloeste-puzzles-bearbeiten/{playerId}',
        ],
        name: 'edit_unsolved_puzzles',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedPlayer === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Only allow editing own unsolved puzzles visibility
        if ($loggedPlayer->playerId !== $playerId) {
            throw new PlayerNotFound();
        }

        $player = $this->getPlayerProfile->byId($playerId);

        $formData = EditUnsolvedPuzzlesFormData::fromVisibility($player->unsolvedPuzzlesVisibility);

        $form = $this->createForm(EditUnsolvedPuzzlesFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditUnsolvedPuzzles(
                    playerId: $playerId,
                    visibility: $formData->visibility ?? CollectionVisibility::Private,
                ),
            );

            $this->addFlash('success', $this->translator->trans('unsolved_puzzles.flash.updated'));

            return $this->redirectToRoute('puzzle_library', ['playerId' => $playerId]);
        }

        return $this->render('unsolved_puzzles/edit.html.twig', [
            'form' => $form,
            'player' => $player,
            'cancelUrl' => $this->generateUrl('puzzle_library', ['playerId' => $playerId]),
        ]);
    }
}
