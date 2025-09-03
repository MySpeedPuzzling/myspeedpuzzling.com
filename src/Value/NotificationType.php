<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum NotificationType: string
{
    case SubscribedPlayerAddedTime = 'SubscribedPlayerAddedTime';
    case PuzzleBorrowedTo = 'PuzzleBorrowedTo';
    case PuzzleBorrowedFrom = 'PuzzleBorrowedFrom';
    case PuzzleReturned = 'PuzzleReturned';
}
