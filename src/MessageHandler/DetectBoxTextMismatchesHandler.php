<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DetectBoxTextMismatches;
use SpeedPuzzling\Web\Query\GetBoxTextFacts;
use SpeedPuzzling\Web\Results\BoxTextDetectionOutcome;
use SpeedPuzzling\Web\Services\BoxText\BoxTextMismatchDetector;
use SpeedPuzzling\Web\Services\BoxText\BoxTextMismatchWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DetectBoxTextMismatchesHandler
{
    public function __construct(
        private GetBoxTextFacts $getBoxTextFacts,
        private BoxTextMismatchWriter $writer,
    ) {
    }

    public function __invoke(DetectBoxTextMismatches $message): BoxTextDetectionOutcome
    {
        $mismatches = [];
        $checked = 0;
        $after = null;

        do {
            $page = $this->getBoxTextFacts->page($after);

            foreach ($page as $facts) {
                $checked++;
                $after = $facts->puzzleId;

                foreach (BoxTextMismatchDetector::detect($facts->puzzleId, $facts->boxText, $facts->catalogue) as $mismatch) {
                    $mismatches[] = ['mismatch' => $mismatch, 'analysisReceivedAt' => $facts->analysisReceivedAt];
                }
            }
        } while (count($page) === GetBoxTextFacts::PAGE_SIZE);

        $written = $this->writer->replaceAll($mismatches);

        return new BoxTextDetectionOutcome($checked, count($mismatches), $written['written'], $written['removed']);
    }
}
