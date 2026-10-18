<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The `image` of a change proposal filed through the internal API is not one the web form would accept
 * (ProposedPuzzleImageInput) - answered `422` with `{"error": "…", "errors": {"image": "…"}}`, nothing is filed.
 */
final class InvalidProposedPuzzleImage extends UnprocessableEntityHttpException
{
    /**
     * @param array<string, string> $errors field => what is wrong with it
     */
    public function __construct(
        readonly public array $errors,
    ) {
        parent::__construct('Invalid image: ' . implode('; ', $errors));
    }
}
