<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class DeleteDomainEvent
{
    /**
     * @param class-string $eventClass
     */
    public function __construct(
        public string $eventClass,
    ) {
    }
}
