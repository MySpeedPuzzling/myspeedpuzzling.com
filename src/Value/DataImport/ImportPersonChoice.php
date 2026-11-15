<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * Who a person named in an import's file is (docs/features/data-import/people.md).
 */
enum ImportPersonChoice: string
{
    /** Somebody without an account - the default, and what every name stays until the importing player says more */
    case Guest = 'guest';
    /** A registered player: a code of the file that resolved by itself, or the importing player's own answer */
    case Player = 'player';
    /** The importing player themself, written among the partners - left out of them */
    case Me = 'me';
}
