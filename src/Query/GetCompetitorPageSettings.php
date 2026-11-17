<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\QrTagMode;

/**
 * A competition's competitor pages & QR settings (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §2) - for the toolkit hub's settings card.
 */
readonly final class GetCompetitorPageSettings
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function of(string $competitionId): CompetitorPageSettings
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        /** @var false|array{competitor_pages: bool, competitor_pages_live_results: bool, time_proposals: bool, qr_tag_mode: null|string} $row */
        $row = $this->database->executeQuery(
            'SELECT competitor_pages, competitor_pages_live_results, time_proposals, qr_tag_mode FROM competition WHERE id = :id',
            ['id' => $competitionId],
        )->fetchAssociative();

        if ($row === false) {
            throw new CompetitionNotFound();
        }

        return new CompetitorPageSettings(
            competitorPages: $row['competitor_pages'],
            liveResultsBeforePublishing: $row['competitor_pages_live_results'],
            timeProposals: $row['time_proposals'],
            qrTagMode: $row['qr_tag_mode'] !== null ? QrTagMode::tryFrom($row['qr_tag_mode']) : null,
        );
    }
}
