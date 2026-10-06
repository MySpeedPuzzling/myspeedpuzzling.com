<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The internal API's refusal of a round's puzzle list - a hidden puzzle (a competition's secret, a placeholder) would be
 * attached unhidden and shown on the event page, or a puzzle got attached elsewhere meanwhile. An expected 409 with the
 * reason, nothing created (logged at info, config/packages/framework.php).
 */
final class RoundPuzzlesNotAttachable extends ConflictHttpException
{
    public function __construct(string $message, null|\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
