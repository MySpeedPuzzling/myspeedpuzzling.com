<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetFeatureRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFeatureRequestsTest extends KernelTestCase
{
    private GetFeatureRequests $getFeatureRequests;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getFeatureRequests = self::getContainer()->get(GetFeatureRequests::class);
    }

    public function testReturnsSortedByVoteCount(): void
    {
        $results = $this->getFeatureRequests->findAll();

        self::assertNotEmpty($results);

        // First should be the popular one (5 votes), second the new one (1 vote)
        $ids = array_map(static fn($r) => $r->id, $results);
        $popularIndex = array_search(FeatureRequestFixture::FEATURE_REQUEST_POPULAR, $ids, true);
        $newIndex = array_search(FeatureRequestFixture::FEATURE_REQUEST_NEW, $ids, true);

        self::assertNotFalse($popularIndex);
        self::assertNotFalse($newIndex);
        self::assertLessThan($newIndex, $popularIndex, 'Popular request should come before new request');
    }

    public function testRecentlyCompletedPutsTheLatestCompletionFirst(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE feature_request SET status = 'completed', completed_at = :at WHERE id = :id",
            ['at' => '2026-10-01 10:00:00', 'id' => FeatureRequestFixture::FEATURE_REQUEST_POPULAR],
        );
        $connection->executeStatement(
            "UPDATE feature_request SET status = 'completed', completed_at = :at WHERE id = :id",
            ['at' => '2026-10-15 10:00:00', 'id' => FeatureRequestFixture::FEATURE_REQUEST_NEW],
        );

        $results = $this->getFeatureRequests->findAll(sort: 'recently_completed');

        self::assertSame(FeatureRequestFixture::FEATURE_REQUEST_NEW, $results[0]->id);
        self::assertSame(FeatureRequestFixture::FEATURE_REQUEST_POPULAR, $results[1]->id);
        self::assertNotNull($results[0]->completedAt);
    }
}
