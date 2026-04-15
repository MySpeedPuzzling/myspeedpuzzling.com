<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Value\EditTimeReturnContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeleteTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-cas/{timeId}',
            'en' => '/en/delete-time/{timeId}',
            'es' => '/es/eliminar-tiempo/{timeId}',
            'ja' => '/ja/時間削除/{timeId}',
            'fr' => '/fr/supprimer-temps/{timeId}',
            'de' => '/de/zeit-loeschen/{timeId}',
        ],
        name: 'delete_time',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $timeId): Response
    {
        $contextValue = $request->isMethod('POST')
            ? $request->request->getString('context')
            : $request->query->getString('context');
        $context = EditTimeReturnContext::tryFrom($contextValue) ?? EditTimeReturnContext::Profile;

        // Capture the puzzleId for the puzzle-detail context before the solving time is deleted.
        $solvedPuzzle = $context === EditTimeReturnContext::PuzzleDetail
            ? $this->getPlayerSolvedPuzzles->byTimeId($timeId)
            : null;

        $this->messageBus->dispatch(
            new DeletePuzzleSolvingTime($user->getUserIdentifier(), $timeId),
        );

        $this->addFlash('success', $this->translator->trans('flashes.time_deleted'));

        $isModalRequest = $request->headers->get('Turbo-Frame') === 'modal-frame';

        if ($isModalRequest && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('delete-time_success_stream.html.twig');
        }

        if ($context === EditTimeReturnContext::PuzzleDetail && $solvedPuzzle !== null) {
            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $solvedPuzzle->puzzleId]);
        }

        return $this->redirectToRoute('my_profile');
    }
}
