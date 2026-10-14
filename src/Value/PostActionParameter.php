<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * What a value of a one-tap action may be (PostAction, docs/features/navigation GEST-15, decision 99): the confirmation
 * view passes on only values of these shapes - the target route checks them again, this only keeps a link from
 * carrying anything else.
 */
enum PostActionParameter: string
{
    /** An id in the path (a puzzle, a player, an event, a listing, a lent puzzle) */
    case Uuid = 'uuid';

    /** A comparison subject: p-<uuid> / t-<uuid> (ComparisonSubjectRef) */
    case Subject = 'subject';

    /** A follow target: competition:<uuid> / series:<uuid> / organization:<uuid> (FollowTarget) */
    case FollowTarget = 'follow_target';

    /** Where the target goes back to: a relative path (ReturnUrl) */
    case ReturnPath = 'return_path';

    /** Which page asked, for the target's Turbo Stream (detail, list, collection-detail, marketplace, ...) */
    case Context = 'context';

    /** A collection id, the system collection's marker or nothing */
    case Collection = 'collection';

    /** A tab of a list page (lent / borrowed) */
    case Tab = 'tab';

    /** A country code (the target refuses one it does not know) */
    case Country = 'country';

    public function accepts(string $value): bool
    {
        return match ($this) {
            self::Uuid => Uuid::isValid($value),
            self::Subject => ComparisonSubjectRef::tryFromString($value) !== null,
            self::FollowTarget => FollowTarget::tryFromString($value) !== null,
            self::ReturnPath => $value === '' || ReturnUrl::tryFrom($value) !== null,
            self::Context => $value === '' || preg_match('~\A[a-z][a-z-]{0,39}\z~', $value) === 1,
            self::Collection => $value === '' || $value === '__system_collection__' || Uuid::isValid($value),
            self::Tab => $value === '' || preg_match('~\A[a-z][a-z_-]{0,19}\z~', $value) === 1,
            self::Country => $value === '' || preg_match('~\A[a-z]{2,3}\z~', $value) === 1,
        };
    }
}
