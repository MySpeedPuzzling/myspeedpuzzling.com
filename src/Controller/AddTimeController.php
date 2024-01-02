<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotFavoriteYourself;
use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\AddPuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AddTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(path: '/pridat-cas/{puzzleId}', name: 'add_time', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, null|string $puzzleId = null): Response
    {
        $activePuzzle = null;

        if ($puzzleId !== null) {
            $activePuzzle = $this->getPuzzleOverview->byId($puzzleId);
        }

        $addTimeForm = $this->createForm(AddPuzzleSolvingTimeFormType::class, new AddPuzzleSolvingTimeFormData());
        $addTimeForm->handleRequest($request);

        if ($addTimeForm->isSubmitted() && $addTimeForm->isValid()) {
            $data = $addTimeForm->getData();
            assert($data instanceof AddPuzzleSolvingTimeFormData);

            if ($activePuzzle !== null) {
                $data->puzzleId = $activePuzzle->puzzleId;
                $data->addPuzzle = false;
            }

            $userId = $user->getUserIdentifier();

            // Adding new puzzles by user
            if (
                $data->addPuzzle === true
                && $data->puzzleName !== null
                && $data->puzzlePiecesCount !== null
                && (
                    $data->puzzleManufacturerId !== ''
                    || $data->puzzleManufacturerName !== null
                )
            ) {
                $newPuzzleId = Uuid::uuid7();
                $data->puzzleId = (string) $newPuzzleId;

                $this->messageBus->dispatch(
                    AddPuzzle::fromFormData($newPuzzleId, $userId, $data),
                );

                $this->addFlash('warning','Nově přidané puzzle se budou veřejně zobrazovat až po schválení administrátorem - obvykle do 24 hodin od přidání.');
            }

            if ($data->puzzleId !== null) {
                /** @var array<string> $groupPlayers */
                $groupPlayers = $request->request->all('group_players');

                try {
                    $this->messageBus->dispatch(
                        AddPuzzleSolvingTime::fromFormData($userId, $groupPlayers, $data),
                    );

                    $this->addFlash('success','Skvělá práce! Skládání jsme zaznamenali.');

                    return $this->redirectToRoute('my_profile');
                } catch (HandlerFailedException $exception) {
                    $realException = $exception->getPrevious();

                    if ($realException instanceof CanNotAssembleEmptyGroup) {
                        $addTimeForm->addError(new FormError('Nelze vytvořit skupinu pouze sám se sebou, to pak není skupinové skládání :-)'));
                    }
                }
            } else {
                $addTimeForm->addError(new FormError('Pro přidání času vyberte puzzle ze seznamu nebo prosím vypište informace o puzzlích'));
            }
        }

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();

        /** @var array<string, array<PuzzleOverview>> $puzzlesPerManufacturer */
        $puzzlesPerManufacturer = [];
        foreach($this->getPuzzlesOverview->allApprovedOrAddedByPlayer($userProfile?->playerId) as $puzzle) {
            $puzzlesPerManufacturer[$puzzle->manufacturerName][] = $puzzle;
        }

        return $this->render('add-time.html.twig', [
            'active_stopwatch' => null,
            'active_puzzle' => $activePuzzle,
            'puzzles' => $puzzlesPerManufacturer,
            'add_puzzle_solving_time_form' => $addTimeForm,
        ]);
    }
}
