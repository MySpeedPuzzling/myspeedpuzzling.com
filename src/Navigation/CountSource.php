<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Where a row's count or status comes from (navigation README §4.12 "Menu data"): the profile row, the Inbox badge
 * statement, the per-page admin_queue_counts() statement, or the lazy menu_counts endpoint - never a statement of its
 * own per page view.
 */
enum CountSource: string
{
    case InboxBadge = 'inbox_badge';
    // admin_queue_counts() - GetAdminQueueCounts, one statement per page for moderators and admins
    case QueueChangeRequests = 'queue_change_requests';
    case QueueMergeRequests = 'queue_merge_requests';
    case QueuePuzzleApprovals = 'queue_puzzle_approvals';
    case QueueTimeVerification = 'queue_time_verification';
    case QueueCompetitionApprovals = 'queue_competition_approvals';
    case QueueOauth2Requests = 'queue_oauth2_requests';
    case QueueDuplicateSignals = 'queue_duplicate_signals';
    // menu_counts (signed-in, loaded when the panel opens)
    case LazyOrganised = 'lazy_organised';
    case LazyReview = 'lazy_review';
    // The profile row
    case LineUp = 'line_up';
    case Stopwatch = 'stopwatch';
    case Level = 'level';
    case Karma = 'karma';
    case MembershipStatus = 'membership_status';
}
