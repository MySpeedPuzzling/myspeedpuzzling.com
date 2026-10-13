<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\S3\ValueObject\CORSRule;
use SpeedPuzzling\Web\Value\OnlineMediaStore;

/**
 * The bucket CORS rule that lets participant phones upload evidence straight to object storage
 * (docs/features/online-competitions/README.md §5.11 "Security": CORS allowing `PUT` from the site's origin only) and
 * the review read a burst with fetch() (`GET`). Our rule has its own id (RULE_ID): applying it replaces that rule and
 * keeps every other rule of the bucket - the primary is the site's own bucket. Used by
 * `myspeedpuzzling:online-media:cors`; the production bucket gets it only with Jan's OK.
 */
final readonly class OnlineMediaBucketCors
{
    public const string RULE_ID = 'msp-online-media';
    public const int MAX_AGE_SECONDS = 3600;

    public function __construct(
        private OnlineMediaStores $stores,
    ) {
    }

    /**
     * @param list<string> $origins
     * @return array{ID: string, AllowedOrigins: list<string>, AllowedMethods: list<string>, AllowedHeaders: list<string>, ExposeHeaders: list<string>, MaxAgeSeconds: int}
     */
    public function rule(array $origins): array
    {
        return [
            'ID' => self::RULE_ID,
            'AllowedOrigins' => $origins,
            'AllowedMethods' => ['PUT', 'GET'],
            // The phone sends Content-Type itself (signed); Content-Length is the browser's own
            'AllowedHeaders' => ['content-type'],
            'ExposeHeaders' => ['ETag'],
            'MaxAgeSeconds' => self::MAX_AGE_SECONDS,
        ];
    }

    /**
     * The bucket's rules as they are now (none: an empty list)
     *
     * @return list<array<string, mixed>>
     */
    public function current(OnlineMediaStore $store): array
    {
        $result = $this->stores->client($store)->getBucketCors(['Bucket' => $this->stores->bucket($store)]);

        try {
            $rules = $result->getCorsRules();
        } catch (ClientException $exception) {
            // NoSuchCORSConfiguration
            if ($exception->getResponse()->getStatusCode() === 404) {
                return [];
            }

            throw $exception;
        }

        return array_values(array_map(static fn (CORSRule $rule): array => array_filter([
            'ID' => $rule->getId(),
            'AllowedOrigins' => $rule->getAllowedOrigins(),
            'AllowedMethods' => $rule->getAllowedMethods(),
            'AllowedHeaders' => $rule->getAllowedHeaders(),
            'ExposeHeaders' => $rule->getExposeHeaders(),
            'MaxAgeSeconds' => $rule->getMaxAgeSeconds(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []), $rules));
    }

    /**
     * Writes the bucket's rules with ours in place of the old one - the rules as written
     *
     * @param list<string> $origins
     * @return list<array<string, mixed>>
     */
    public function apply(OnlineMediaStore $store, array $origins): array
    {
        $rules = array_values(array_filter(
            $this->current($store),
            static fn (array $rule): bool => ($rule['ID'] ?? null) !== self::RULE_ID,
        ));
        $rules[] = $this->rule($origins);

        $this->stores->client($store)->putBucketCors([
            'Bucket' => $this->stores->bucket($store),
            'CORSConfiguration' => ['CORSRules' => $rules],
        ])->resolve();

        return $rules;
    }
}
