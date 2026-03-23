<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

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
        $results = $this->getFeatureRequests->allSortedByVotes();

        self::assertNotEmpty($results);

        // First should be the popular one (5 votes), second the new one (1 vote)
        $ids = array_map(static fn($r) => $r->id, $results);
        $popularIndex = array_search(FeatureRequestFixture::FEATURE_REQUEST_POPULAR, $ids, true);
        $newIndex = array_search(FeatureRequestFixture::FEATURE_REQUEST_NEW, $ids, true);

        self::assertNotFalse($popularIndex);
        self::assertNotFalse($newIndex);
        self::assertLessThan($newIndex, $popularIndex, 'Popular request should come before new request');
    }
}
