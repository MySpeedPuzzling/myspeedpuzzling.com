<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventDetail;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Readable paths for the builders' unit tests: /events/<slug>, /series/<series>/<edition>, /<route>/<values…>?<query>
 */
final class PathUrlGenerator implements UrlGeneratorInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $values = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $parameters);

        return match ($name) {
            'event_detail' => '/events/' . ($values['slug'] ?? ''),
            'edition_detail' => '/series/' . ($values['seriesSlug'] ?? '') . '/' . ($values['editionSlug'] ?? ''),
            'competition_series_detail' => '/series/' . ($values['slug'] ?? ''),
            'event_round_results' => '/events/' . ($values['slug'] ?? '') . '/results/' . ($values['roundSlug'] ?? ''),
            'edition_round_results' => '/series/' . ($values['seriesSlug'] ?? '') . '/' . ($values['editionSlug'] ?? '') . '/results/' . ($values['roundSlug'] ?? ''),
            'puzzle_add' => '/puzzle-add' . (isset($values['puzzleId']) ? '/' . $values['puzzleId'] : '') . '?competition=' . ($values['competition'] ?? ''),
            default => '/' . $name . '/' . implode('/', $values),
        };
    }

    public function setContext(RequestContext $context): void
    {
    }

    public function getContext(): RequestContext
    {
        return new RequestContext();
    }
}
