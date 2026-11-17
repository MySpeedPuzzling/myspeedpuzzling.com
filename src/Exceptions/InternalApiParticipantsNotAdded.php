<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * `POST /internal-api/competitions/{competitionId}/participants` refused - nothing was added (docs/features/internal-api.md
 * "Online timing"): a name matching several participants of the event (`ambiguous_name`, 409), the participants sheet's
 * rules refusing a change (`refused`, 422) or the event changing between the read and the write (`changed_meanwhile`,
 * 409 - send it again).
 */
final class InternalApiParticipantsNotAdded extends HttpException implements InternalApiErrorCode
{
    public const string AMBIGUOUS_NAME = 'ambiguous_name';
    public const string REFUSED = 'refused';
    public const string CHANGED_MEANWHILE = 'changed_meanwhile';

    private function __construct(
        int $status,
        string $message,
        private readonly string $errorCode,
    ) {
        parent::__construct($status, $message);
    }

    /**
     * @param list<string> $names
     */
    public static function ambiguousNames(array $names): self
    {
        return new self(409, sprintf(
            'Several participants of the event have the name of: %s - send a name that tells them apart. Nothing was added.',
            implode(', ', $names),
        ), self::AMBIGUOUS_NAME);
    }

    /**
     * @param list<string> $reasons
     */
    public static function refused(array $reasons): self
    {
        return new self(422, sprintf('Refused by the participants sheet\'s rules: %s. Nothing was added.', implode('; ', $reasons)), self::REFUSED);
    }

    public static function changedMeanwhile(): self
    {
        return new self(409, 'The event\'s participants changed meanwhile - send it again. Nothing was added.', self::CHANGED_MEANWHILE);
    }

    public function internalApiErrorCode(): string
    {
        return $this->errorCode;
    }
}
