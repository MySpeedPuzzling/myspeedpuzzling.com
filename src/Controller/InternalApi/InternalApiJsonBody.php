<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class InternalApiJsonBody
{
    /**
     * Decodes the JSON request body for internal API endpoints.
     * Empty body is treated as no fields (`{}`). Invalid JSON raises 400.
     *
     * @return array<string, mixed>
     */
    public static function parse(Request $request): array
    {
        $content = $request->getContent();

        if ($content === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON payload.', $e);
        }

        if (is_array($data) === false) {
            throw new BadRequestHttpException('JSON payload must be an object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        if (is_string($value) === false || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('"%s" is required.', $key));
        }

        return $value;
    }

    /**
     * Blank counts as absent, so it never overwrites a stored value.
     *
     * @param array<string, mixed> $body
     */
    public static function optionalString(array $body, string $key): null|string
    {
        $value = $body[$key] ?? null;

        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function confidence(array $body): null|MergeDecisionConfidence
    {
        $value = self::optionalString($body, 'decisionConfidence');

        if ($value === null) {
            return null;
        }

        return MergeDecisionConfidence::tryFrom($value) ?? throw new BadRequestHttpException(sprintf(
            '"decisionConfidence" must be one of: %s.',
            implode(', ', array_column(MergeDecisionConfidence::cases(), 'value')),
        ));
    }

    /**
     * A list of distinct ids, lowercased - a malformed id is a 400 here, not a 500
     * from deep inside a repository.
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    public static function uuidList(array $body, string $key, int $minimum): array
    {
        $values = $body[$key] ?? null;

        if (is_array($values) === false || array_is_list($values) === false) {
            throw new BadRequestHttpException(sprintf('"%s" must be a list of ids.', $key));
        }

        $ids = [];

        foreach ($values as $value) {
            if (is_string($value) === false || Uuid::isValid($value) === false) {
                throw new BadRequestHttpException(sprintf('"%s" must contain only ids.', $key));
            }

            $ids[] = strtolower($value);
        }

        $ids = array_values(array_unique($ids));

        if (count($ids) < $minimum) {
            throw new BadRequestHttpException(sprintf('"%s" needs at least %d distinct ids.', $key, $minimum));
        }

        return $ids;
    }
}
