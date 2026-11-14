<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\GuestNameMissing;
use SpeedPuzzling\Web\Exceptions\PlayerNotInGroupTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\ReplaceGroupMemberWithGuest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimeCustomValuesRepository;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The pair/team the result leaves behind without results is removed by myspeedpuzzling:cleanup-empty-puzzling-teams.
 * Nobody is notified: the tracker gets the result they meant, the removed player wrote to us.
 */
#[AsMessageHandler]
readonly final class ReplaceGroupMemberWithGuestHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private MessageBusInterface $commandBus,
        private SolvingTimeCustomValuesRepository $solvingTimeCustomValuesRepository,
    ) {
    }

    public function __invoke(ReplaceGroupMemberWithGuest $message): void
    {
        $time = $this->puzzleSolvingTimeRepository->get($message->timeId);
        $player = $this->playerRepository->get($message->playerId);
        $playerId = $player->id->toString();

        if ($time->isTrackedBy($player)) {
            throw PlayerNotInGroupTime::isTracker();
        }

        if ($time->team === null || Puzzler::listContainsPlayer($time->team->puzzlers, $playerId) === false) {
            throw PlayerNotInGroupTime::notInGroup();
        }

        $guestName = trim((string) preg_replace('/\s+/u', ' ', $message->guestName ?? $player->name ?? ''));

        if ($guestName === '') {
            throw new GuestNameMissing();
        }

        $membersBefore = $time->memberPlayerIds();

        $puzzlers = array_map(
            static fn (Puzzler $puzzler): Puzzler => $puzzler->playerId === $playerId
                ? new Puzzler(playerId: null, playerName: $guestName, playerCode: null, playerCountry: null, isPrivate: false)
                : $puzzler,
            $time->team->puzzlers,
        );

        $group = new PuzzlersGroup($time->team->teamId, array_values($puzzlers));
        $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group);
        assert($puzzlingTeam !== null);

        $time->replaceMistakenMember($group, $puzzlingTeam);

        // No longer on the result: their own values of it go with them (docs/features/custom-fields.md)
        $customValues = $this->solvingTimeCustomValuesRepository->find($time, $player);

        if ($customValues !== null) {
            $this->solvingTimeCustomValuesRepository->delete($customValues);
        }

        foreach ($membersBefore as $memberPlayerId) {
            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
        }

        // Like an edit: the removed player's chain on the puzzle loses this solve
        $this->commandBus->dispatch(new RecalculateXpChainForSolve($time->id->toString(), $membersBefore));
    }
}
