<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The importing player's answer about a person of their file can not be taken
 * (docs/features/data-import/people.md "Answers"): nothing was written.
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class ImportPersonChoiceRefused extends \DomainException
{
    /** The person is not one of this import */
    public const string UNKNOWN_PERSON = 'unknown_person';
    /**
     * No player to be: a code nobody has - or a player who may not be added. One reason for both, on purpose: the
     * importing player must not be able to tell a block from an unknown code
     */
    public const string UNKNOWN_PLAYER = 'unknown_player';
    /** Not an answer: a player without a player or a code */
    public const string INVALID = 'invalid';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('The answer about a person of an import was refused: %s.', $reason));
    }
}
