<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the open participants spreadsheets of an event that its participants, round places, pairs/teams or rounds
 * changed - a private Mercure update on `/competition-participants/{competitionId}` (topic()), followed only by the sheet
 * page with the subscriber token its state carries (OfficialResultsSubscription::forParticipantsSheet()), never through
 * the subscribe cookie. docs/features/competitions-management/participants-spreadsheet.md.
 *
 * Payload: {"type": "participants_sheet.changed", "competitionId": "…", "version": "<GetParticipantsSheetVersion>"} -
 * a page whose known version differs fetches the state again. Results, table numbers and qualified marks travel on the
 * rounds' own topics (OfficialResultsLiveUpdates).
 *
 * Called by the controllers after the dispatch returned, i.e. after the commit. A Mercure failure never fails the
 * write: it is logged (warning) and the pages catch up on their next version check.
 */
final readonly class ParticipantsSheetLiveUpdates
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
        private GetParticipantsSheetVersion $getVersion,
        private AfterResponse $afterResponse,
    ) {
    }

    public static function topic(string $competitionId): string
    {
        return '/competition-participants/' . strtolower($competitionId);
    }

    /**
     * For a write made outside the sheet - a player's "I'm going", leaving, the online personal link
     * (docs/features/competitions-management/participant-linking.md §5.8): once the answer is sent (AfterResponse, the
     * dispatch returned = committed), the version is read and the open sheets are told. A version nothing changed is one
     * they know already - no refetch.
     */
    public function changedAfterResponse(string $competitionId): void
    {
        $this->afterResponse->defer(function () use ($competitionId): void {
            $this->changed($competitionId, $this->getVersion->ofCompetition($competitionId));
        });
    }

    public function changed(string $competitionId, string $version): void
    {
        try {
            $this->hub->publish(new Update(self::topic($competitionId), json_encode([
                'type' => 'participants_sheet.changed',
                'competitionId' => strtolower($competitionId),
                'version' => $version,
            ], JSON_THROW_ON_ERROR), private: true));
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish a participants sheet update', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);
        }
    }
}
