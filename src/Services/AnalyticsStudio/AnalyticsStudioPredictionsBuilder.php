<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use stdClass;

/**
 * The analytics studio's `predictions` answer (analytics-studio.md R3): the time predictions of the compare set - at
 * most MAX_ENTRIES entries, each member through AnalyticsStudioPredictions (solo predictions, the shown model, cached per
 * player and visible puzzle set). Entries not of the round are left out. Statements: the round, its visible puzzles and
 * the asked entries (3), then GetPlayerPredictions' own per member without a cached prediction.
 */
readonly final class AnalyticsStudioPredictionsBuilder
{
    public const int MAX_ENTRIES = 4;

    public function __construct(
        private AnalyticsStudioContextLoader $contextLoader,
        private GetAnalyticsStudioEntrants $getAnalyticsStudioEntrants,
        private AnalyticsStudioPredictions $predictions,
    ) {
    }

    /**
     * @param list<string> $entryKeys
     * @return array<string, mixed>
     * @throws AnalyticsStudioNotFound
     */
    public function build(string $roundId, array $entryKeys): array
    {
        $context = $this->contextLoader->load($roundId);
        $wanted = array_slice(array_values(array_unique(array_map('strtolower', $entryKeys))), 0, self::MAX_ENTRIES);
        $entrants = [];

        if ($wanted !== []) {
            foreach ($this->getAnalyticsStudioEntrants->forEntries($context->round, $wanted) as $entrant) {
                $members = [];
                foreach ($entrant->members as $member) {
                    $members[] = $this->predictions->memberJson($member, $context->visible);
                }

                $entrants[$entrant->key()] = ['members' => $members];
            }
        }

        return [
            'visibleHash' => $context->visibleHash,
            'entrants' => $entrants === [] ? new stdClass() : $entrants,
        ];
    }
}
