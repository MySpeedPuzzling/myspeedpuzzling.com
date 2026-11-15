<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Results\ImportFlowText;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One import on Imports home (docs/features/data-import/guided-flow.md §5.1): **one status** - a sentence, two where
 * the table of §5.1 has two - and **one button**. Worked out from what the home page has loaded for all imports at
 * once (the import, and GetImportAddScreen::addedByImports()), in the order "Where you are" (ImportFlow) takes its
 * primary action in - so the card and the import's own page never say two different things about what is next.
 *
 * Pure: it reads nothing and stores nothing. "Continue" always leads to "Where you are" (§9); only what has one
 * obvious place of its own goes straight there (an "Add" that runs, the rows that wait for an answer, the check
 * before the Add, what was added).
 *
 * A value worked out per page view, not a service: it is built by ImportHomeCard::of().
 */
#[Exclude]
readonly final class ImportHomeCard
{
    /** From so many days before the file is removed the card says until when it is kept (§9) */
    public const int KEPT_UNTIL_SHOWN_DAYS = 7;

    /** The button sends the form that checks the upload again - it is no link */
    public const string ROUTE_FINISH_UPLOAD = 'data_import_upload_finish';

    /**
     * @param list<ImportFlowText> $lines the status, one sentence a line
     * @param array<string, string> $parameters of the route
     */
    private function __construct(
        public DataImportOverview $import,
        public array $lines,
        /** The key of the button's text */
        public ImportFlowText $button,
        public string $route,
        public array $parameters,
        /** A button that only opens the import - nothing waits for the player there */
        public bool $quiet,
        /** Finished, or nothing more can come of it: listed under "Finished" */
        public bool $finished,
        /** "The file is kept until …" - only in its last days */
        public null|DateTimeImmutable $keptUntil,
    ) {
    }

    /**
     * @param null|array{results: int, collectionItems: int, wishListItems: int, lastAddedAt: null|DateTimeImmutable, adding: bool, runningBatchId: null|string, resultsToday: int, ready: int, waiting: int, questions: int, look: int, toMatch: int, working: bool, tookRows: bool} $standing
     *        what GetImportAddScreen::addedByImports() says about the import - null: nothing
     * @param int $resultsToday the results all of the player's imports added today (the daily limit is the player's)
     */
    public static function of(DataImportOverview $import, null|array $standing, int $resultsToday, DateTimeImmutable $now): self
    {
        $id = ['importId' => $import->importId];
        $added = $standing === null ? 0 : $standing['results'] + $standing['collectionItems'] + $standing['wishListItems'];
        $addedLine = $standing === null || $added === 0
            ? null
            : ($standing['lastAddedAt'] === null
                ? new ImportFlowText('data_import.flow.add.added', $added)
                : new ImportFlowText('data_import.flow.add.added_on', $added, [], $standing['lastAddedAt']));
        $open = new ImportFlowText('data_import.after.home.open');
        $continue = new ImportFlowText('data_import.flow.continue');
        $keptUntil = $import->fileKeptUntil !== null
            && $import->status !== DataImportStatus::Done
            && $import->fileKeptUntil > $now
            && $import->fileKeptUntil <= $now->modify('+' . self::KEPT_UNTIL_SHOWN_DAYS . ' days')
                ? $import->fileKeptUntil
                : null;

        $card = static fn (array $lines, ImportFlowText $button, string $route, bool $quiet = false, bool $finished = false, array $parameters = []): self => self::card($import, $finished ? null : $keptUntil, $lines, $button, $route, $id + $parameters, $quiet, $finished);

        switch ($import->status) {
            case DataImportStatus::Uploading:
                return $card([new ImportFlowText('data_import.flow.file.upload_not_finished')], new ImportFlowText('data_import.after.home.finish_upload'), self::ROUTE_FINISH_UPLOAD);
            case DataImportStatus::FetchFailed:
            case DataImportStatus::ReadFailed:
                return $card([new ImportFlowText('data_import.flow.file.not_read')], new ImportFlowText('data_import.after.home.see_why'), 'data_import_detail');
            case DataImportStatus::Uploaded:
            case DataImportStatus::Fetching:
            case DataImportStatus::Reading:
                return $card([new ImportFlowText('data_import.flow.file.being_read')], $open, 'data_import_detail', quiet: true);
            case DataImportStatus::Done:
            case DataImportStatus::Discarded:
                return $addedLine === null
                    ? $card([new ImportFlowText('data_import.home.nothing_added_now')], $open, 'data_import_detail', quiet: true, finished: true)
                    : $card([$addedLine], new ImportFlowText('data_import.flow.primary.see_added'), 'data_import_added', quiet: true, finished: true);
            default:
                break;
        }

        if ($standing === null) {
            return $card([new ImportFlowText('data_import.flow.file.being_read')], $open, 'data_import_detail', quiet: true);
        }

        $readyLine = $standing['ready'] > 0 ? new ImportFlowText('data_import.after.home.ready', $standing['ready']) : null;

        if ($standing['runningBatchId'] !== null) {
            return $card([new ImportFlowText('data_import.flow.add.running')], new ImportFlowText('data_import.flow.primary.follow'), 'data_import_batch', parameters: ['batchId' => $standing['runningBatchId']]);
        }

        if ($standing['questions'] > 0 || $standing['look'] > 0) {
            return $card([
                $standing['questions'] > 0 ? new ImportFlowText('data_import.flow.check.questions', $standing['questions']) : null,
                $standing['look'] > 0 ? new ImportFlowText('data_import.flow.check.rows', $standing['look']) : null,
            ], $continue, 'data_import_detail');
        }

        if ($standing['toMatch'] > 0) {
            return $card([$readyLine, new ImportFlowText('data_import.after.home.to_match', $standing['toMatch'])], $continue, 'data_import_detail');
        }

        if ($standing['waiting'] > 0) {
            return $card(
                [$addedLine, new ImportFlowText('data_import.flow.add.rows_wait', $standing['waiting'])],
                new ImportFlowText('data_import.flow.primary.answer', $standing['waiting']),
                'data_import_not_added',
            );
        }

        if ($standing['ready'] > 0 && $resultsToday >= DataImportLimits::MAX_RESULTS_ADDED_PER_DAY) {
            // The daily limit is used up: the rest is ready tomorrow - nothing to press today
            return $card(
                [
                    $standing['resultsToday'] > 0 ? new ImportFlowText('data_import.after.home.added_today', $standing['resultsToday']) : $addedLine,
                    new ImportFlowText('data_import.after.home.tomorrow', $standing['ready']),
                ],
                $open,
                'data_import_detail',
                quiet: true,
            );
        }

        if ($standing['ready'] > 0) {
            return $card([$readyLine], new ImportFlowText('data_import.flow.primary.check_and_add'), 'data_import_add');
        }

        if ($standing['working']) {
            return $card([new ImportFlowText('data_import.flow.file.being_read')], $open, 'data_import_detail', quiet: true);
        }

        if ($addedLine !== null) {
            return $card([$addedLine], new ImportFlowText('data_import.flow.primary.see_added'), 'data_import_added', quiet: true);
        }

        return $card(
            // It took rows and nothing of them is on the profile: everything was undone
            [new ImportFlowText($standing['tookRows'] ? 'data_import.home.nothing_added_now' : 'data_import.flow.add.nothing')],
            $open,
            'data_import_detail',
            quiet: true,
        );
    }

    /**
     * @param array<mixed> $lines the sentences - a null among them is a line that has nothing to say
     * @param array<mixed> $parameters
     */
    private static function card(DataImportOverview $import, null|DateTimeImmutable $keptUntil, array $lines, ImportFlowText $button, string $route, array $parameters, bool $quiet, bool $finished): self
    {
        $routeParameters = [];

        foreach ($parameters as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $routeParameters[$name] = $value;
            }
        }

        return new self(
            $import,
            array_values(array_filter($lines, static fn (mixed $line): bool => $line instanceof ImportFlowText)),
            $button,
            $route,
            $routeParameters,
            $quiet,
            $finished,
            $keptUntil,
        );
    }
}
