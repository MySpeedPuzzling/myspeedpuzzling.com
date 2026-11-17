<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayLimitReached;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayPresets;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A new display follows the current round and starts from the chosen preset, normalised by the sanitizer
 * (D-displays-overlays.md §3.3, §6); a preset with live results starts with uses_live_results on.
 */
#[AsMessageHandler]
readonly final class AddCompetitionDisplayHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionDisplayRepository $displayRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private DisplayPresets $presets,
    ) {
    }

    /**
     * @throws CompetitionDisplayLimitReached
     */
    public function __invoke(AddCompetitionDisplay $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        // Counted under the event's displays lock (AddCompetitionDisplay is SerializedByLock) - never one over the cap
        if ($this->displayRepository->countOf($competition) >= CompetitionDisplay::MAX_PER_COMPETITION) {
            throw new CompetitionDisplayLimitReached();
        }

        $now = $this->clock->now();
        // InvalidArgumentException for a preset of the other kind (the form refuses it first)
        $design = $this->presets->design($message->presetKey, $message->kind, $competition->id->toString());

        $this->displayRepository->save(new CompetitionDisplay(
            id: $message->displayId,
            competition: $competition,
            kind: $message->kind,
            name: CompetitionDisplay::cleanName($message->name),
            token: OnlineToken::generate(),
            locale: CompetitionDisplay::checkLocale($message->locale),
            design: $design,
            createdAt: $now,
            createdBy: $message->actorPlayerId !== null ? $this->playerRepository->get($message->actorPlayerId) : null,
            updatedAt: $now,
            usesLiveResults: DisplayDesignSanitizer::usesLiveResults($design),
            usesResults: DisplayDesignSanitizer::usesResults($design),
        ));
    }
}
