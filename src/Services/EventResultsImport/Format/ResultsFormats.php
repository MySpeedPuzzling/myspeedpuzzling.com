<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport\Format;

use SpeedPuzzling\Web\Exceptions\UnknownResultsFormat;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every results format by its key - a results source names its format by the key (event_results_sheet.format).
 * Stateless: the formats are pure services.
 */
readonly final class ResultsFormats
{
    /**
     * @param iterable<ResultsFormat> $formats
     */
    public function __construct(
        #[AutowireIterator(ResultsFormat::class)]
        private iterable $formats,
    ) {
    }

    /**
     * @throws UnknownResultsFormat
     */
    public function get(string $key): ResultsFormat
    {
        foreach ($this->formats as $format) {
            if ($format->key() === $key) {
                return $format;
            }
        }

        throw new UnknownResultsFormat(sprintf('No results format "%s" - known: %s.', $key, implode(', ', $this->keys())));
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->formats as $format) {
            $keys[] = $format->key();
        }

        return $keys;
    }

    /**
     * @return list<ResultsFormat>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->formats as $format) {
            $all[] = $format;
        }

        return $all;
    }
}
