<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * An uploaded participant list that is not a readable .xlsx workbook or CSV/TSV text, or a sheet it does not have
 * (ParticipantFileReader). The organiser is told `competition.participants.import.file.unreadable`.
 */
final class ParticipantFileUnreadable extends UnprocessableEntityHttpException
{
    public function __construct(string $reason, null|\Throwable $previous = null)
    {
        parent::__construct('The participant file could not be read: ' . $reason, $previous);
    }
}
