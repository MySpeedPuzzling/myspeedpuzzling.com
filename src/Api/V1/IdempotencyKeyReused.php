<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A 422 for an Idempotency-Key the token owner already used for a different result - another puzzle, time or day
 * (docs/features/duplicate-results.md, Layer 1). A retry of the same result is answered with the saved one instead;
 * a different result needs a key of its own. Nothing was saved.
 */
final class IdempotencyKeyReused extends UnprocessableEntityHttpException implements ProblemExceptionInterface
{
    public const string TYPE = '/errors/idempotency_key_reused';

    public function __construct(SolvingTimeIdReused $previous)
    {
        parent::__construct(
            'This Idempotency-Key was already used for a different result (another puzzle, time or finish day). Send a new key for a new result.',
            $previous,
        );
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTitle(): string
    {
        return 'Idempotency key reused';
    }

    public function getStatus(): int
    {
        return $this->getStatusCode();
    }

    public function getDetail(): string
    {
        return $this->getMessage();
    }

    public function getInstance(): null|string
    {
        return null;
    }
}
