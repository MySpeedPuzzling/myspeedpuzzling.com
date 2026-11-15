<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportPerson;
use SpeedPuzzling\Web\Repository\DataImportPersonRepository;
use SpeedPuzzling\Web\Repository\ImportPersonDecisionRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonDecidedHow;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonText;

/**
 * The people of an import, built from its rows as they are parsed now (docs/features/data-import/people.md
 * "Building") - with the import's puzzles, under the import's lock (BuildImportPuzzlesHandler). Run again it changes
 * nothing that was decided: a person still named keeps their row and their decision, a person no row names any more
 * goes, a new one arrives.
 *
 * A person nobody decided anything about is decided here only by what needs no person:
 *
 * 1. the importing player's remembered **"keep as a guest"** for the same name (ImportPersonDecision) - the safe
 *    default, applied silently;
 * 2. **a code, id or profile address resolves by itself** - when the add form would let the importing player add
 *    that player and nothing stands between them (ImportPartnerEligibility); the importing player's own code is
 *    "me".
 *
 * **A name never becomes a player - or "me" - here**: not by a remembered answer of an earlier file (it is offered
 * on the page, "last time Anna was ... - still right?", and the name stays a guest for adding until the importing
 * player says yes in this import), and not because it is the importing player's own name (asked: "is this you?").
 */
readonly final class ImportPeopleBuilder
{
    public function __construct(
        private DataImportPersonRepository $personRepository,
        private ImportPersonDecisionRepository $decisionRepository,
        private ImportPartnerEligibility $eligibility,
    ) {
    }

    public function build(DataImport $import, DateTimeImmutable $now): void
    {
        /** @var array<string, array{text: ImportPersonText, rows: int, exampleRowIds: list<string>}> $named */
        $named = [];

        foreach ($this->personRepository->distinctPeopleOfRows($import) as $said) {
            $text = ImportPersonText::of($said['text']);

            if ($text === null) {
                continue;
            }

            if (isset($named[$text->key]) === false) {
                $named[$text->key] = ['text' => $text, 'rows' => 0, 'exampleRowIds' => []];
            }

            $named[$text->key]['rows'] += $said['rows'];
            $named[$text->key]['exampleRowIds'] = array_slice([...$named[$text->key]['exampleRowIds'], ...$said['exampleRowIds']], 0, 2);
        }

        $existing = [];

        foreach ($this->personRepository->ofImport($import) as $person) {
            if (isset($named[$person->personKey])) {
                $existing[$person->personKey] = $person;
            } else {
                $this->personRepository->delete($person);
            }
        }

        $importer = $import->player;
        $remembered = $this->decisionRepository->ofPlayerByKeys($importer, array_map(strval(...), array_keys($named)));

        foreach ($named as $key => $said) {
            $key = (string) $key;
            $text = $said['text'];
            $person = $existing[$key] ?? null;

            if ($person === null) {
                $person = new DataImportPerson(Uuid::uuid7(), $import, $key, $text->name, $text->isWrittenAsPlayer(), $said['rows'], $said['exampleRowIds']);
                $this->personRepository->save($person);
            } else {
                $person->seen($text->name, $said['rows'], $said['exampleRowIds']);
            }

            if ($person->isUndecided() === false) {
                continue;
            }

            $answer = $remembered[$key] ?? null;

            if ($answer !== null && $answer->choice === ImportPersonChoice::Guest) {
                $person->decide(ImportPersonChoice::Guest, null, ImportPersonDecidedHow::Remembered, $now);

                continue;
            }

            // A remembered player or "me" for a name is the page's to offer - nothing is decided by it here
            if ($answer !== null && $text->isWrittenAsPlayer() === false) {
                continue;
            }

            if ($text->isWrittenAsPlayer()) {
                $player = $text->code !== null
                    ? $this->eligibility->byCode($importer, $text->code)
                    : $this->eligibility->byId($importer, (string) $text->playerId);

                if ($player !== null && $player->id->equals($importer->id)) {
                    $person->decide(ImportPersonChoice::Me, null, ImportPersonDecidedHow::Code, $now);
                } elseif ($player !== null) {
                    $person->decide(ImportPersonChoice::Player, $player, ImportPersonDecidedHow::Code, $now);
                }
            }
        }
    }
}
