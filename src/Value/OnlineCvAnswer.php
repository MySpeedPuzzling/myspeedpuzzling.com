<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The CV worker's answer to one finish photo (OnlineCvClient):
 * - a verdict: complete / gaps (boxes in the photo's own pixels) / unsure, how sure, why, which checks ran (`method`),
 *   the worker's version, how long it took, the photo's size;
 * - retry: the worker was busy (503), could not reach a store (502) or did not answer - ask again later;
 * - failed: it refused the photo for good (gone from the store, not an image, a URL it may not fetch) - asking again
 *   changes nothing.
 */
final readonly class OnlineCvAnswer
{
    public const string VERDICT = 'verdict';
    public const string RETRY = 'retry';
    public const string FAILED = 'failed';

    /**
     * @param self::VERDICT|self::RETRY|self::FAILED $kind
     * @param list<array{x: int, y: int, w: int, h: int}> $gaps
     * @param null|array<string, mixed> $details
     */
    private function __construct(
        public string $kind,
        public null|OnlineFinishCheckVerdict $verdict = null,
        public null|float $confidence = null,
        public array $gaps = [],
        public null|string $reason = null,
        public null|string $method = null,
        public null|string $version = null,
        public null|int $ms = null,
        public null|int $imageWidth = null,
        public null|int $imageHeight = null,
        public null|array $details = null,
        public null|string $error = null,
    ) {
    }

    /**
     * @param list<array{x: int, y: int, w: int, h: int}> $gaps
     * @param null|array<string, mixed> $details
     */
    public static function verdict(
        OnlineFinishCheckVerdict $verdict,
        float $confidence,
        array $gaps,
        string $reason,
        string $method,
        string $version,
        null|int $ms,
        null|int $imageWidth,
        null|int $imageHeight,
        null|array $details,
    ): self {
        return new self(
            kind: self::VERDICT,
            verdict: $verdict,
            confidence: $confidence,
            gaps: $gaps,
            reason: $reason,
            method: $method,
            version: $version,
            ms: $ms,
            imageWidth: $imageWidth,
            imageHeight: $imageHeight,
            details: $details,
        );
    }

    public static function retry(string $error): self
    {
        return new self(kind: self::RETRY, error: $error);
    }

    public static function failed(string $error): self
    {
        return new self(kind: self::FAILED, error: $error);
    }
}
