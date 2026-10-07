<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Results\PageSectionOwnerOverview;
use SpeedPuzzling\Web\Value\PageSectionOwner;

readonly final class GetPageSectionOwner
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws CompetitionSeriesNotFound
     */
    public function of(PageSectionOwner $owner): PageSectionOwnerOverview
    {
        $table = $owner->isSeries() ? 'competition_series' : 'competition';

        $ownerColumn = $owner->isSeries() ? 'series_id' : 'competition_id';

        /** @var false|array{name: string, is_online: bool, sections_count: int|string} $row */
        $row = $this->database
            ->executeQuery(
                "SELECT name, is_online, (SELECT COUNT(*) FROM competition_page_section WHERE {$ownerColumn} = :id) AS sections_count FROM {$table} WHERE id = :id",
                ['id' => $owner->id()],
            )
            ->fetchAssociative();

        if ($row === false) {
            throw $owner->isSeries() ? new CompetitionSeriesNotFound() : new CompetitionNotFound();
        }

        return new PageSectionOwnerOverview(name: $row['name'], isOnline: $row['is_online'], sectionsCount: (int) $row['sections_count']);
    }
}
