<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deletes a round with its puzzle assignments, teams and seating - only while nobody has a result in it, a player's
 * time or an official result the organiser recorded (409 otherwise, CompetitionRoundHasResults), and only with `{"confirmReveal": true}` when that reveals a secret puzzle
 * right away (409, `revealedPuzzles`): another round has revealed it already. A secret puzzle no other round holds
 * stays hidden - its hide dates are kept, nothing is refused.
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
    public function __invoke(string $roundId, Request $request): Response
    {
        // 404 for an unknown round before anything runs
        $round = $this->competitionRoundRepository->get($roundId);

        // The body is optional - only for the yes to revealing secret puzzles
        $input = InternalApiInput::fromRequest($request, ['confirmReveal']);
        $confirmReveal = $input->bool('confirmReveal') ?? false;
        $input->throwIfInvalid();

        $this->messageBus->dispatch(new DeleteCompetitionRound(
            roundId: $round->id->toString(),
            refuseWhenItHasResults: true,
            refuseToReveal: $confirmReveal === false,
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
