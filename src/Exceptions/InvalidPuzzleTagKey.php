<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\PuzzleTaxonomy;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A tag key that cannot be in the taxonomy: `<facet>.<key>` of a known facet (PuzzleTaxonomy::isWellFormedKey()).
 */
final class InvalidPuzzleTagKey extends UnprocessableEntityHttpException
{
    public function __construct(string $tagKey)
    {
        parent::__construct(sprintf(
            '"%s" is no tag key - expected <facet>.<key> (lower case, digits, underscores; at most %d characters) of a known facet.',
            mb_substr($tagKey, 0, 120),
            PuzzleTaxonomy::MAX_KEY_LENGTH,
        ));
    }
}
