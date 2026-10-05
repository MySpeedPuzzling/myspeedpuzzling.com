<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changes only the fields sent. The round's slug never changes (shared result links keep working). A category change
 * that would put one of its puzzles into two rounds of the same category is refused (409).
 */
final class UpdateCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}',
        requirements: ['roundId' => InternalApiInput::ID_REQUIREMENT],
        methods: ['PATCH'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $input = InternalApiInput::fromRequest($request, RoundInput::FIELDS);

        $data = CompetitionRoundFormData::fromCompetitionRound($round);
        RoundInput::applyTo($input, $data, $round->competition->locationCountryCode);

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        assert($data->name !== null && $data->minutesLimit !== null && $data->startsAt !== null);

        try {
            $this->messageBus->dispatch(new EditCompetitionRound(
                roundId: $round->id->toString(),
                name: $data->name,
                minutesLimit: $data->minutesLimit,
                startsAt: $data->startsAt,
                badgeBackgroundColor: $data->badgeBackgroundColor,
                badgeTextColor: $data->badgeTextColor,
                category: $data->category,
                resultsLink: $data->resultsLink,
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                throw new ConflictHttpException(sprintf(
                    'A puzzle can be in only one %s round of a competition - one of this round\'s puzzles is already in round "%s". Nothing was changed.',
                    $data->category->value,
                    $exception->getPrevious()->conflictingRoundName,
                ), $exception);
            }

            throw $exception;
        }

        return new JsonResponse($this->getAdminCompetitions->round($round->id->toString())->toArray());
    }
}
