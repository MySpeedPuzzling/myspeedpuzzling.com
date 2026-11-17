<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidDisplayDesign;
use SpeedPuzzling\Web\Results\DisplayDesignError;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use stdClass;

/**
 * The display design boundary (D-displays-overlays.md §4) over the shared cases of
 * tests/fixtures/display-design-cases.json - DisplaySchemaScriptTest runs assets/displays/schema.js over the very same
 * file, so the server and the browser cannot drift: valid, invalid, malicious (XSS and CSS injection attempts),
 * out-of-range values and unknown keys.
 */
final class DisplayDesignSanitizerTest extends TestCase
{
    public const string CASES = __DIR__ . '/../../fixtures/display-design-cases.json';

    /**
     * @return iterable<string, array{object, array<string, mixed>, string}>
     */
    public static function cases(): iterable
    {
        $json = (string) file_get_contents(self::CASES);
        $objects = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        $arrays = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        assert($objects instanceof stdClass && is_array($arrays) && is_array($arrays['cases']) && is_string($arrays['competitionId']));
        assert(is_array($objects->cases));

        foreach ($objects->cases as $index => $case) {
            assert($case instanceof stdClass && is_array($arrays['cases'][$index]) && is_string($case->name));

            /** @var array<string, mixed> $expected */
            $expected = $arrays['cases'][$index];

            yield $case->name => [$case, $expected, $arrays['competitionId']];
        }
    }

    /**
     * @param array<string, mixed> $expected the case decoded as arrays (expect / errors / uses)
     */
    #[DataProvider('cases')]
    public function testTheSharedCase(object $case, array $expected, string $competitionId): void
    {
        $sanitizer = new DisplayDesignSanitizer();

        try {
            $design = $sanitizer->normalise(self::input($case), $competitionId);
        } catch (InvalidDisplayDesign $exception) {
            self::assertArrayHasKey('errors', $expected, 'Refused: ' . $exception->getMessage());
            self::assertSame($expected['errors'], array_map(
                static fn (DisplayDesignError $error): array => $error->toArray(),
                $exception->errors,
            ));

            return;
        }

        self::assertArrayNotHasKey('errors', $expected, 'Not refused');
        self::assertSame($expected['expect'], $design);

        if (isset($expected['uses']) && is_array($expected['uses'])) {
            self::assertSame($expected['uses']['results'], DisplayDesignSanitizer::usesResults($design));
            self::assertSame($expected['uses']['live'], DisplayDesignSanitizer::usesLiveResults($design));
        }

        // A normalised design comes back unchanged - also in the associative form it is stored in
        self::assertSame($design, $sanitizer->normaliseArray($design, $competitionId));
    }

    public function testTheCompetitionIdIsComparedInLowerCase(): void
    {
        $path = 'competition-displays/018d0004-0000-0000-0000-00000000c0de/0193a7b2-1c3d-7e4f-8a9b-0c1d2e3f4a5b.png';
        $design = new DisplayDesignSanitizer()->normaliseArray([
            'schema' => 1,
            'elements' => [['id' => 'picture1', 'type' => 'image', 'frame' => [], 'props' => ['path' => $path]]],
        ], '018D0004-0000-0000-0000-00000000C0DE');

        self::assertSame([$path], DisplayDesignSanitizer::imagePaths($design));
    }

    public function testLiveElementIdsIgnoreVisibility(): void
    {
        $design = new DisplayDesignSanitizer()->normaliseArray([
            'schema' => 1,
            'elements' => [
                ['id' => 'shown001', 'type' => 'leaderboard', 'frame' => [], 'props' => ['source' => 'live']],
                ['id' => 'publish1', 'type' => 'ticker', 'frame' => [], 'props' => ['source' => 'published']],
                ['id' => 'hidden01', 'type' => 'finished_count', 'frame' => [], 'hidden' => true, 'props' => ['source' => 'live']],
            ],
        ], '018d0004-0000-0000-0000-00000000c0de');

        self::assertSame(['shown001' => 0, 'hidden01' => 2], DisplayDesignSanitizer::liveElementIds($design));
    }

    /**
     * Competition toolkit v2 S2: every shown standings element asks for its standing; it is no results type (no live
     * results, nothing for resultsDemand()), but a display with one hears the results signals (usesResults).
     */
    public function testStandingsDemandListsTheShownStandingsElements(): void
    {
        $design = new DisplayDesignSanitizer()->normaliseArray([
            'schema' => 1,
            'elements' => [
                ['id' => 'stand001', 'type' => 'standings', 'frame' => []],
                ['id' => 'stand002', 'type' => 'standings', 'frame' => [], 'props' => ['standingId' => '018D0004-0000-0000-0000-00000000ABCD', 'rows' => 5]],
                ['id' => 'stand003', 'type' => 'standings', 'frame' => [], 'hidden' => true],
                ['id' => 'stand004', 'type' => 'standings', 'frame' => [], 'when' => ['phases' => []]],
            ],
        ], '018d0004-0000-0000-0000-00000000c0de');

        self::assertSame([
            'stand001' => ['standingId' => 'auto', 'rows' => 10],
            'stand002' => ['standingId' => '018d0004-0000-0000-0000-00000000abcd', 'rows' => 5],
        ], DisplayDesignSanitizer::standingsDemand($design));
        self::assertSame([], DisplayDesignSanitizer::resultsDemand($design));
        self::assertTrue(DisplayDesignSanitizer::usesResults($design));
        self::assertFalse(DisplayDesignSanitizer::usesLiveResults($design));
    }

    private static function input(object $case): mixed
    {
        assert($case instanceof stdClass);
        $input = $case->input;

        if (isset($case->repeat) && $input instanceof stdClass) {
            assert($case->repeat instanceof stdClass && is_int($case->repeat->count));
            $template = json_encode($case->repeat->element, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $elements = [];

            for ($index = 0; $index < $case->repeat->count; $index++) {
                $elements[] = json_decode(str_replace(
                    ['{i8}', '{i12}'],
                    [sprintf('%08d', $index), sprintf('%012d', $index)],
                    $template,
                ), false, flags: JSON_THROW_ON_ERROR);
            }

            $input->elements = $elements;
        }

        return $input;
    }
}
