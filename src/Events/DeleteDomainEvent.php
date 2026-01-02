<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

/**
 * Interface for delete domain events that can be created from an entity.
 */
interface DeleteDomainEvent
{
    public static function fromEntity(object $entity): static;
}
