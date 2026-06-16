<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ComparisonBucket;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ComparisonBucketTest extends TestCase
{
    private function makeBucket(): ComparisonBucket
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new ComparisonBucket($requestStack);
    }

    public function testAddAndListPlayersPreservingOrderAndDeduping(): void
    {
        $bucket = $this->makeBucket();
        $bucket->addPlayer('p1');
        $bucket->addPlayer('p2');
        $bucket->addPlayer('p1'); // duplicate ignored

        self::assertSame(2, $bucket->count());
        self::assertSame(['p1', 'p2'], $bucket->playerIds());
        self::assertTrue($bucket->hasPlayer('p1'));
        self::assertFalse($bucket->hasPlayer('p3'));
    }

    public function testRemovePlayer(): void
    {
        $bucket = $this->makeBucket();
        $bucket->addPlayer('p1');
        $bucket->addPlayer('p2');

        $bucket->removePlayer('p1');

        self::assertSame(['p2'], $bucket->playerIds());
        self::assertFalse($bucket->hasPlayer('p1'));
    }

    public function testCoSolversAreManagedPerSubject(): void
    {
        $bucket = $this->makeBucket();
        $bucket->addPlayer('p1');
        $bucket->addPlayer('p2');

        $bucket->addCoSolver('p1', 'c1');
        $bucket->addCoSolver('p1', 'c1'); // duplicate ignored
        $bucket->addCoSolver('p1', 'p1'); // self ignored

        $subjects = $bucket->getSubjects();
        self::assertSame('p1', $subjects[0]->playerId);
        self::assertSame(['c1'], $subjects[0]->coSolverIds);
        self::assertSame([], $subjects[1]->coSolverIds);

        $bucket->removeCoSolver('p1', 'c1');
        self::assertSame([], $bucket->getSubjects()[0]->coSolverIds);
    }

    public function testClear(): void
    {
        $bucket = $this->makeBucket();
        $bucket->addPlayer('p1');

        $bucket->clear();

        self::assertTrue($bucket->isEmpty());
        self::assertSame(0, $bucket->count());
    }

    public function testRespectsMaxSubjectsCap(): void
    {
        $bucket = $this->makeBucket();

        for ($i = 0; $i < ComparisonBucket::MAX_SUBJECTS + 5; $i++) {
            $bucket->addPlayer('player-' . $i);
        }

        self::assertSame(ComparisonBucket::MAX_SUBJECTS, $bucket->count());
    }

    public function testWorksWithoutASession(): void
    {
        // No request on the stack -> getSession() throws internally and the bucket stays empty.
        $bucket = new ComparisonBucket(new RequestStack());

        self::assertTrue($bucket->isEmpty());
        self::assertSame([], $bucket->getSubjects());

        $bucket->addPlayer('p1'); // must not throw
        self::assertSame(0, $bucket->count());
    }
}
