<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Readable round slug, unique within its competition ("individual-a", "teams-final"). Collisions get a
 * -2/-3/... suffix, the same policy as manufacturer slugs. The caller passes the slugs already taken in
 * the competition, so a batch (the backfill) stays unique before anything is flushed.
 */
readonly final class CompetitionRoundSlugGenerator
{
    public function __construct(
        private SluggerInterface $slugger,
    ) {
    }

    /**
     * @param array<string> $takenSlugs
     */
    public function generate(string $roundName, array $takenSlugs): string
    {
        $base = strtolower((string) $this->slugger->slug($roundName));

        if ($base === '') {
            $base = 'round';
        }

        $slug = $base;
        $suffix = 2;

        while (in_array($slug, $takenSlugs, true)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
