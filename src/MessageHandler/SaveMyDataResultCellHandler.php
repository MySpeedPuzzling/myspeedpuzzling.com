<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Message\SaveMyDataResultCell;
use SpeedPuzzling\Web\Results\MyData\MyDataCellSave;
use SpeedPuzzling\Web\Results\MyData\MyDataResultEdit;
use SpeedPuzzling\Web\Services\MyData\MyDataResultEditor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Under the result's lock (SaveMyDataResultCell): the result as it is stored NOW, the three-way compare, then the
 * edit form's save for that one field (MyDataResultEditor → EditPuzzleSolvingTime, a transaction of its own).
 * Changes nothing itself - there is no transaction here to flush it.
 */
#[AsMessageHandler]
readonly final class SaveMyDataResultCellHandler
{
    public function __construct(
        private MyDataResultEditor $editor,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     */
    public function __invoke(SaveMyDataResultCell $message): MyDataCellSave
    {
        // Somebody else's result is not there at all
        $time = $this->editor->rowOf($message->viewer, $message->solvingTimeId);
        $field = $message->field;

        if (
            in_array($field, MyDataResultEdit::FIELDS, true) === false
            || $time->isEditableBy($message->viewer->playerId) === false
            || ($time->time === null && in_array($field, [MyDataResultEdit::TIME, MyDataResultEdit::FIRST_TRY], true))
        ) {
            return new MyDataCellSave(MyDataCellSave::READ_ONLY);
        }

        if (MyDataResultEditor::isOnlyRegisteredMember($message->viewer, $time) === false) {
            return new MyDataCellSave(MyDataCellSave::IN_SHEET_ONLY);
        }

        $stored = MyDataResultEdit::ofStored($time);

        if (self::same($stored->cell($field), $message->from) === false) {
            return new MyDataCellSave(MyDataCellSave::CHANGED_MEANWHILE);
        }

        $edit = $stored->withCell($field, $message->value);

        if ($edit === null) {
            return new MyDataCellSave(MyDataCellSave::INVALID);
        }

        // Never an answer to a question here: no "move the first try", no "it's another solve", no "yes, it's right"
        $outcome = $this->editor->save($message->viewer, $time, $edit);

        return match (true) {
            $outcome->isRefused() => new MyDataCellSave(MyDataCellSave::REFUSED, $outcome),
            $outcome->saved => new MyDataCellSave(MyDataCellSave::SAVED, $outcome),
            default => new MyDataCellSave(MyDataCellSave::UNCHANGED, $outcome),
        };
    }

    /**
     * What a cell showed against what is stored - line ends and outer spaces are the browser's business.
     */
    private static function same(string $stored, string $from): bool
    {
        return trim(str_replace("\r\n", "\n", $stored)) === trim(str_replace("\r\n", "\n", $from));
    }
}
