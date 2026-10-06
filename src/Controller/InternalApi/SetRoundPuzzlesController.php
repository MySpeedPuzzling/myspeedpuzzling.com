<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleInTwoRoundsOfCategory;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The round's puzzles become exactly `puzzleIds` (SetCompetitionRoundPuzzles): one round per category per puzzle per
 * competition, else 409 and nothing changes. New puzzles are attached unhidden, so a hidden puzzle is refused (409);
 * removing a secret puzzle that no other round keeps hidden needs `"confirmReveal": true` (409, `revealedPuzzles`).
 */
final class SetRoundPuzzlesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly GetAdminPuzzles $getAdminPuzzles,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/puzzles',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->getAdminCompetitions->round($roundId);

        $input = InternalApiInput::fromRequest($request, ['puzzleIds', 'confirmReveal']);
        $puzzleIds = $input->requiredIdList('puzzleIds', 'a list of puzzle ids, [] removes every puzzle.');
        $confirmReveal = $input->bool('confirmReveal') ?? false;

        $input->throwIfInvalid();
        assert($puzzleIds !== null);

        $unknownPuzzleIds = array_values(array_diff($puzzleIds, array_keys($this->getAdminPuzzles->byIds($puzzleIds))));

        if ($unknownPuzzleIds !== []) {
            throw new NotFoundHttpException(sprintf('Unknown puzzle ids: %s.', implode(', ', $unknownPuzzleIds)));
        }

        try {
            $this->messageBus->dispatch(new SetCompetitionRoundPuzzles(
                roundId: $round->roundId,
                puzzleIds: $puzzleIds,
                refuseToReveal: $confirmReveal === false,
            ));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                throw new PuzzleInTwoRoundsOfCategory($round->category, $previous->conflictingRoundName, $exception);
            }

            throw $exception;
        }

        return new JsonResponse($this->getAdminCompetitions->round($round->roundId)->toArray());
    }
}
