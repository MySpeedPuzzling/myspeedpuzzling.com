<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A puzzle image analysis the internal API cannot store (PuzzleImageAnalysisInput) - answered `422` with
 * `{"error": "…", "errors": {"field": "…"}}` (InternalApiErrorResponseSubscriber); in a batch it is that item's
 * `invalid` outcome instead.
 */
final class InvalidPuzzleImageAnalysis extends UnprocessableEntityHttpException
{
    /**
     * @param array<string, string> $errors field => what is wrong with it
     */
    public function __construct(
        readonly public array $errors,
    ) {
        parent::__construct('Invalid image analysis: ' . implode('; ', array_map(
            static fn (string $field, string $error): string => sprintf('"%s" %s', $field, $error),
            array_keys($errors),
            $errors,
        )));
    }
}
