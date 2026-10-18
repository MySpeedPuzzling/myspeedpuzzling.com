<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Results\ScannedEanLookup;
use SpeedPuzzling\Web\Value\EanParityMisread;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The lookup behind the camera scanners (add form, multiscan, "Report duplicate"): SearchPuzzle::allByEan(), and when
 * that finds nothing for a code starting with 0, the code it was most likely misread from (EanParityMisread,
 * docs/features/barcode-scanner/README.md §3.3) - only when exactly one of the possible originals is in the catalogue.
 * Measured on the production catalogue (2026-10-18): all 61,415 possible misreads of its codes lead back to the right
 * code, none to a wrong one, and none of its 6,558 genuine codes starting with 0 would be taken for another one if it
 * were new (the two that would are themselves stored misreads). API v1 keeps the exact lookup.
 */
readonly final class FindPuzzlesByScannedEan
{
    public function __construct(
        private SearchPuzzle $searchPuzzle,
        private Connection $database,
        #[Autowire(service: 'monolog.logger.barcode_scanner')]
        private LoggerInterface $logger,
    ) {
    }

    public function byEan(string $ean): ScannedEanLookup
    {
        $puzzles = $this->searchPuzzle->allByEan($ean);

        if ($puzzles !== []) {
            return new ScannedEanLookup($puzzles);
        }

        $original = $this->theOnlyOriginalInCatalogue(EanParityMisread::possibleOriginals($ean));

        if ($original === null) {
            return new ScannedEanLookup([]);
        }

        // Hidden puzzles count above (a secret puzzle's code is still the only original) but are never returned here
        $puzzles = $this->searchPuzzle->allByEan($original);

        if ($puzzles !== []) {
            $this->logger->info('Scanned barcode corrected from a misread', [
                'scanned' => $ean,
                'corrected' => $original,
            ]);
        }

        return new ScannedEanLookup($puzzles, $puzzles !== [] ? $original : null);
    }

    /**
     * @param list<string> $candidates 13-digit codes
     */
    private function theOnlyOriginalInCatalogue(array $candidates): null|string
    {
        if ($candidates === []) {
            return null;
        }

        // The barcode lookup's search key (PuzzleTextSearch::barcodeCondition()): one "e:" line per EAN without leading zeros
        $lines = [];
        $conditions = [];
        $params = [];

        foreach ($candidates as $index => $candidate) {
            $digits = ltrim($candidate, '0');
            // A list, not a map: a numeric string key would turn into an int
            $lines[] = [$candidate, "\ne:" . $digits . "\n"];
            $conditions[] = "(puzzle.search_codes LIKE :end{$index} AND strpos(puzzle.search_codes, :line{$index}) > 0)";
            $params["end{$index}"] = '%' . $digits . "\n%";
            $params["line{$index}"] = "\ne:" . $digits . "\n";
        }

        $where = implode(' OR ', $conditions);

        /** @var list<string> $keys */
        $keys = $this->database
            ->executeQuery("SELECT puzzle.search_codes FROM puzzle WHERE {$where}", $params)
            ->fetchFirstColumn();

        $found = [];

        foreach ($lines as [$candidate, $line]) {
            foreach ($keys as $key) {
                if (str_contains($key, $line)) {
                    $found[] = $candidate;
                    break;
                }
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
