<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The reviewer's confirmation of an online result changed nothing (ConfirmOnlineResultHandler,
 * docs/features/online-competitions/README.md §5.9) - thrown before anything was written, so the transaction commits
 * nothing. An HttpException, so it reaches the controller as itself through the command bus
 * (UnwrapHttpExceptionMiddleware):
 * - 409: the official result is not the one the reviewer saw - somebody entered another one meanwhile (the write path's
 *   three-way `conflict`); `current` = the result the entry holds now, the reviewer decides again;
 * - 422: the confirmation itself is refused - our reasons (online_rounds.review.reason.*) or the official results
 *   write path's (official_results.reason.*, `officialReason`).
 */
final class OnlineResultRefused extends HttpException
{
    public const string NOT_CONNECTED = 'not_connected';
    public const string INVALID_CONFIRMATION = 'invalid_confirmation';
    public const string ROUND_NOT_STARTED = 'round_not_started';
    public const string MOMENT_BEFORE_START = 'moment_before_start';
    public const string MOMENT_AFTER_END = 'moment_after_end';
    public const string MOMENT_NOT_RECORDED = 'moment_not_recorded';

    private function __construct(
        int $statusCode,
        readonly public string $reason,
        // The reason is one of the official results write path's (RecordRoundResultsHandler outcomes)
        readonly public bool $officialReason,
        // A conflict: the official result the entry holds now
        readonly public null|RoundEntryResult $current = null,
    ) {
        parent::__construct($statusCode, sprintf('The online result was not confirmed (%s). Nothing was changed.', $reason));
    }

    public static function because(string $reason): self
    {
        return new self(422, $reason, false);
    }

    /**
     * The official result write path refused the change (`rejected`)
     */
    public static function rejectedByOfficialResults(string $reason): self
    {
        return new self(422, $reason, true);
    }

    /**
     * The official result holds another value than the one the reviewer saw (`conflict`)
     */
    public static function conflict(string $reason, RoundEntryResult $current): self
    {
        return new self(409, $reason, true, $current);
    }

    public function translationKey(): string
    {
        return ($this->officialReason ? 'official_results.reason.' : 'online_rounds.review.reason.') . $this->reason;
    }
}
