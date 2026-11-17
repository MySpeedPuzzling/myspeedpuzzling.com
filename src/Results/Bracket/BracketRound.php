<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Bracket;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\OfficialResultsRound;
use SpeedPuzzling\Web\Results\OfficialRoundEntrants;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Value\BracketRoundStatus;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * One round's box in the bracket (competition toolkit A9, A-round-formats.md §3.5): its name, start, status and - on
 * the public page, only once its results are published - the entrants it shows: the qualified ones (each with the
 * mark), or the top 3 of a round that qualified nobody (a final). Entrants come from OfficialEntrantsView only, so
 * players hidden from the viewer are left out and private ones keep the organiser's name.
 */
readonly final class BracketRound
{
    // A box lists at most this many entrants - the round's page has them all
    public const int MAX_SHOWN = 32;
    public const int TOP_SHOWN = 3;

    /**
     * @param list<PublishedRoundEntry> $shownEntries
     */
    public function __construct(
        public string $id,
        public string $name,
        public RoundCategory $category,
        public DateTimeImmutable $startsAt,
        public bool $startTimeKnown,
        public string $timezone,
        public BracketRoundStatus $status,
        public null|string $slug,
        // The round's public results page - only while its results are published and it has an address
        public null|string $url = null,
        // The organiser's bracket only: entries, results and qualified marks so far (published or not)
        public null|RoundResultsOverview $progress = null,
        public array $shownEntries = [],
        // The shown entries are the top of the round (it qualified nobody), not its qualified ones
        public bool $showsTop = false,
        // How many qualified / ranked entries the viewer may see - "+ 4 more" when a box cuts its list
        public int $visibleCount = 0,
    ) {
    }

    /**
     * The organiser's bracket (the advancement steps page): the round with its results progress, published or not.
     */
    public static function forOrganiser(OfficialResultsRound $round, DateTimeImmutable $now): self
    {
        $overview = $round->overview;
        $published = $overview->resultsPublishedAt !== null;

        return new self(
            id: strtolower($overview->roundId),
            name: $overview->name,
            category: RoundCategory::from($overview->category),
            startsAt: $overview->startsAt,
            startTimeKnown: true,
            timezone: $round->timezone,
            status: BracketRoundStatus::of($published, $overview->stopwatchStatus, $overview->stopwatchStartedAt, $overview->startsAt, $now),
            slug: $overview->slug,
            url: $published ? $round->publicUrl : null,
            progress: $overview,
        );
    }

    public function isPublished(): bool
    {
        return $this->status === BracketRoundStatus::Published;
    }

    /**
     * The entrants a published round's box shows. Called with the round's OfficialEntrantsView answer only - an
     * unpublished round comes empty from it anyway.
     */
    public function withEntrants(OfficialRoundEntrants $entrants): self
    {
        if ($this->isPublished() === false) {
            return $this;
        }

        $qualified = array_values(array_filter($entrants->entries, static fn (PublishedRoundEntry $entry): bool => $entry->qualified));
        $showsTop = $qualified === [];
        $list = $showsTop
            ? array_values(array_filter($entrants->entries, static fn (PublishedRoundEntry $entry): bool => $entry->rank <= self::TOP_SHOWN))
            : $qualified;

        return clone($this, [
            'shownEntries' => array_slice($list, 0, self::MAX_SHOWN),
            'showsTop' => $showsTop,
            'visibleCount' => count($list),
        ]);
    }

    public function withUrl(null|string $url): self
    {
        return clone($this, ['url' => $url]);
    }

    public function hiddenCount(): int
    {
        return max(0, $this->visibleCount - count($this->shownEntries));
    }
}
