<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DeleteCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionSeriesHandler
{
    public function __construct(
        private CompetitionSeriesRepository $seriesRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(DeleteCompetitionSeries $message): void
    {
        $seriesId = $message->seriesId;
        $params = ['id' => $seriesId];

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = NULL
             WHERE competition_round_id IN (
                 SELECT cr.id FROM competition_round cr
                 INNER JOIN competition c ON c.id = cr.competition_id
                 WHERE c.series_id = :id
             )',
            $params,
        );
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = NULL
             WHERE competition_id IN (SELECT id FROM competition WHERE series_id = :id)',
            $params,
        );

        $this->database->executeStatement(
            'DELETE FROM competition_participant_round
             WHERE participant_id IN (
                 SELECT cp.id FROM competition_participant cp
                 INNER JOIN competition c ON c.id = cp.competition_id
                 WHERE c.series_id = :id
             )
                OR round_id IN (
                    SELECT cr.id FROM competition_round cr
                    INNER JOIN competition c ON c.id = cr.competition_id
                    WHERE c.series_id = :id
                )',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_team
             WHERE round_id IN (
                 SELECT cr.id FROM competition_round cr
                 INNER JOIN competition c ON c.id = cr.competition_id
                 WHERE c.series_id = :id
             )',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_participant
             WHERE competition_id IN (SELECT id FROM competition WHERE series_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_round_puzzle
             WHERE round_id IN (
                 SELECT cr.id FROM competition_round cr
                 INNER JOIN competition c ON c.id = cr.competition_id
                 WHERE c.series_id = :id
             )',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_round
             WHERE competition_id IN (SELECT id FROM competition WHERE series_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_maintainer
             WHERE competition_id IN (SELECT id FROM competition WHERE series_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition WHERE series_id = :id',
            $params,
        );

        $series = $this->seriesRepository->get($seriesId);
        $this->seriesRepository->delete($series);
    }
}
