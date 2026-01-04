<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

/**
 * Marker interface for events/messages that require a fresh entity manager state.
 *
 * Events dispatched from Doctrine's postFlush (via DomainEventsSubscriber) may encounter
 * identity map conflicts when they modify/delete entities that were loaded by the previous handler.
 * Implementing this interface signals the middleware to clear the entity manager before handling.
 */
interface RequiresFreshEntityManagerState
{
}
