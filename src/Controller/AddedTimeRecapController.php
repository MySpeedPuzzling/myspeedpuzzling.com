<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\PuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FinishStopwatch;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AddedTimeRecapController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/cas-pridan/{timeId}',
            'en' => '/en/time-added/{timeId}',
        ],
        name: 'added_time_recap',
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        string $timeId,
    ): Response {
        $solvingPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $player = $this->getPlayerProfile->byId($solvingPuzzle->playerId);

        return $this->render('added_time_recap.html.twig', [
            'solved_puzzle' => $solvingPuzzle,
        ]);
    }
}
