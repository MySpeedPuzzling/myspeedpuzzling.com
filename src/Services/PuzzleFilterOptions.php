<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Results\PuzzleTag;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Brand/tag option lists for the /puzzle search, cached in the app cache
 * (Redis): they are requested on every page view + by the filter-options
 * endpoint, but change rarely. Options are kept out of the initial HTML
 * (~420 kB of <option> tags) and fetched on first dropdown focus instead.
 */
final readonly class PuzzleFilterOptions
{
    public function __construct(
        private GetManufacturers $getManufacturers,
        private GetTags $getTags,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     manufacturers: list<array{value: string, text: string}>,
     *     tags: list<array{value: string, text: string}>,
     * }
     */
    public function all(): array
    {
        return $this->cache->get('puzzle_filter_options_v1', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return [
                'manufacturers' => array_values(array_map(
                    static fn (ManufacturerOverview $manufacturer): array => [
                        'value' => $manufacturer->manufacturerId,
                        'text' => "{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})",
                    ],
                    $this->getManufacturers->onlyApprovedOrAddedByPlayer(),
                )),
                'tags' => array_values(array_map(
                    static fn (PuzzleTag $tag): array => [
                        'value' => $tag->tagId,
                        'text' => $tag->name,
                    ],
                    $this->getTags->all(),
                )),
            ];
        });
    }

    public function manufacturerLabel(string $manufacturerId): null|string
    {
        return $this->findLabel($this->all()['manufacturers'], $manufacturerId);
    }

    public function tagLabel(string $tagId): null|string
    {
        return $this->findLabel($this->all()['tags'], $tagId);
    }

    /**
     * @param list<array{value: string, text: string}> $options
     */
    private function findLabel(array $options, string $value): null|string
    {
        foreach ($options as $option) {
            if ($option['value'] === $value) {
                return $option['text'];
            }
        }

        return null;
    }
}
