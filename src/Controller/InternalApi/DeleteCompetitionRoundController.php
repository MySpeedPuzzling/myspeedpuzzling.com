<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deletes a round with its puzzle assignments, teams and seating - only while nobody has a result in it (409
 * otherwise, CompetitionRoundHasResults).
 */
final class DeleteCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['DELETE'],
    )]
    public function __invoke(string $roundId): Response
    {
        // 404 for an unknown round before anything runs
        $round = $this->competitionRoundRepository->get($roundId);

        $this->messageBus->dispatch(new DeleteCompetitionRound(
            roundId: $round->id->toString(),
            refuseWhenItHasResults: true,
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
