<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Contracts\Service\ResetInterface;

/**
 * SearchText::fold() with a memory, for code that folds the same texts again and again: the events page folds a
 * series' name for every edition, the country names of every row, organization and event-type names, and sorts by
 * folded names (a fifth of the page's PHP time went to folding). fold() is a pure function of its input, so a
 * remembered fold is exactly the fold.
 *
 * Worker mode (FrankenPHP): emptied between requests (ResetInterface) and capped, so one long build or a console
 * command never holds more than MAX_ENTRIES texts. Nothing about a viewer is in here - only texts and their folds.
 */
final class SearchTextFolds implements ResetInterface
{
    public const int MAX_ENTRIES = 5000;

    /** @var array<string, string> */
    private array $folds = [];

    public function fold(string $text): string
    {
        if (isset($this->folds[$text])) {
            return $this->folds[$text];
        }

        if (count($this->folds) >= self::MAX_ENTRIES) {
            $this->folds = [];
        }

        return $this->folds[$text] = SearchText::fold($text);
    }

    public function remembered(): int
    {
        return count($this->folds);
    }

    public function reset(): void
    {
        $this->folds = [];
    }
}
