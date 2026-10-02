<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DuplicateDetectedBy: string
{
    // The first run over all existing results
    case Backfill = 'backfill';
    // The daily detection
    case Cron = 'cron';
    // While saving ("It's another solve, save it")
    case Save = 'save';
}
