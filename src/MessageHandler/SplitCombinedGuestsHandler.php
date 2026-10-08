<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SplitCombinedGuests;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The pair/team left behind without results is removed by myspeedpuzzling:cleanup-empty-puzzling-teams.
 */
#[AsMessageHandler]
readonly final class SplitCombinedGuestsHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
    ) {
    }

    /**
     * @return list<array{timeId: string, before: list<string>, after: list<string>}>
     */
    public function __invoke(SplitCombinedGuests $message): array
    {
        $changes = [];

        foreach ($this->puzzleSolvingTimeRepository->findWithCombinedGuest() as $time) {
            if ($time->team === null) {
                continue;
            }

            $puzzlers = [];

            foreach ($time->team->puzzlers as $puzzler) {
                if ($puzzler->playerId !== null || str_contains($puzzler->playerName ?? '', ',') === false) {
                    $puzzlers[] = $puzzler;
                    continue;
                }

                foreach (PuzzlersGrouping::splitInputs([$puzzler->playerName ?? '']) as $name) {
                    $puzzlers[] = new Puzzler(playerId: null, playerName: $name, playerCode: null, playerCountry: null, isPrivate: false);
                }
            }

            $changes[] = [
                'timeId' => $time->id->toString(),
                'before' => self::names($time->team->puzzlers),
                'after' => self::names($puzzlers),
            ];

            if ($message->dryRun || $puzzlers === []) {
                continue;
            }

            $group = new PuzzlersGroup($time->team->teamId, $puzzlers);
            $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group);
            assert($puzzlingTeam !== null);

            $time->correctGroup($group, $puzzlingTeam);
        }

        return $changes;
    }

    /**
     * @param array<Puzzler> $puzzlers
     * @return list<string>
     */
    private static function names(array $puzzlers): array
    {
        return array_values(array_map(
            static fn(Puzzler $puzzler): string => $puzzler->playerId !== null ? ($puzzler->playerCode !== null ? '#' . $puzzler->playerCode : $puzzler->playerId) : (string) $puzzler->playerName,
            $puzzlers,
        ));
    }
}
