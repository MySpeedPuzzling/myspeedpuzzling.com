<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum NotificationType: string
{
    case SubscribedPlayerAddedTime = 'SubscribedPlayerAddedTime';

    // A pair/team time the player is part of was edited by another group member
    case GroupSolvingTimeEdited = 'GroupSolvingTimeEdited';

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

    // Transaction rating notifications
    case RateYourTransaction = 'RateYourTransaction';

    // Conversation notifications
    case NewConversationRequest = 'NewConversationRequest';

    // Community moderator role (no target entity - the notification is the whole message)
    case ModeratorRoleGranted = 'ModeratorRoleGranted';
    case ModeratorRoleRevoked = 'ModeratorRoleRevoked';

    // A pair/team the player belongs to was named or renamed by another member
    case PuzzlingTeamRenamed = 'PuzzlingTeamRenamed';
}
