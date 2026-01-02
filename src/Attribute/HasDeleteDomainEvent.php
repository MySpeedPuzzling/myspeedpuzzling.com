<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Attribute;

use Attribute;
use SpeedPuzzling\Web\Events\DeleteDomainEvent;

#[Attribute(Attribute::TARGET_CLASS)]
final class HasDeleteDomainEvent
{
    /**
     * @param class-string<DeleteDomainEvent> $eventClass
     */
    public function __construct(
        public string $eventClass,
    ) {
    }
}
