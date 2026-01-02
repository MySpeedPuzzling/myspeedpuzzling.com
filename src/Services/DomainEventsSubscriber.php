<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use ReflectionClass;
use SpeedPuzzling\Web\Attribute\HasDeleteDomainEvent;
use SpeedPuzzling\Web\Entity\EntityWithEvents;
use SpeedPuzzling\Web\Events\DeleteDomainEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DomainEventsSubscriber implements ResetInterface
{
    /** @var array<EntityWithEvents> */
    private array $entities = [];

    /** @var array<DeleteDomainEvent> */
    private array $deleteEvents = [];

    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    public function reset(): void
    {
        $this->entities = [];
        $this->deleteEvents = [];
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
        $this->collectDeleteEvents($eventArgs);
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

    private function collectDeleteEvents(PostRemoveEventArgs $eventArgs): void
    {
        $entity = $eventArgs->getObject();
        $reflection = new ReflectionClass($entity);
        $attributes = $reflection->getAttributes(HasDeleteDomainEvent::class);

        if (count($attributes) === 0) {
            return;
        }

        $deleteEventAttribute = $attributes[0]->newInstance();

        /** @var class-string<DeleteDomainEvent> $eventClass */
        $eventClass = $deleteEventAttribute->eventClass;

        $this->deleteEvents[] = $eventClass::fromEntity($entity);
    }

    private function dispatchEvents(): void
    {
        $entities = $this->entities;
        $this->entities = [];

        $deleteEvents = $this->deleteEvents;
        $this->deleteEvents = [];

        foreach ($entities as $entity) {
            foreach ($entity->popEvents() as $event) {
                $this->messageBus->dispatch($event);
            }
        }

        foreach ($deleteEvents as $event) {
            $this->messageBus->dispatch($event);
        }
    }
}
