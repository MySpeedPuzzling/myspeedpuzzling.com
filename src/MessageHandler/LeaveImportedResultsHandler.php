<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImportPartnerOptOut;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\LeaveImportedResults;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Repository\DataImportPersonRepository;
use SpeedPuzzling\Web\Repository\ImportPartnerOptOutRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimeCustomValuesRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A registered player takes themself off the results somebody's spreadsheet imports put them on
 * (docs/features/data-import/people.md "A partner can get out"):
 *
 * - **the mark first** (ImportPartnerOptOut): from now on no import of that importing player resolves this player -
 *   for the importing player indistinguishable from a code nobody has (ImportPartnerEligibility);
 * - every such result **keeps existing**: in it the player becomes a guest carrying their name - the mechanism of
 *   ReplaceGroupMemberWithGuest ("a player added by mistake": PuzzleSolvingTime::replaceMistakenMember(), the
 *   pair/team resolved anew, their own custom values of the result go with them). The tracker stays the importing
 *   player; the group keeps its size;
 * - XP per result through the edit's own message (RecalculateXpChainForSolve), achievements **once** for the player
 *   and once for the importing player - not once per result.
 *
 * Only results made by a spreadsheet import and tracked by that importing player are touched: results the two
 * typed together stay as they are. Nobody is told. Run again it finds nothing.
 */
#[AsMessageHandler]
readonly final class LeaveImportedResultsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private DataImportPersonRepository $personRepository,
        private ImportPartnerOptOutRepository $optOutRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private SolvingTimeCustomValuesRepository $solvingTimeCustomValuesRepository,
        private MessageBusInterface $commandBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(LeaveImportedResults $message): int
    {
        if (Uuid::isValid($message->playerId) === false || Uuid::isValid($message->importerId) === false || strtolower($message->playerId) === strtolower($message->importerId)) {
            throw new PlayerNotFound();
        }

        // A second press waits here and finds the mark
        $this->idLock->lockUntilCommit(Uuid::fromString($message->playerId));
        $player = $this->playerRepository->get($message->playerId);
        $importer = $this->playerRepository->get($message->importerId);
        $playerId = $player->id->toString();

        if ($this->optOutRepository->find($playerId, $importer->id->toString()) === null) {
            $this->optOutRepository->save(new ImportPartnerOptOut(Uuid::uuid7(), $player, $importer, $this->clock->now()));
        }

        $guestName = trim((string) preg_replace('/\s+/u', ' ', $player->name ?? ''));
        $guestName = $guestName === '' ? $player->code : $guestName;
        $left = 0;

        foreach ($this->personRepository->importedResultIdsWith($importer->id->toString(), $playerId) as $timeId) {
            $time = $this->puzzleSolvingTimeRepository->get($timeId);

            if ($time->team === null || $time->isTrackedBy($player) || Puzzler::listContainsPlayer($time->team->puzzlers, $playerId) === false) {
                continue;
            }

            $membersBefore = $time->memberPlayerIds();
            $group = new PuzzlersGroup($time->team->teamId, array_values(array_map(
                static fn (Puzzler $puzzler): Puzzler => $puzzler->playerId === $playerId
                    ? new Puzzler(playerId: null, playerName: $guestName, playerCode: null, playerCountry: null, isPrivate: false)
                    : $puzzler,
                $time->team->puzzlers,
            )));
            $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group);
            assert($puzzlingTeam !== null);
            $time->replaceMistakenMember($group, $puzzlingTeam);

            // No longer on the result: their own values of it go with them (docs/features/custom-fields.md)
            $customValues = $this->solvingTimeCustomValuesRepository->find($time, $player);

            if ($customValues !== null) {
                $this->solvingTimeCustomValuesRepository->delete($customValues);
            }

            // Like an edit: the removed player's chain on the puzzle loses this solve
            $this->commandBus->dispatch(new RecalculateXpChainForSolve($time->id->toString(), $membersBefore));
            $left++;
        }

        if ($left > 0) {
            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($playerId));
            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($importer->id->toString()));
        }

        return $left;
    }
}
