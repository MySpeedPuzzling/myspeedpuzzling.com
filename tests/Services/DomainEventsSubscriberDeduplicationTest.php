<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EntityWithEvents;
use SpeedPuzzling\Web\Entity\HasEvents;
use SpeedPuzzling\Web\Events\CompetitionRoundsChanged;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\SeriesEditionsChanged;
use SpeedPuzzling\Web\Services\DomainEventsSubscriber;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * P4 (docs/features/events-page/high-frequency-series.md): an idempotent event about the same thing goes out once per
 * flush - "Add several dates" creates up to 24 editions of one series in one flush, one reconcile is enough. Every
 * other event goes out as recorded.
 */
final class DomainEventsSubscriberDeduplicationTest extends TestCase
{
    public function testADeduplicatedEventIsDispatchedOncePerKeyPerFlush(): void
    {
        $bus = new class () implements MessageBusInterface {
            /** @var list<string> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = match (true) {
                    $message instanceof SeriesEditionsChanged => $message::class . ' ' . $message->seriesId->toString(),
                    $message instanceof CompetitionRoundsChanged => $message::class . ' ' . $message->competitionId->toString(),
                    default => $message::class,
                };

                return new Envelope($message);
            }
        };
        $subscriber = new DomainEventsSubscriber($bus);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $series = Uuid::uuid7();
        $otherSeries = Uuid::uuid7();
        $competition = Uuid::uuid7();
        $puzzle = Uuid::uuid7();

        $first = self::entity(new SeriesEditionsChanged($series), new CompetitionRoundsChanged($competition), new PuzzleSolved(Uuid::uuid7(), $puzzle));
        $second = self::entity(new SeriesEditionsChanged($series), new CompetitionRoundsChanged($competition), new PuzzleSolved(Uuid::uuid7(), $puzzle));
        $third = self::entity(new SeriesEditionsChanged(Uuid::fromString($series->toString())), new SeriesEditionsChanged($otherSeries));

        $subscriber->postPersist(new PostPersistEventArgs($first, $entityManager));
        $subscriber->postPersist(new PostPersistEventArgs($second, $entityManager));
        $subscriber->postUpdate(new PostUpdateEventArgs($third, $entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($entityManager));

        self::assertSame(
            [
                SeriesEditionsChanged::class . ' ' . $series->toString(),
                CompetitionRoundsChanged::class . ' ' . $competition->toString(),
                PuzzleSolved::class,
                PuzzleSolved::class,
                SeriesEditionsChanged::class . ' ' . $otherSeries->toString(),
            ],
            $bus->dispatched,
        );

        // The next flush is a new one
        $bus->dispatched = [];
        $subscriber->postPersist(new PostPersistEventArgs(self::entity(new SeriesEditionsChanged($series)), $entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($entityManager));

        self::assertSame([SeriesEditionsChanged::class . ' ' . $series->toString()], $bus->dispatched);
    }

    private static function entity(object ...$events): EntityWithEvents
    {
        $entity = new class () implements EntityWithEvents {
            use HasEvents;
        };

        foreach ($events as $event) {
            $entity->recordThat($event);
        }

        return $entity;
    }
}
