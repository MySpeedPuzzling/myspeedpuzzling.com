<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Exceptions\CompetitionNotApprovable;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Approves a pending competition like the admin approval queue does, credited to the reviewer player - the
 * competition becomes public and its creator gets the "approved" e-mail (unless that is the reviewer).
 */
final class ApproveCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/approve',
        requirements: ['competitionId' => InternalApiInput::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the approval to.',
            );
        }

        $competition = $this->getAdminCompetitions->detail($competitionId)->competition;

        if ($competition->seriesId !== null) {
            throw new CompetitionNotApprovable('An edition is never approved on its own - its series is approved, in the admin approval queue.');
        }

        if ($competition->rejectedAt !== null) {
            throw new CompetitionNotApprovable('The competition was rejected - approving it would not make it public. Decide it in the admin approval queue.');
        }

        if ($competition->approvedAt !== null) {
            throw new CompetitionNotApprovable('The competition is already approved.');
        }

        $this->messageBus->dispatch(new ApproveCompetition(
            competitionId: $competition->competitionId,
            approvedByPlayerId: $this->reviewerPlayerId,
            // A player's submission: they get the "approved" e-mail, like from the approval queue - not the reviewer
            // player about a competition he created through this API
            notifyCreator: $competition->addedByPlayerId !== strtolower($this->reviewerPlayerId),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
