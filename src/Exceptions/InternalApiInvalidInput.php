<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Invalid fields of an internal API request - answered `400` with `{"error": "…", "errors": {"field": "…"}}`
 * (InternalApiErrorResponseSubscriber).
 */
final class InternalApiInvalidInput extends BadRequestHttpException
{
    /**
     * @param array<string, string> $errors field => what is wrong with it
     */
    public function __construct(
        readonly public array $errors,
    ) {
        parent::__construct('Invalid input: ' . implode('; ', array_map(
            static fn (string $field, string $error): string => sprintf('"%s" %s', $field, $error),
            array_keys($errors),
            $errors,
        )));
    }
}
