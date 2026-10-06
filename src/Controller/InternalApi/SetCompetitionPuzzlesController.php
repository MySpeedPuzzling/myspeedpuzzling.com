<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\SetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The competition's own puzzles - "Competition puzzles" on the standalone event page - become exactly `puzzleIds`.
 * They are the puzzles of the competition's tag; a competition without one gets a tag named after it, a tag that
 * other competitions or series carry too is refused (409), as changing it would change their puzzles.
 */
final class SetCompetitionPuzzlesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly GetAdminPuzzles $getAdminPuzzles,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/puzzles',
        requirements: ['competitionId' => InternalApiInput::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        $competition = $this->getAdminCompetitions->detail($competitionId)->competition;

        $input = InternalApiInput::fromRequest($request, ['puzzleIds']);
        $puzzleIds = $input->requiredIdList('puzzleIds', 'a list of puzzle ids, [] removes every puzzle.');

        $input->throwIfInvalid();
        assert($puzzleIds !== null);

        $unknownPuzzleIds = array_values(array_diff($puzzleIds, array_keys($this->getAdminPuzzles->byIds($puzzleIds))));

        if ($unknownPuzzleIds !== []) {
            throw new NotFoundHttpException(sprintf('Unknown puzzle ids: %s.', implode(', ', $unknownPuzzleIds)));
        }

        $this->messageBus->dispatch(new SetCompetitionPuzzles(
            competitionId: $competition->competitionId,
            puzzleIds: $puzzleIds,
        ));

        return new JsonResponse($this->getAdminCompetitions->detail($competition->competitionId)->toArray());
    }
}
