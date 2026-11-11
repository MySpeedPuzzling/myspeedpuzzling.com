<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\MostActiveSoloPlayers;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The Hub's "Most active" panel reads its list twice (rows + "Show more"), the list query runs once per render.
 */
final class MostActiveSoloPlayersTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    public function testTheMonthListIsQueriedOncePerRender(): void
    {
        $client = self::createClient();
        $component = $this->mostActive($client);
        $component->render();

        $this->startCountingQueries($client);
        $component->refresh()->render();

        self::assertCount(1, $this->monthQueries($client));
        self::assertCount(0, $this->allTimeQueries($client));
    }

    public function testSwitchingTheTabQueriesTheNewTabsList(): void
    {
        $client = self::createClient();
        $component = $this->mostActive($client);
        $component->render();

        $this->startCountingQueries($client);
        $component->call('changeTimespan', ['timespan' => 'all_time'])->render();

        $mostActive = $component->component();
        self::assertInstanceOf(MostActiveSoloPlayers::class, $mostActive);
        self::assertSame('all_time', $mostActive->timespan);
        self::assertCount(1, $this->allTimeQueries($client));
        self::assertCount(0, $this->monthQueries($client));
    }

    private function mostActive(KernelBrowser $client): TestLiveComponent
    {
        $component = $this->createLiveComponent('MostActiveSoloPlayers', ['showLimit' => 4], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * @return list<string>
     */
    private function monthQueries(KernelBrowser $client): array
    {
        return array_values(array_filter(
            $this->executedSql($client),
            // GetMostActivePlayers::mostActiveSoloPlayersInMonth()
            static fn (string $sql): bool => str_contains($sql, 'solved_puzzles_count')
                && str_contains($sql, 'finished_at >= '),
        ));
    }

    /**
     * @return list<string>
     */
    private function allTimeQueries(KernelBrowser $client): array
    {
        return array_values(array_filter(
            $this->executedSql($client),
            // GetMostActivePlayers::mostActiveSoloPlayers()
            static fn (string $sql): bool => str_contains($sql, 'solved_puzzles_count')
                && !str_contains($sql, 'finished_at >= '),
        ));
    }
}
