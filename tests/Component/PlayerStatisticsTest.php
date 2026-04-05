<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use DateTimeImmutable;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerStatisticsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testFirstTryToggleChangesData(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('PlayerStatistics', [
            'playerId' => PlayerFixture::PLAYER_REGULAR,
            'dateFrom' => new DateTimeImmutable('-60 days'),
            'dateTo' => new DateTimeImmutable('+1 day'),
            'streakOptedOut' => false,
        ], $client);

        $testComponent->setRouteLocale('en');

        // Initial render - should show statistics and toggle
        $rendered = $testComponent->render();
        $html = $rendered->toString();
        $this->assertStringContainsString('only-first-attempt', $html);

        // Toggle first try filter
        $testComponent->set('onlyFirstTries', true);
        $response = $testComponent->response();
        $this->assertSame(200, $response->getStatusCode());

        $rendered = $testComponent->render();
        $filteredHtml = $rendered->toString();

        // The checkbox should now be checked
        $this->assertStringContainsString('checked', $filteredHtml);
    }
}
