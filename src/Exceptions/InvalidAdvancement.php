<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * AdvanceQualified asked for something that cannot be planned - `reason` is a key of official_results.advance.error.*
 */
final class InvalidAdvancement extends UnprocessableEntityHttpException
{
    public function __construct(
        readonly public string $reason,
    ) {
        parent::__construct(sprintf('The advancement cannot be planned: %s.', $reason));
    }
}
