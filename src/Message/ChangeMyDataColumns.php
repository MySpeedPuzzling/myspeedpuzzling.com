<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MyDataTab;

/**
 * The player chose which columns one tab of "My data" shows (docs/features/my-data.md "The column chooser").
 */
readonly final class ChangeMyDataColumns
{
    /**
     * @param list<string> $offeredColumns the columns the chooser's form listed
     * @param list<string> $shownColumns the ticked ones - the other offered ones are switched off; an id that is no
     *                                   column of the tab is dropped
     */
    public function __construct(
        public string $playerId,
        public MyDataTab $tab,
        public array $offeredColumns,
        public array $shownColumns,
    ) {
    }
}
