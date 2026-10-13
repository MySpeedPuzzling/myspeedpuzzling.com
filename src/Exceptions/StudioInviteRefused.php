<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Entity\StudioInvite;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An invitation to the studio, or an answer to one, was refused (InviteToStudio, AnswerStudioInvite,
 * docs/features/online-competitions/contracts/phase-1b.md §4) - thrown before anything was written, so nothing is
 * committed. `reason` is the endpoints' error code (409):
 * - `video_unavailable`: the round's live video is off;
 * - `not_entered`: the participant is not entered in the round;
 * - `already_invited`: the participant has an open invitation in the round (`invite` = that one) - one at a time;
 * - `invite_not_open`: the invitation was answered differently or ended already;
 * - `invite_expired`: nobody answered it within StudioInvite::EXPIRES_AFTER_SECONDS.
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class StudioInviteRefused extends ConflictHttpException
{
    public const string VIDEO_UNAVAILABLE = 'video_unavailable';
    public const string NOT_ENTERED = 'not_entered';
    public const string ALREADY_INVITED = 'already_invited';
    public const string NOT_OPEN = 'invite_not_open';
    public const string EXPIRED = 'invite_expired';

    private function __construct(
        readonly public string $reason,
        readonly public null|StudioInvite $invite = null,
    ) {
        parent::__construct(sprintf('The studio invitation was refused (%s). Nothing was changed.', $reason));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    /**
     * One open invitation per participant and round - this one
     */
    public static function alreadyInvited(StudioInvite $open): self
    {
        return new self(self::ALREADY_INVITED, $open);
    }
}
