<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Closure;
use JsonSerializable;

/**
 * A value worked out only when it is JSON-encoded - for form options that are encoded once, while the form is rendered
 */
readonly final class LazyJsonValue implements JsonSerializable
{
    /**
     * @param Closure(): mixed $value
     */
    public function __construct(
        private Closure $value,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return ($this->value)();
    }
}
