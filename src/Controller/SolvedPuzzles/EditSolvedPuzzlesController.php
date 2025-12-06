<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SolvedPuzzles;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditSolvedPuzzlesFormData;
use SpeedPuzzling\Web\FormType\EditSolvedPuzzlesFormType;
use SpeedPuzzling\Web\Message\EditSolvedPuzzles;
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

final class EditSolvedPuzzlesController extends AbstractController
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
            'cs' => '/upravit-vyresene-puzzle/{playerId}',
            'en' => '/en/edit-solved-puzzles/{playerId}',
            'es' => '/es/editar-puzzles-resueltos/{playerId}',
            'ja' => '/ja/解決済みパズルを編集/{playerId}',
            'fr' => '/fr/modifier-puzzles-resolus/{playerId}',
            'de' => '/de/geloeste-puzzles-bearbeiten/{playerId}',
        ],
        name: 'edit_solved_puzzles',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedPlayer === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Only allow editing own solved puzzles visibility
        if ($loggedPlayer->playerId !== $playerId) {
            throw new PlayerNotFound();
        }

        $player = $this->getPlayerProfile->byId($playerId);

        $formData = EditSolvedPuzzlesFormData::fromVisibility($player->solvedPuzzlesVisibility);

        $form = $this->createForm(EditSolvedPuzzlesFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditSolvedPuzzles(
                    playerId: $playerId,
                    visibility: $formData->visibility ?? CollectionVisibility::Private,
                ),
            );

            $this->addFlash('success', $this->translator->trans('solved_puzzles.flash.updated'));

            return $this->redirectToRoute('puzzle_library', ['playerId' => $playerId]);
        }

        return $this->render('solved_puzzles/edit.html.twig', [
            'form' => $form,
            'player' => $player,
            'cancelUrl' => $this->generateUrl('puzzle_library', ['playerId' => $playerId]),
        ]);
    }
}
