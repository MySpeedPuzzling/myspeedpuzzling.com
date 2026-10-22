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
    // An admin invited the player - the notification leads to the moderator guide, where they accept or decline
    case ModeratorInvitationReceived = 'ModeratorInvitationReceived';

    // A pair/team the player belongs to was named or renamed by another member
    case PuzzlingTeamRenamed = 'PuzzlingTeamRenamed';

    // "This guest of mine is you" - asked, and agreed to (GuestLinkRequest)
    case GuestLinkRequested = 'GuestLinkRequested';
    case GuestLinkAccepted = 'GuestLinkAccepted';

    // A round's official results were published for the first time and the player has a finished result in it
    case OfficialResultPublished = 'OfficialResultPublished';

    // "I'd like to puzzle with you" on the player's partner finder listing (PartnerRequest), actor = the sender
    case PartnerRequest = 'PartnerRequest';

    // The actor closed their listing as found and named the player as who they teamed up with - so the player can close
    // theirs too (the player had an open listing at the event); target = the actor's listing
    case PartnerTeamedUp = 'PartnerTeamedUp';

    // The actor named the player as a member of their group in their partner finder listing; target = that listing
    case PartnerGroupMember = 'PartnerGroupMember';
}
