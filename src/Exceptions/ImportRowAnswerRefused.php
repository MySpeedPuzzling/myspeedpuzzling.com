<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * An answer about rows of an import can not be taken as sent - nothing was written. `reason`: `unreadable` (the
 * typed value can not be read for sure as that field), `not_answerable` (a field nobody can answer), `too_many_rows`,
 * `nothing` (neither a value nor "leave out").
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class ImportRowAnswerRefused extends \DomainException
{
    public const string UNREADABLE = 'unreadable';
    public const string NOT_ANSWERABLE = 'not_answerable';
    public const string TOO_MANY_ROWS = 'too_many_rows';
    public const string NOTHING = 'nothing';

    public function __construct(
        public readonly string $reason,
        /** An ImportField's value - null when the refusal is about no field */
        public readonly null|string $field = null,
    ) {
        parent::__construct('The answer about rows of the import was refused: ' . $reason);
    }
}
