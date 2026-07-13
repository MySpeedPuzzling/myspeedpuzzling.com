<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Post-backfill calibration check (§1.3 / P7.T2). The two hard invariants on
 * PRODUCTION data (base ≈ 7,004 players with solves):
 *
 *   1. ≈115 players at Level 50 (±10, i.e. ~1.6% instant-max)
 *   2. the median player sits around Level 13–14
 *
 * Additionally the rank-115 XP total should land around 3,190+. Dev fixtures just
 * need to look sane (a handful of low-level players). Read-only — safe anywhere.
 */
#[AsCommand(
    name: 'myspeedpuzzling:xp-distribution',
    description: 'Print the level pyramid, instant-max count and top-20 XP totals for calibration verification.',
)]
final class XpDistributionConsoleCommand extends Command
{
    public function __construct(
        readonly private Connection $database,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<array{level: int, players: int|string}> $pyramid */
        $pyramid = $this->database->fetchAllAssociative(
            'SELECT level, COUNT(*) AS players FROM player WHERE xp_total > 0 GROUP BY level ORDER BY level DESC',
        );

        if ($pyramid === []) {
            $io->warning('No players with XP found — run myspeedpuzzling:xp-backfill first.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($pyramid as $row) {
            $total += (int) $row['players'];
        }

        $io->title('Level pyramid');
        $rows = [];
        foreach ($pyramid as $row) {
            $players = (int) $row['players'];
            $rows[] = [
                'Lv ' . $row['level'],
                $players,
                sprintf('%.1f%%', $players / $total * 100),
                str_repeat('█', max(1, (int) round($players / $total * 60))),
            ];
        }
        $io->table(['Level', 'Players', 'Share', ''], $rows);

        $maxLevelCount = 0;
        foreach ($pyramid as $row) {
            if ($row['level'] >= LevelTable::MAX_LEVEL) {
                $maxLevelCount += (int) $row['players'];
            }
        }

        $median = $this->database->fetchOne(
            'SELECT PERCENTILE_DISC(0.5) WITHIN GROUP (ORDER BY level) FROM player WHERE xp_total > 0',
        );

        $io->section('Calibration invariants (production expectations)');
        $io->listing([
            sprintf('Players at Level 50: %d — expected ≈115 (±10) on production data', $maxLevelCount),
            sprintf('Median level: %s — expected around 13–14 on production data', is_numeric($median) ? (string) (int) $median : 'n/a'),
            sprintf('Players with XP: %d', $total),
        ]);

        /** @var list<array{name: null|string, code: string, xp_total: int, level: int}> $top */
        $top = $this->database->fetchAllAssociative(
            'SELECT name, code, xp_total, level FROM player WHERE xp_total > 0 ORDER BY xp_total DESC LIMIT 20',
        );

        $io->section('Top 20 XP totals (rank-115 total should be ≈3,190+ on production)');
        $io->table(
            ['#', 'Player', 'XP', 'Level'],
            array_map(
                static fn (array $row, int $index): array => [
                    $index + 1,
                    $row['name'] ?? ('#' . strtoupper($row['code'])),
                    $row['xp_total'],
                    $row['level'],
                ],
                $top,
                array_keys($top),
            ),
        );

        return self::SUCCESS;
    }
}
