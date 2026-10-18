<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\BoxTextFacts;
use SpeedPuzzling\Web\Value\BoxTextCatalogue;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * Every puzzle's current `box_text` analysis (of the picture it has now) with its record, a page at a time in puzzle id
 * order - the input of the box-text mismatch detection (docs/features/puzzle-approvals.md, "Box-text mismatches").
 * Secret puzzles (hide_until / hide_image_until ahead) are left out.
 */
readonly final class GetBoxTextFacts
{
    public const int PAGE_SIZE = 1000;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<BoxTextFacts>
     */
    public function page(null|string $after, int $limit = self::PAGE_SIZE): array
    {
        $fingerprint = PuzzleImageFingerprint::sql('p.image');
        $parameters = [
            'analyzer' => ImageAnalyzer::BoxText->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => max(1, $limit),
        ];
        $afterCondition = '';

        if ($after !== null) {
            $afterCondition = 'AND a.puzzle_id > :after';
            $parameters['after'] = strtolower($after);
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    a.puzzle_id,
    a.result,
    a.received_at,
    p.pieces_count,
    p.ean,
    p.identification_number,
    m.name AS manufacturer_name
FROM puzzle_image_analysis a
JOIN puzzle p ON p.id = a.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE a.analyzer = :analyzer
    AND a.image_fingerprint = {$fingerprint}
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$afterCondition}
ORDER BY a.puzzle_id
LIMIT :limit
SQL,
            $parameters,
        );

        $facts = [];

        foreach ($rows as $row) {
            assert(is_string($row['puzzle_id']));
            assert(is_string($row['result']));
            assert(is_string($row['received_at']));
            assert(is_int($row['pieces_count']));

            $boxText = json_decode($row['result'], true, flags: JSON_THROW_ON_ERROR);

            $facts[] = new BoxTextFacts(
                puzzleId: $row['puzzle_id'],
                boxText: is_array($boxText) ? $boxText : [],
                catalogue: new BoxTextCatalogue(
                    piecesCount: $row['pieces_count'],
                    ean: is_string($row['ean']) ? $row['ean'] : null,
                    brandCodes: is_string($row['identification_number']) ? $row['identification_number'] : null,
                    brandName: is_string($row['manufacturer_name']) ? $row['manufacturer_name'] : null,
                ),
                analysisReceivedAt: new DateTimeImmutable($row['received_at']),
            );
        }

        return $facts;
    }
}
