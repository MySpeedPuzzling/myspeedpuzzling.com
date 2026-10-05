<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\LanguageTag;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
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

    /**
     * Record versions (PuzzleRecordVersion) of puzzles as read before deciding - an object puzzle id => version, keyed
     * by the lower-case id; `[]` when the key is absent or null (nothing is checked).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    public static function recordVersions(array $body, string $key): array
    {
        $values = $body[$key] ?? null;

        if ($values === null) {
            return [];
        }

        if (is_array($values) === false || ($values !== [] && array_is_list($values))) {
            throw new BadRequestHttpException(sprintf('"%s" must be an object: puzzle id => recordVersion.', $key));
        }

        $versions = [];

        foreach ($values as $puzzleId => $version) {
            if (Uuid::isValid((string) $puzzleId) === false || is_string($version) === false || trim($version) === '') {
                throw new BadRequestHttpException(sprintf('"%s" must map puzzle ids to the recordVersion read for them.', $key));
            }

            $versions[strtolower((string) $puzzleId)] = $version;
        }

        return $versions;
    }

    /**
     * Puzzle names as a list of `{"name": "…", "language": "cs" | null}`, in order - null when the key is absent or
     * null, `[]` is an empty list. Names are cleaned (PuzzleNames::cleanName()), languages normalised (LanguageTag); a
     * blank or too long name, an unknown language or any other key in an entry (a typo like "lang" would drop the
     * language unnoticed) is a 400. How many names a puzzle may have is the caller's check.
     *
     * @param array<string, mixed> $body
     */
    public static function optionalPuzzleNames(array $body, string $key): null|PuzzleNames
    {
        $values = $body[$key] ?? null;

        if ($values === null) {
            return null;
        }

        if (is_array($values) === false || array_is_list($values) === false) {
            throw new BadRequestHttpException(sprintf('"%s" must be a list of {"name", "language"} objects.', $key));
        }

        $names = [];

        foreach ($values as $value) {
            $unknownKeys = is_array($value) ? array_diff(array_map(strval(...), array_keys($value)), ['name', 'language']) : [];

            if ($unknownKeys !== []) {
                throw new BadRequestHttpException(sprintf(
                    'An entry of "%s" holds only "name" and "language" - not: %s.',
                    $key,
                    implode(', ', $unknownKeys),
                ));
            }

            $name = is_array($value) && is_string($value['name'] ?? null) ? PuzzleNames::cleanName($value['name']) : '';

            if ($name === '') {
                throw new BadRequestHttpException(sprintf('Every entry of "%s" needs a "name".', $key));
            }

            if (mb_strlen($name) > PuzzleNames::MAX_NAME_LENGTH) {
                throw new BadRequestHttpException(sprintf('A name in "%s" can be at most %d characters long.', $key, PuzzleNames::MAX_NAME_LENGTH));
            }

            assert(is_array($value));
            $language = self::optionalLanguageTag($value, 'language');

            $names[] = new PuzzleName($name, $language === false ? null : $language);
        }

        return new PuzzleNames($names);
    }

    /**
     * A BCP 47 language tag, normalised (LanguageTag) - false when the key is absent, null for null or a blank string
     * (no language: English or not known), a 400 for anything that is no tag of a known language.
     *
     * @param array<mixed> $body
     */
    public static function optionalLanguageTag(array $body, string $key): null|false|string
    {
        if (array_key_exists($key, $body) === false) {
            return false;
        }

        $value = $body[$key];

        if ($value === null || $value === '') {
            return null;
        }

        $tag = is_string($value) ? LanguageTag::normalize($value) : null;

        return $tag ?? throw new BadRequestHttpException(sprintf(
            '"%s" must be a BCP 47 language tag such as "cs", "de" or "pt-BR", or null.',
            $key,
        ));
    }
}
