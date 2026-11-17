<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayDesignConflict;
use SpeedPuzzling\Web\Exceptions\InvalidDisplayDesign;
use SpeedPuzzling\Web\Message\DeleteCompetitionDisplayImages;
use SpeedPuzzling\Web\Message\SaveCompetitionDisplayDesign;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Results\DisplayDesignError;
use SpeedPuzzling\Web\Results\SavedCompetitionDisplayDesign;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The editor's Save (D-displays-overlays.md §3.3): a stale base version is a conflict (409) before anything else; the
 * design is normalised by the sanitizer (structure errors = 422); a results element newly switched to live results
 * needs the organiser's confirmation (D §5.1 - 422 `live_not_confirmed` on its source); uses_results /
 * uses_live_results are derived from what is stored.
 *
 * Pictures that dropped out of the design are removed once the save committed (DeleteCompetitionDisplayImages - only
 * those no other display still shows).
 */
#[AsMessageHandler]
readonly final class SaveCompetitionDisplayDesignHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
        private DisplayDesignSanitizer $sanitizer,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws CompetitionDisplayNotFound
     * @throws DisplayDesignConflict
     * @throws InvalidDisplayDesign
     */
    public function __invoke(SaveCompetitionDisplayDesign $message): SavedCompetitionDisplayDesign
    {
        $display = $this->displayRepository->get($message->displayId);

        if ($message->baseVersion !== $display->designVersion) {
            throw new DisplayDesignConflict($display->designVersion);
        }

        $design = $this->sanitizer->normalise($message->design, $display->competition->id->toString());

        if ($message->liveConfirmed === false) {
            $wasLive = DisplayDesignSanitizer::liveElementIds($display->design);
            $unconfirmed = [];

            foreach (DisplayDesignSanitizer::liveElementIds($design) as $elementId => $index) {
                if (!isset($wasLive[$elementId])) {
                    $unconfirmed[] = new DisplayDesignError('/elements/' . $index . '/props/source', 'live_not_confirmed');
                }
            }

            if ($unconfirmed !== []) {
                throw new InvalidDisplayDesign($unconfirmed);
            }
        }

        $droppedImages = array_values(array_diff(DisplayDesignSanitizer::imagePaths($display->design), DisplayDesignSanitizer::imagePaths($design)));
        $usesResults = DisplayDesignSanitizer::usesResults($design);
        $usesLiveResults = DisplayDesignSanitizer::usesLiveResults($design);
        $changed = $display->saveDesign($design, $message->baseVersion, $usesLiveResults, $usesResults, $this->clock->now());

        if ($droppedImages !== []) {
            $this->messageBus->dispatch(new DeleteCompetitionDisplayImages($droppedImages), [new DispatchAfterCurrentBusStamp()]);
        }

        return new SavedCompetitionDisplayDesign(
            designVersion: $display->designVersion,
            design: $design,
            usesResults: $usesResults,
            usesLiveResults: $usesLiveResults,
            changed: $changed,
        );
    }
}
