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
 * A person nobody decided anything about is decided here, by the three things that need no person:
 *
 * 1. the importing player's remembered answer for the same name (ImportPersonDecision);
 * 2. **a code, id or profile address resolves by itself** - when the add form would let the importing player add
 *    that player and no block stands between them (ImportPartnerEligibility); the importing player's own code is
 *    "me";
 * 3. the importing player's own name is "me".
 *
 * **A plain name is never resolved to a player here** - only the importing player's answer does that.
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
        $ownNameKey = $importer->name === null || trim($importer->name) === '' ? null : ImportPersonText::nameKey($importer->name);

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

            if ($answer !== null) {
                $linked = $answer->linkedPlayer === null ? null : $this->eligibility->byId($importer, $answer->linkedPlayer->id->toString());

                // A remembered player who may not be added any more is no answer: the person is a guest, as by default
                if ($answer->choice !== ImportPersonChoice::Player || $linked !== null) {
                    $person->decide($answer->choice, $linked, ImportPersonDecidedHow::Remembered, $now);

                    continue;
                }
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

                continue;
            }

            if ($ownNameKey !== null && $key === $ownNameKey) {
                $person->decide(ImportPersonChoice::Me, null, ImportPersonDecidedHow::OwnName, $now);
            }
        }
    }
}
