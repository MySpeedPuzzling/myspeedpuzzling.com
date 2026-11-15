<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Repository\DataImportPersonRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonText;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * The other people of a row as the writer takes them (docs/features/data-import/people.md "The engine"), read
 * **when the row is added** from the import's people as they are decided then:
 *
 * - a person decided to be a registered player - and still allowed (ImportPartnerEligibility: no block either way
 *   right now) - leaves as `#code`, which the writer reads as that player, exactly like the add form reads a code;
 * - "me" is left out - it is the importing player;
 * - everybody else is a guest of the name the file writes: **no entry leaves here with a `#` that this class did not
 *   put there**, so the writer never resolves a code on its own - a code nobody has, a code of a player with a block
 *   and a name are all guests.
 *
 * A row without people costs no statement; a row with people two (its people, the blocks).
 */
readonly final class ImportRowPeople
{
    public function __construct(
        private DataImportPersonRepository $personRepository,
        private ImportPartnerEligibility $eligibility,
    ) {
    }

    /**
     * @return array{people: list<string>, registeredPlayerIds: list<string>, named: int}
     *         people: one entry per other person, for ImportedResult::$groupPlayers; registeredPlayerIds: the players
     *         among them; named: how many people the row names (with "me", before anybody was left out)
     */
    public function of(DataImportRow $row): array
    {
        $texts = [];

        foreach (is_array($row->parsed['people'] ?? null) ? $row->parsed['people'] : [] as $person) {
            $text = is_string($person) ? ImportPersonText::of($person) : null;

            if ($text !== null) {
                $texts[$text->key] ??= $text;
            }
        }

        if ($texts === []) {
            return ['people' => [], 'registeredPlayerIds' => [], 'named' => 0];
        }

        $importer = $row->import->player;
        $decided = $this->personRepository->ofImportByKeys($row->import, array_map(strval(...), array_keys($texts)));
        $players = [];

        foreach ($decided as $key => $person) {
            $player = $person->playerNow();

            if ($player !== null) {
                $players[$key] = $player;
            }
        }

        $notAllowed = $players === []
            ? []
            : $this->eligibility->notAllowedAmong($importer, array_values(array_map(static fn ($player): string => $player->id->toString(), $players)));

        $people = [];
        $registered = [];

        foreach ($texts as $key => $text) {
            $person = $decided[$key] ?? null;
            $player = $players[$key] ?? null;

            // A code the import's people do not know yet (an answer typed after they were built): resolved here, by
            // the same rule - eligibility answers null for a code nobody has and for a player with a block alike
            if ($person === null && $text->isWrittenAsPlayer()) {
                $player = $text->code !== null
                    ? $this->eligibility->byCode($importer, $text->code)
                    : $this->eligibility->byId($importer, (string) $text->playerId);
            }

            if ($person !== null && $person->choice === ImportPersonChoice::Me) {
                continue;
            }

            if ($player !== null && $player->id->equals($importer->id)) {
                continue;
            }

            if ($player !== null && in_array($player->id->toString(), $notAllowed, true) === false) {
                $people[] = '#' . $player->code;
                $registered[] = $player->id->toString();

                continue;
            }

            $people[] = $text->name;
        }

        return [
            'people' => array_values(array_unique($people)),
            'registeredPlayerIds' => array_values(array_unique($registered)),
            'named' => count($texts),
        ];
    }

    /**
     * Solo, pair or team once "me" is left out of the people: a file that writes "Jan & Ana" for a pair of the
     * importing Jan names two people, and its kind was counted from them (ImportRowParser) - it is a pair, not a
     * team. Only a kind that was the count of the named people moves with them; a kind a column said stays.
     */
    public static function typeWithout(PuzzlingType $said, int $named, int $others): PuzzlingType
    {
        if ($named === $others || PuzzlingType::fromPuzzlersCount($named + 1) !== $said) {
            return $said;
        }

        return PuzzlingType::fromPuzzlersCount($others + 1);
    }
}
