<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use SpeedPuzzling\Web\Entity\EntityWithEvents;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DomainEventsSubscriber
{
    /** @var array<EntityWithEvents> */
    private array $entities = [];

    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    public function postPersist(PostPersistEventArgs $eventArgs): void
    {
        $this->collectEventsFromEntity($eventArgs);
    }

    public function postUpdate(PostUpdateEventArgs $eventArgs): void
    {
        $this->collectEventsFromEntity($eventArgs);
    }

    public function postRemove(PostRemoveEventArgs $eventArgs): void
    {
        $this->collectEventsFromEntity($eventArgs);
    }

    public function postFlush(PostFlushEventArgs $eventArgs): void
    {
        $this->dispatchEvents();
    }

    private function collectEventsFromEntity(
        PostPersistEventArgs|PostUpdateEventArgs|PostRemoveEventArgs $eventArgs,
    ): void {
        $entity = $eventArgs->getObject();

        if ($entity instanceof EntityWithEvents) {
            $this->entities[] = $entity;
        }
    }

    private function dispatchEvents(): void
    {
        $entities = $this->entities;
        $this->entities = [];

        foreach ($entities as $entity) {
            foreach ($entity->popEvents() as $event) {
                $this->messageBus->dispatch($event);
            }
        }
    }
}
