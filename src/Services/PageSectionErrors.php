<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

/**
 * Collects what is wrong with one submitted section form (PageSectionRequestParser) - each reason once.
 */
final class PageSectionErrors
{
    /** @var array<string, array{key: string, parameters: array<string, int|string>}> */
    private array $errors = [];

    /**
     * @param array<string, int|string> $parameters
     */
    public function add(string $key, array $parameters = []): void
    {
        $this->errors[$key . '|' . implode('|', $parameters)] ??= ['key' => $key, 'parameters' => $parameters];
    }

    /**
     * @return list<array{key: string, parameters: array<string, int|string>}>
     */
    public function all(): array
    {
        return array_values($this->errors);
    }
}
