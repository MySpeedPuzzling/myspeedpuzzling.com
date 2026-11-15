<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValue;
use SpeedPuzzling\Web\Message\AddCustomField;
use SpeedPuzzling\Web\Message\SetCollectionItemCustomValues;
use SpeedPuzzling\Web\Message\SetSolvingTimeCustomValues;
use SpeedPuzzling\Web\Message\WriteImportRowCustomValues;
use SpeedPuzzling\Web\Repository\CustomFieldRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Value\CustomValues;
use SpeedPuzzling\Web\Value\DataImportRowState;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The values of the importing player's own fields that a row carries (`parsed.custom`, keyed by a ready-made field's
 * key, and the words that stood beside a time - `parsed.notes` - as private notes), written on what the row made
 * (docs/features/data-import/adding.md "The player's own fields"):
 *
 * - on the result the row made, for a field that is for results - through SetSolvingTimeCustomValues, THE writer of
 *   those values: the importing player's own row of the result, nobody else's;
 * - else on the collection item the row created, for a field that is for collection items - through
 *   SetCollectionItemCustomValues. Never on a result or an item that was there before.
 *
 * A ready-made field the player does not have yet is added for them (AddCustomField::readyMade() - README "a column
 * mapped to a new custom field creates it at Add"; the field stays when the rows are undone). A value that cannot be
 * stored - no room for another field, a value the field's type refuses, nothing of the row to put it on - is left
 * out and counted on the row; it never fails the row, which is added and committed before this runs.
 *
 * Private by construction: the acting player's own fields and values only, nothing is read out or shown, and nothing
 * of a value is logged - not here and not by the caller (ProcessImportBatchHandler names the exception's class).
 */
#[AsMessageHandler]
readonly final class WriteImportRowCustomValuesHandler
{
    public function __construct(
        private DataImportRowRepository $rowRepository,
        private CustomFieldRepository $customFieldRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(WriteImportRowCustomValues $message): void
    {
        $row = $this->rowRepository->find($message->rowId);

        if ($row === null || $row->state !== DataImportRowState::Added || $row->import->isOwnedBy(strtolower($message->playerId)) === false) {
            return;
        }

        // Once per "Add" of the row
        if (isset($row->made['customFields'])) {
            return;
        }

        $player = $row->import->player;
        $playerId = $player->id->toString();
        $said = self::said($row->parsed ?? []);

        if ($said === []) {
            return;
        }

        $resultId = $row->resultMade ? $row->solvingTimeId?->toString() : null;
        $itemId = $row->collectionItemId?->toString();
        $leftOut = 0;
        $onResult = [];
        $onItem = [];

        foreach ($said as $key => $value) {
            $standard = StandardCustomField::tryFrom($key);
            $target = match (true) {
                $standard === null => null,
                $resultId !== null && $standard->forResults() => 'result',
                $itemId !== null && $standard->forCollectionItems() => 'item',
                default => null,
            };

            if ($standard === null || $target === null) {
                $leftOut++;

                continue;
            }

            $field = $this->fieldOf($playerId, $standard);

            if ($field === null) {
                $leftOut++;

                continue;
            }

            try {
                // The writers' own reading of a value, asked first: one wrong value must not refuse the others
                if (CustomValues::normalise($field->type, $field->choices, $value) === null) {
                    continue;
                }
            } catch (InvalidCustomValue) {
                $leftOut++;

                continue;
            }

            if ($target === 'result') {
                $onResult[$field->id->toString()] = $value;
            } else {
                $onItem[$field->id->toString()] = $value;
            }
        }

        if ($resultId !== null && $onResult !== []) {
            $this->messageBus->dispatch(new SetSolvingTimeCustomValues($resultId, $playerId, $onResult));
        }

        if ($itemId !== null && $onItem !== []) {
            $this->messageBus->dispatch(new SetCollectionItemCustomValues($itemId, $playerId, $onItem));
        }

        $row->customFieldsFilled(array_keys($onResult), array_keys($onItem), $leftOut);
    }

    /**
     * The player's field for a ready-made key that can take a value - added when they do not have it yet.
     */
    private function fieldOf(string $playerId, StandardCustomField $standard): null|CustomField
    {
        $find = function () use ($playerId, $standard): null|CustomField {
            foreach ($this->customFieldRepository->ofPlayerId($playerId) as $field) {
                if ($field->standardKey === $standard && $field->isArchived() === false) {
                    return $field;
                }
            }

            return null;
        };

        $field = $find();

        if ($field !== null) {
            return $field;
        }

        try {
            $this->messageBus->dispatch(AddCustomField::readyMade(Uuid::uuid7()->toString(), $playerId, $standard));
        } catch (\Throwable) {
            // No room for another field, or its name is taken by one of the player's own: the value is left out
            return null;
        }

        return $find();
    }

    /**
     * What the row says for the player's own fields, as a form would send it: by ready-made key.
     *
     * @param array<mixed> $parsed
     *
     * @return array<string, string>
     */
    private static function said(array $parsed): array
    {
        $said = [];

        foreach (is_array($parsed['custom'] ?? null) ? $parsed['custom'] : [] as $key => $value) {
            if (is_string($key) === false || (is_scalar($value) === false)) {
                continue;
            }

            $input = CustomValues::toInput($value);

            if (trim($input) !== '') {
                $said[$key] = $input;
            }
        }

        $notes = [];

        foreach (is_array($parsed['notes'] ?? null) ? $parsed['notes'] : [] as $note) {
            if (is_string($note) && trim($note) !== '') {
                $notes[] = trim($note);
            }
        }

        if ($notes !== []) {
            $key = StandardCustomField::PrivateNotes->value;
            $said[$key] = trim(($said[$key] ?? '') . "\n" . implode("\n", $notes));
        }

        return $said;
    }
}
