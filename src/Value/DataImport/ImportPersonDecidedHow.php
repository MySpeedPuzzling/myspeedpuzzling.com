<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How the decision about a person of an import was made (docs/features/data-import/people.md).
 */
enum ImportPersonDecidedHow: string
{
    /** Nobody decided anything: a guest */
    case ByDefault = 'default';
    /** The file writes a player's code, profile address or id, and that player may be added - by itself */
    case Code = 'code';
    /** The importing player said so on "People in your file" */
    case Player = 'player';
    /** The importing player's answer for the same name in an earlier import */
    case Remembered = 'remembered';
    /** The name is the importing player's own name */
    case OwnName = 'own_name';
}
