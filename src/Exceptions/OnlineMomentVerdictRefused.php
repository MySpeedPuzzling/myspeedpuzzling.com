<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The reviewer's gap mark on a finish moment was refused (MarkOnlineMomentGap / UnmarkOnlineMomentGap,
 * docs/features/online-competitions/README.md §5.9) - thrown before anything was written. 422 with the reason
 * (online_rounds.review.reason.*):
 * - `moment_not_found`: the id is no finish moment of this participant in this round - not an event of theirs, not a
 *   raise of the hands (`finish.candidate`) or a Finished tap (`finish.button`), or a raise in a round whose detection
 *   only watches (`shadow` - the reviewer never sees one there, so it is answered as unknown, never as a candidate).
 * An HttpException, so it reaches the controller as itself through the command bus (UnwrapHttpExceptionMiddleware).
 */
final class OnlineMomentVerdictRefused extends HttpException
{
    public const string MOMENT_NOT_FOUND = 'moment_not_found';

    private function __construct(
        readonly public string $reason,
    ) {
        parent::__construct(422, sprintf('The gap mark was refused (%s). Nothing was changed.', $reason));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function translationKey(): string
    {
        return 'online_rounds.review.reason.' . $this->reason;
    }
}
