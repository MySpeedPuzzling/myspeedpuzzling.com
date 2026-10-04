<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Vendor warnings that report routine events, logged at info instead - a warning becomes a Sentry issue, and a person
 * should look at it (CLAUDE.md, log levels). Exact messages only: anything reworded upstream stays a warning.
 *
 * - Symfony's stateless CSRF check finding neither an Origin/Referer header nor the double-submit cookie on a POST:
 *   every browser sends Origin with a POST, so only scripts and scanners end up here (WEB-D1: "SQLSpider" posting to
 *   /login and /register). The request is refused either way. A mismatching origin or a broken token stay warnings.
 */
#[AsMonologProcessor]
final readonly class DowngradeRoutineWarningsProcessor implements ProcessorInterface
{
    private const array ROUTINE_MESSAGES = [
        'CSRF validation failed: double-submit and origin info not found.',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($record->level !== Level::Warning || in_array($record->message, self::ROUTINE_MESSAGES, true) === false) {
            return $record;
        }

        return $record->with(level: Level::Info);
    }
}
