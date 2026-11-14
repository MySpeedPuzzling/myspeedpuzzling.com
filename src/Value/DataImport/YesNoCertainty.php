<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

enum YesNoCertainty: string
{
    /** Nothing in the cell - nothing was said */
    case Blank = 'blank';
    /** A word, mark or typed value that means yes or no */
    case Known = 'known';
    /** A word that is neither - the layer above asks what it means ("Back to the library") */
    case Unknown = 'unknown';
}
