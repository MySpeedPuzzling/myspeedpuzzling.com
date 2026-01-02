<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Attribute;

use Attribute;
use SpeedPuzzling\Web\Events\DeleteDomainEventInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final class DeleteDomainEvent
{
    /**
     * @param class-string<DeleteDomainEventInterface> $eventClass
     */
    public function __construct(
        public string $eventClass,
    ) {
    }
}
