<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a public API request ended, as counted in the usage statistics. 429 is its
 * own class so rate-limit rejections are visible per caller, not lost among 4xx.
 */
enum ApiStatusClass: string
{
    case Success = '2xx';
    case Redirect = '3xx';
    case ClientError = '4xx';
    case TooManyRequests = '429';
    case ServerError = '5xx';

    public static function fromStatusCode(int $statusCode): self
    {
        return match (true) {
            $statusCode === 429 => self::TooManyRequests,
            $statusCode >= 500 => self::ServerError,
            $statusCode >= 400 => self::ClientError,
            $statusCode >= 300 => self::Redirect,
            default => self::Success,
        };
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::ClientError, self::TooManyRequests, self::ServerError], true);
    }
}
