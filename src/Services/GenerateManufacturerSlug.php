<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Generates a unique, lowercase ascii slug for a manufacturer name.
 * Name collisions get a -2/-3/... suffix (same policy as the slug backfill
 * migration Version20260710230956).
 */
readonly final class GenerateManufacturerSlug
{
    public function __construct(
        private SluggerInterface $slugger,
        private ManufacturerRepository $manufacturerRepository,
    ) {
    }

    public function fromName(string $name): string
    {
        $base = strtolower((string) $this->slugger->slug($name));

        if ($base === '') {
            $base = 'brand-' . substr(md5($name . microtime()), 0, 8);
        }

        $slug = $base;
        $suffix = 2;

        while ($this->manufacturerRepository->slugExists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
