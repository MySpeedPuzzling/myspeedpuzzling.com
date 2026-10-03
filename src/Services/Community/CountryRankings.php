<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Community;

use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Results\CountryCupRow;
use SpeedPuzzling\Web\Results\CountryCupStandings;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\CountryCupMeasure;
use SpeedPuzzling\Web\Value\CountryCupPeriod;

/**
 * Orders the countries for the Players page's tiles and Country Cup (docs/features/players-page/README.md). Pure PHP
 * over the rows of GetCommunityScopeStats::countries(), so every order costs no statement of its own. Ties fall to the
 * bigger community, then the country code, so an order never flickers between requests.
 */
readonly final class CountryRankings
{
    public const int TILES = 12;
    public const int CUP_ROWS = 10;

    // Rising needs something to rise from - a jump from 1 solve to 5 is not a rising community
    public const int RISING_MINIMUM_PREVIOUS_SOLVES = 10;

    /**
     * @param list<CommunityScopeStatistics> $countries
     * @return list<CommunityScopeStatistics>
     */
    public function mostActive(array $countries, int $limit = self::TILES): array
    {
        usort($countries, static fn (CommunityScopeStatistics $a, CommunityScopeStatistics $b): int => [$b->active30d, $b->registeredPlayers, $a->scope->key()] <=> [$a->active30d, $a->registeredPlayers, $b->scope->key()]);

        return array_slice($countries, 0, $limit);
    }

    /**
     * @param list<CommunityScopeStatistics> $countries
     * @return list<CommunityScopeStatistics>
     */
    public function mostPuzzlers(array $countries, int $limit = self::TILES): array
    {
        usort($countries, static fn (CommunityScopeStatistics $a, CommunityScopeStatistics $b): int => [$b->registeredPlayers, $b->active30d, $a->scope->key()] <=> [$a->registeredPlayers, $a->active30d, $b->scope->key()]);

        return array_slice($countries, 0, $limit);
    }

    /**
     * Biggest change of solves in the last 30 days against the 30 before, only countries with at least
     * RISING_MINIMUM_PREVIOUS_SOLVES solves in the 30 days before.
     *
     * @param list<CommunityScopeStatistics> $countries
     * @return list<CommunityScopeStatistics>
     */
    public function rising(array $countries, int $limit = self::TILES): array
    {
        $rising = array_values(array_filter(
            $countries,
            static fn (CommunityScopeStatistics $statistics): bool => $statistics->solvesPrev30d >= self::RISING_MINIMUM_PREVIOUS_SOLVES,
        ));

        usort($rising, static fn (CommunityScopeStatistics $a, CommunityScopeStatistics $b): int => [(int) $b->solvesChangePercent(), $b->solves30d, $a->scope->key()] <=> [(int) $a->solvesChangePercent(), $a->solves30d, $b->scope->key()]);

        return array_slice($rising, 0, $limit);
    }

    /**
     * The leaders of one Cup board, plus the viewer's and the page scope's country with their real position when they
     * are not among them (not ranked at all = no position).
     *
     * @param list<CommunityScopeStatistics> $countries
     */
    public function cup(
        array $countries,
        CountryCupMeasure $measure,
        CountryCupPeriod $period,
        null|CountryCode $viewerCountry,
        null|CountryCode $scopeCountry,
        int $limit = self::CUP_ROWS,
    ): CountryCupStandings {
        $thisMonth = $period === CountryCupPeriod::ThisMonth;

        /** @var array<string, array{country: CountryCode, value: null|int, active: int}> $entries */
        $entries = [];

        foreach ($countries as $statistics) {
            $country = $statistics->country();

            if ($country === null) {
                continue;
            }

            $entries[$country->name] = [
                'country' => $country,
                'value' => match ($measure) {
                    CountryCupMeasure::PerActivePuzzler => $thisMonth ? $statistics->piecesPerActivePuzzlerThisMonth() : $statistics->piecesPerActivePuzzlerLastMonth(),
                    CountryCupMeasure::TotalPieces => $thisMonth ? $statistics->piecesThisMonth : $statistics->piecesLastMonth,
                },
                'active' => $thisMonth ? $statistics->activeThisMonth : $statistics->activeLastMonth,
            ];
        }

        $ranked = array_values(array_filter($entries, static fn (array $entry): bool => $entry['value'] !== null && $entry['value'] > 0));
        usort($ranked, static fn (array $a, array $b): int => [$b['value'], $b['active'], $a['country']->name] <=> [$a['value'], $a['active'], $b['country']->name]);

        $leaderValue = $ranked[0]['value'] ?? 0;

        /** @var array<string, int> $positions */
        $positions = [];
        foreach ($ranked as $index => $entry) {
            $positions[$entry['country']->name] = $index + 1;
        }

        $row = static function (array $entry) use ($positions, $leaderValue, $viewerCountry, $scopeCountry): CountryCupRow {
            /** @var array{country: CountryCode, value: null|int, active: int} $entry */
            $country = $entry['country'];

            return new CountryCupRow(
                country: $country,
                position: $positions[$country->name] ?? null,
                value: $entry['value'],
                active: $entry['active'],
                barPercent: $leaderValue > 0 && $entry['value'] !== null ? round($entry['value'] / $leaderValue * 100, 1) : 0.0,
                isViewerCountry: $country === $viewerCountry,
                isScopeCountry: $country === $scopeCountry,
            );
        };

        $top = array_map($row, array_slice($ranked, 0, $limit));

        $marked = [];
        foreach ([$viewerCountry, $scopeCountry] as $country) {
            if ($country === null || isset($marked[$country->name]) || !isset($entries[$country->name])) {
                continue;
            }

            $position = $positions[$country->name] ?? null;

            if ($position !== null && $position <= $limit) {
                continue;
            }

            $marked[$country->name] = $row($entries[$country->name]);
        }

        $marked = array_values($marked);
        usort($marked, static fn (CountryCupRow $a, CountryCupRow $b): int => ($a->position ?? PHP_INT_MAX) <=> ($b->position ?? PHP_INT_MAX));

        return new CountryCupStandings($measure, $period, $top, $marked);
    }
}
