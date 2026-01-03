<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum NotificationType: string
{
    case SubscribedPlayerAddedTime = 'SubscribedPlayerAddedTime';

    // Lending notifications
    case PuzzleLentToYou = 'PuzzleLentToYou';
    case PuzzleBorrowedFromYou = 'PuzzleBorrowedFromYou';
    case PuzzleReturnedToYou = 'PuzzleReturnedToYou';
    case PuzzleTakenBack = 'PuzzleTakenBack';
    case PuzzlePassedToYou = 'PuzzlePassedToYou';
    case PuzzlePassedFromYou = 'PuzzlePassedFromYou';
    case YourPuzzleWasPassed = 'YourPuzzleWasPassed';

    // Puzzle report notifications
    case PuzzleChangeRequestApproved = 'PuzzleChangeRequestApproved';
    case PuzzleChangeRequestRejected = 'PuzzleChangeRequestRejected';
    case PuzzleMergeRequestApproved = 'PuzzleMergeRequestApproved';
    case PuzzleMergeRequestRejected = 'PuzzleMergeRequestRejected';
}
