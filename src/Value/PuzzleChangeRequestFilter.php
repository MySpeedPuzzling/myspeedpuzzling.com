<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\HttpFoundation\Request;

/**
 * The change request queue's filters (docs/features/puzzle-approvals.md "Change requests"): who filed it and one of
 * its tags - `?source=image_pipeline&tag=artwork_crop`, next to the status tabs. A value the queue does not know is
 * ignored (the filter is off), never an error page.
 */
readonly final class PuzzleChangeRequestFilter
{
    // The same rule as the internal API's tags (SubmitPuzzleChangeRequestController)
    private const string TAG_PATTERN = '/^[A-Za-z0-9_.:-]{1,50}$/';

    public function __construct(
        public null|PuzzleChangeRequestSource $source = null,
        public null|string $tag = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $source = $request->query->get('source');
        $tag = $request->query->get('tag');

        return new self(
            source: is_string($source) ? PuzzleChangeRequestSource::tryFrom($source) : null,
            tag: is_string($tag) && preg_match(self::TAG_PATTERN, $tag) === 1 ? $tag : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->source === null && $this->tag === null;
    }

    /**
     * The query parameters that keep the filter on a link (the tabs, paging).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'source' => $this->source?->value,
            'tag' => $this->tag,
        ], static fn (null|string $value): bool => $value !== null);
    }
}
