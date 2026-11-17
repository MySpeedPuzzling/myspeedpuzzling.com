<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Exceptions\InvalidDisplayDesign;
use SpeedPuzzling\Web\Results\DisplayDesignError;
use SpeedPuzzling\Web\Value\DisplayDesign;
use stdClass;

/**
 * The boundary of a display design - schema v1 (docs/features/competitions-management/toolkit/D-displays-overlays.md
 * §4, §4.1, §5 with the v1 cuts of R-review-and-plan.md §3: no spotlight/message elements, 4 fonts) + the `standings`
 * element of competition toolkit v2 S2 (displays-standings.md §3.2).
 *
 * A strict whitelist: only the known element types, keys and value ranges come out; there is no field for raw CSS,
 * HTML, SVG, classes or URLs except this event's own uploaded pictures and one https link for a QR code.
 * - **Structure** errors refuse the design (InvalidDisplayDesign with JSON pointers): not an object, unknown schema,
 *   elements not a list, more than 60 elements, an element that is not an object, a bad or duplicate id, an unknown
 *   type, a missing frame, a picture path that is not this event's upload, more than 30 pictures, over 64 KB.
 * - **Values** are normalised: numbers clamped and rounded, unknown keys dropped, bad colours and enums replaced by
 *   their default, texts plain (control characters removed, length capped).
 *
 * assets/displays/schema.js implements the same rules for the editor and the renderer; both are run over
 * tests/fixtures/display-design-cases.json, so they cannot drift. Change both together.
 *
 * The input is JSON decoded with objects as stdClass (json_decode($json, false)) - an object and a list stay apart
 * exactly as in the browser. normaliseArray() takes the associative form (presets, a design read from the database).
 */
final readonly class DisplayDesignSanitizer
{
    public const int MAX_ELEMENTS = 60;
    public const int MAX_IMAGES = 30;
    public const int MAX_BYTES = 65536;

    public const array ELEMENT_TYPES = [
        'timer', 'status', 'progress', 'text', 'field', 'round_badge', 'image', 'rotator', 'clock', 'puzzle',
        'finished_count', 'leaderboard', 'ticker', 'qr', 'box', 'standings',
    ];

    /** Elements that show official results - each has a `source` (published | live) */
    public const array RESULTS_TYPES = ['finished_count', 'leaderboard', 'ticker'];

    /** Columns of the standings element (competition toolkit v2 S2) - `stage` shows only once a standing has stages */
    public const array STANDINGS_COLUMNS = ['rank', 'flag', 'name', 'members', 'total', 'rounds', 'missing', 'stage'];

    public const array FONTS = ['rubik', 'bebas', 'roboto_mono', 'courier_prime'];
    public const array PHASES = ['idle', 'scheduled', 'running', 'times_up', 'stopped'];
    private const array FONT_WEIGHTS = [300, 400, 500, 700, 900];
    private const array FIELDS = ['competition_name', 'round_name', 'round_category', 'round_limit', 'round_start', 'location', 'event_date'];
    private const array PHASE_COLORS = ['idle', 'scheduled', 'running', 'warning', 'times_up', 'stopped'];

    private const string COLOR_PATTERN = '/^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?\z/';
    private const string ID_PATTERN = '/^[a-z0-9]{8}\z/';
    private const string UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';
    private const string URL_PATTERN = '~^https://[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+(?::[0-9]{1,5})?(?:[/?#][A-Za-z0-9\-._\~:/?#\[\]@!$&()*+,;=%]*)?\z~';
    private const string CONTROL_CHARACTERS = '/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u';
    private const int URL_MAX_LENGTH = 300;

    /**
     * @param array<mixed> $design the associative form (json_decode($json, true), a preset, the stored design)
     * @return array<string, mixed>
     * @throws InvalidDisplayDesign
     */
    public function normaliseArray(array $design, string $competitionId): array
    {
        return $this->normalise(
            json_decode(json_encode($design, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR),
            $competitionId,
        );
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidDisplayDesign
     */
    public function normalise(mixed $design, string $competitionId): array
    {
        $errors = [];
        $result = $this->run($design, strtolower($competitionId), $errors);

        if ($errors !== [] || $result === null) {
            throw new InvalidDisplayDesign($errors !== [] ? $errors : [new DisplayDesignError('', 'not_object')]);
        }

        return $result;
    }

    /**
     * Any element shows official results - the display's state carries results data at all (uses_results). Hidden
     * elements and elements shown in no phase do not count: they never render.
     *
     * @param array<string, mixed> $design a normalised design
     */
    public static function usesResults(array $design): bool
    {
        foreach (self::shownResultsElements($design) as $_) {
            return true;
        }

        // A result edit in a published round changes a published standing - its `results` signals must reach the display
        return self::standingsDemand($design) !== [];
    }

    /**
     * The shown standings elements (competition toolkit v2 S2, displays-standings.md §3.2) - each gets its standing in
     * the display's state. Not a results type: a standing is public data (published rounds of a published standing),
     * so it has no `source` and never asks for live results.
     *
     * @param array<string, mixed> $design a normalised design
     * @return array<string, array{standingId: string, rows: int}> element id => what it shows
     */
    public static function standingsDemand(array $design): array
    {
        $demand = [];

        foreach (is_array($design['elements'] ?? null) ? $design['elements'] : [] as $element) {
            if (
                !is_array($element)
                || ($element['type'] ?? null) !== 'standings'
                || ($element['hidden'] ?? false) === true
                || !is_array($element['when'] ?? null)
                || ($element['when']['phases'] ?? []) === []
                || !is_string($element['id'] ?? null)
            ) {
                continue;
            }

            $props = is_array($element['props'] ?? null) ? $element['props'] : [];
            $standingId = $props['standingId'] ?? null;
            $rows = $props['rows'] ?? null;

            $demand[$element['id']] = [
                'standingId' => is_string($standingId) && ($standingId === 'auto' || preg_match(self::UUID_PATTERN, $standingId) === 1) ? strtolower($standingId) : 'auto',
                'rows' => is_int($rows) ? max(1, min(30, $rows)) : 10,
            ];
        }

        return $demand;
    }

    /**
     * Any shown element asks for results before they are published (uses_live_results, D §5.1).
     *
     * @param array<string, mixed> $design a normalised design
     */
    public static function usesLiveResults(array $design): bool
    {
        foreach (self::shownResultsElements($design) as $element) {
            if (self::isLive($element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the shown results elements ask of the display's state (D3, GetCompetitionDisplayState): per element type
     * (finished_count, leaderboard, ticker) whether one of them shows live results and the most rows any of them shows.
     * A type no shown element has is missing - the state carries nothing of it.
     *
     * @param array<string, mixed> $design a normalised design
     * @return array<string, array{live: bool, rows: int}>
     */
    public static function resultsDemand(array $design): array
    {
        $demand = [];

        foreach (self::shownResultsElements($design) as $element) {
            $type = $element['type'];
            assert(is_string($type));
            $props = is_array($element['props'] ?? null) ? $element['props'] : [];
            $rows = is_int($props['rows'] ?? null) ? $props['rows'] : 0;

            $demand[$type] = [
                'live' => ($demand[$type]['live'] ?? false) || self::isLive($element),
                'rows' => max($demand[$type]['rows'] ?? 0, $rows),
            ];
        }

        return $demand;
    }

    /**
     * Ids of the results elements with `source: live`, hidden or not - the save handler asks for the organiser's
     * confirmation for every id that was not live before.
     *
     * @param array<mixed> $design a normalised design
     * @return array<string, int> element id => its index
     */
    public static function liveElementIds(array $design): array
    {
        $ids = [];
        $elements = $design['elements'] ?? [];

        if (!is_array($elements)) {
            return [];
        }

        foreach (array_values($elements) as $index => $element) {
            if (is_array($element) && self::isLive($element) && is_string($element['id'] ?? null)) {
                $ids[$element['id']] = $index;
            }
        }

        return $ids;
    }

    /**
     * Every picture a normalised design references (background, image elements, rotators), each once.
     *
     * @param array<mixed> $design
     * @return list<string>
     */
    public static function imagePaths(array $design): array
    {
        $paths = [];
        $canvas = $design['canvas'] ?? null;

        if (is_array($canvas) && is_array($canvas['background'] ?? null) && is_array($canvas['background']['image'] ?? null)) {
            $paths[] = $canvas['background']['image']['path'] ?? null;
        }

        foreach (is_array($design['elements'] ?? null) ? $design['elements'] : [] as $element) {
            if (!is_array($element) || !is_array($element['props'] ?? null)) {
                continue;
            }

            $props = $element['props'];

            if (($element['type'] ?? null) === 'image') {
                $paths[] = $props['path'] ?? null;
            }

            if (($element['type'] ?? null) === 'rotator' && is_array($props['images'] ?? null)) {
                foreach ($props['images'] as $image) {
                    $paths[] = is_array($image) ? ($image['path'] ?? null) : null;
                }
            }
        }

        return array_values(array_unique(array_filter($paths, is_string(...))));
    }

    /**
     * @param array<mixed> $design
     * @return iterable<array<mixed>>
     */
    private static function shownResultsElements(array $design): iterable
    {
        foreach (is_array($design['elements'] ?? null) ? $design['elements'] : [] as $element) {
            if (
                is_array($element)
                && in_array($element['type'] ?? null, self::RESULTS_TYPES, true)
                && ($element['hidden'] ?? false) !== true
                && is_array($element['when'] ?? null)
                && ($element['when']['phases'] ?? []) !== []
            ) {
                yield $element;
            }
        }
    }

    /**
     * @param array<mixed> $element
     */
    private static function isLive(array $element): bool
    {
        return in_array($element['type'] ?? null, self::RESULTS_TYPES, true)
            && is_array($element['props'] ?? null)
            && ($element['props']['source'] ?? null) === 'live';
    }

    /**
     * @param list<DisplayDesignError> $errors
     * @return null|array<string, mixed>
     */
    private function run(mixed $input, string $competitionId, array &$errors): null|array
    {
        if (!self::isObject($input)) {
            $errors[] = new DisplayDesignError('', 'not_object');

            return null;
        }

        $schema = self::get($input, 'schema');

        if (!self::isNumber($schema) || $schema != DisplayDesign::SCHEMA_VERSION) {
            $errors[] = new DisplayDesignError('/schema', 'unknown_schema');

            return null;
        }

        $canvas = $this->canvas(self::get($input, 'canvas'), $competitionId, $errors);
        $theme = $this->theme(self::get($input, 'theme'));

        $elementsInput = self::get($input, 'elements');
        $elements = [];

        if ($elementsInput !== null) {
            if (!self::isList($elementsInput)) {
                $errors[] = new DisplayDesignError('/elements', 'not_list');

                return null;
            }

            $list = is_array($elementsInput) ? array_values($elementsInput) : [];

            if (count($list) > self::MAX_ELEMENTS) {
                $errors[] = new DisplayDesignError('/elements', 'too_many_elements');

                return null;
            }

            $seenIds = [];

            foreach ($list as $index => $elementInput) {
                $element = $this->element($elementInput, '/elements/' . $index, $competitionId, $errors, $seenIds);

                if ($element !== null) {
                    $elements[] = $element;
                }
            }
        }

        if ($errors !== []) {
            return null;
        }

        $design = [
            'schema' => DisplayDesign::SCHEMA_VERSION,
            'canvas' => $canvas,
            'theme' => $theme,
            'elements' => $elements,
        ];

        if (count(self::imagePaths($design)) > self::MAX_IMAGES) {
            $errors[] = new DisplayDesignError('/elements', 'too_many_images');

            return null;
        }

        $json = json_encode($design, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);

        if (strlen($json) > self::MAX_BYTES) {
            $errors[] = new DisplayDesignError('', 'too_large');

            return null;
        }

        return $design;
    }

    /**
     * @param list<DisplayDesignError> $errors
     * @return array<string, mixed>
     */
    private function canvas(mixed $input, string $competitionId, array &$errors): array
    {
        $background = self::get($input, 'background');
        $imageInput = self::get($background, 'image');
        $image = null;

        if (self::isObject($imageInput)) {
            $path = $this->imagePath(self::get($imageInput, 'path'), '/canvas/background/image/path', $competitionId, $errors);

            if ($path !== null) {
                $image = [
                    'path' => $path,
                    'fit' => self::enum(self::get($imageInput, 'fit'), ['cover', 'contain'], 'cover'),
                    'opacity' => self::decimal(self::get($imageInput, 'opacity'), 0, 1, 1),
                ];
            }
        }

        return [
            'aspect' => DisplayDesign::ASPECT,
            'background' => [
                'type' => self::enum(self::get($background, 'type'), ['color', 'transparent', 'image', 'chroma'], 'color'),
                'color' => self::color(self::get($background, 'color')) ?? DisplayDesign::DEFAULT_BACKGROUND_COLOR,
                'chroma' => self::color(self::get($background, 'chroma')) ?? DisplayDesign::DEFAULT_CHROMA_COLOR,
                'image' => $image,
            ],
        ];
    }

    /**
     * @return array{font: string, color: string, accent: string}
     */
    private function theme(mixed $input): array
    {
        return [
            'font' => self::enum(self::get($input, 'font'), self::FONTS, DisplayDesign::DEFAULT_FONT),
            'color' => self::color(self::get($input, 'color')) ?? DisplayDesign::DEFAULT_TEXT_COLOR,
            'accent' => self::color(self::get($input, 'accent')) ?? DisplayDesign::DEFAULT_ACCENT_COLOR,
        ];
    }

    /**
     * @param list<DisplayDesignError> $errors
     * @param array<string, true> $seenIds
     * @return null|array<string, mixed>
     */
    private function element(mixed $input, string $path, string $competitionId, array &$errors, array &$seenIds): null|array
    {
        if (!self::isObject($input)) {
            $errors[] = new DisplayDesignError($path, 'element_not_object');

            return null;
        }

        $refused = false;
        $id = self::get($input, 'id');

        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            $errors[] = new DisplayDesignError($path . '/id', 'invalid_id');
            $refused = true;
        } elseif (isset($seenIds[$id])) {
            $errors[] = new DisplayDesignError($path . '/id', 'duplicate_id');
            $refused = true;
        } else {
            $seenIds[$id] = true;
        }

        $type = self::get($input, 'type');

        if (!is_string($type) || !in_array($type, self::ELEMENT_TYPES, true)) {
            $errors[] = new DisplayDesignError($path . '/type', 'unknown_type');

            return null;
        }

        $frame = self::get($input, 'frame');

        if (!self::isObject($frame)) {
            $errors[] = new DisplayDesignError($path . '/frame', 'missing_frame');

            return null;
        }

        $props = $this->props($type, self::get($input, 'props'), $path . '/props', $competitionId, $errors);

        if ($refused) {
            return null;
        }

        $when = self::get($input, 'when');

        return [
            'id' => $id,
            'type' => $type,
            'name' => self::plain(self::get($input, 'name'), 60, multiline: false, trim: true),
            'frame' => [
                'x' => self::decimal(self::get($frame, 'x'), 0, 100, 0),
                'y' => self::decimal(self::get($frame, 'y'), 0, 100, 0),
                'w' => self::decimal(self::get($frame, 'w'), 0.5, 100, 10),
                'h' => self::decimal(self::get($frame, 'h'), 0.5, 100, 10),
                'rotate' => self::decimal(self::get($frame, 'rotate'), -180, 180, 0),
            ],
            'z' => self::integer(self::get($input, 'z'), 0, 999, 0),
            'hidden' => self::boolean(self::get($input, 'hidden'), false),
            'locked' => self::boolean(self::get($input, 'locked'), false),
            'when' => [
                'phases' => self::subset(self::get($when, 'phases'), self::PHASES, self::PHASES),
                'results' => self::enum(self::get($when, 'results'), ['any', 'published', 'not_published'], 'any'),
                'puzzle' => self::enum(self::get($when, 'puzzle'), ['any', 'revealed', 'not_revealed'], 'any'),
            ],
            'style' => $this->style(self::get($input, 'style')),
            'props' => $props,
        ];
    }

    /**
     * Only the keys given with a valid value - a missing key means "the theme's / the type's default" (§4.1).
     *
     * @return array<string, mixed>
     */
    private function style(mixed $input): array
    {
        $style = [];

        $font = self::get($input, 'font');
        if (is_string($font) && in_array($font, self::FONTS, true)) {
            $style['font'] = $font;
        }

        self::put($style, 'fontSize', self::optionalInteger(self::get($input, 'fontSize'), 8, 900));

        $weight = self::get($input, 'fontWeight');
        if (self::isNumber($weight)) {
            $nearest = self::FONT_WEIGHTS[0];
            foreach (self::FONT_WEIGHTS as $candidate) {
                if (abs($weight - $candidate) < abs($weight - $nearest)) {
                    $nearest = $candidate;
                }
            }
            $style['fontWeight'] = $nearest;
        }

        self::put($style, 'color', self::color(self::get($input, 'color')));
        self::put($style, 'background', self::color(self::get($input, 'background')));

        $gradient = self::get($input, 'gradient');
        $from = self::color(self::get($gradient, 'from'));
        $to = self::color(self::get($gradient, 'to'));
        if ($from !== null && $to !== null) {
            $style['gradient'] = ['from' => $from, 'to' => $to, 'angle' => self::integer(self::get($gradient, 'angle'), 0, 359, 180)];
        }

        $border = self::get($input, 'border');
        $borderColor = self::color(self::get($border, 'color'));
        if ($borderColor !== null) {
            $style['border'] = ['width' => self::integer(self::get($border, 'width'), 0, 40, 1), 'color' => $borderColor];
        }

        self::put($style, 'radius', self::optionalInteger(self::get($input, 'radius'), 0, 540));
        self::put($style, 'padding', self::optionalInteger(self::get($input, 'padding'), 0, 200));
        self::put($style, 'opacity', self::optionalDecimal(self::get($input, 'opacity'), 0, 1));
        self::put($style, 'align', self::optionalEnum(self::get($input, 'align'), ['left', 'center', 'right']));
        self::put($style, 'valign', self::optionalEnum(self::get($input, 'valign'), ['top', 'middle', 'bottom']));
        self::put($style, 'shadow', self::optionalEnum(self::get($input, 'shadow'), ['none', 'soft', 'strong', 'outline']));

        $uppercase = self::get($input, 'uppercase');
        if (is_bool($uppercase)) {
            $style['uppercase'] = $uppercase;
        }

        self::put($style, 'letterSpacing', self::optionalDecimal(self::get($input, 'letterSpacing'), -10, 40));
        self::put($style, 'lineHeight', self::optionalDecimal(self::get($input, 'lineHeight'), 0.8, 2));
        self::put($style, 'fit', self::optionalEnum(self::get($input, 'fit'), ['none', 'shrink', 'fill']));

        return $style;
    }

    /**
     * @param list<DisplayDesignError> $errors
     * @return array<string, mixed>
     */
    private function props(string $type, mixed $input, string $path, string $competitionId, array &$errors): array
    {
        return match ($type) {
            'timer' => [
                'mode' => self::enum(self::get($input, 'mode'), ['auto', 'elapsed', 'remaining'], 'auto'),
                'format' => self::enum(self::get($input, 'format'), ['auto', 'hh:mm:ss', 'mm:ss'], 'auto'),
                'bigCountdown' => self::integer(self::get($input, 'bigCountdown'), 0, 30, 0),
                'phaseColors' => self::colorMap(self::get($input, 'phaseColors'), self::PHASE_COLORS),
                'warningSeconds' => self::integer(self::get($input, 'warningSeconds'), 0, 3600, 0),
                'idleText' => self::enum(self::get($input, 'idleText'), ['dashes', 'zero', 'blank'], 'dashes'),
            ],
            'status' => [
                'texts' => self::textMap(self::get($input, 'texts'), self::PHASES, 80),
            ],
            'progress' => [
                'direction' => self::enum(self::get($input, 'direction'), ['fill', 'drain'], 'fill'),
                'orientation' => self::enum(self::get($input, 'orientation'), ['horizontal', 'vertical'], 'horizontal'),
                'trackColor' => self::color(self::get($input, 'trackColor')),
                'barColor' => self::color(self::get($input, 'barColor')),
                'warningColor' => self::color(self::get($input, 'warningColor')),
                'warningSeconds' => self::integer(self::get($input, 'warningSeconds'), 0, 3600, 0),
            ],
            'text' => [
                'text' => self::plain(self::get($input, 'text'), 300, multiline: true, trim: true),
            ],
            'field' => [
                'field' => self::enum(self::get($input, 'field'), self::FIELDS, 'round_name'),
                'prefix' => self::plain(self::get($input, 'prefix'), 60, multiline: false, trim: false),
                'suffix' => self::plain(self::get($input, 'suffix'), 60, multiline: false, trim: false),
            ],
            'round_badge' => [
                'useRoundColors' => self::boolean(self::get($input, 'useRoundColors'), true),
            ],
            'image' => $this->imageProps($input, $path, $competitionId, $errors),
            'rotator' => $this->rotatorProps($input, $path, $competitionId, $errors),
            'clock' => [
                'zone' => self::enum(self::get($input, 'zone'), ['round', 'device'], 'round'),
                'format' => self::enum(self::get($input, 'format'), ['24h', '12h'], '24h'),
                'seconds' => self::boolean(self::get($input, 'seconds'), false),
            ],
            'puzzle' => [
                'layout' => self::enum(self::get($input, 'layout'), ['row', 'column', 'grid'], 'row'),
                'show' => self::subset(self::get($input, 'show'), ['image', 'name', 'brand', 'pieces'], ['image', 'name']),
                'max' => self::integer(self::get($input, 'max'), 1, 6, 3),
                'whenHidden' => self::enum(self::get($input, 'whenHidden'), ['nothing', 'text'], 'nothing'),
                'hiddenText' => self::plain(self::get($input, 'hiddenText'), 80, multiline: false, trim: true),
                'imageFit' => self::enum(self::get($input, 'imageFit'), ['contain', 'cover'], 'contain'),
            ],
            'finished_count' => [
                'format' => self::enum(self::get($input, 'format'), ['fraction', 'count', 'percent'], 'fraction'),
                ...self::resultsSource($input),
            ],
            'leaderboard' => [
                'rows' => self::integer(self::get($input, 'rows'), 1, 30, 10),
                'columns' => self::subset(self::get($input, 'columns'), ['rank', 'flag', 'name', 'members', 'result', 'table'], ['rank', 'flag', 'name', 'result']),
                'highlightLatestSeconds' => self::integer(self::get($input, 'highlightLatestSeconds'), 0, 60, 10),
                'rowStyle' => [
                    'background' => self::color(self::get(self::get($input, 'rowStyle'), 'background')),
                    'altBackground' => self::color(self::get(self::get($input, 'rowStyle'), 'altBackground')),
                    'gap' => self::integer(self::get(self::get($input, 'rowStyle'), 'gap'), 0, 40, 4),
                ],
                ...self::resultsSource($input),
            ],
            'ticker' => [
                'rows' => self::integer(self::get($input, 'rows'), 1, 10, 5),
                ...self::resultsSource($input),
            ],
            'qr' => self::qrProps($input),
            'standings' => [
                'standingId' => self::standingRef(self::get($input, 'standingId')),
                'rows' => self::integer(self::get($input, 'rows'), 1, 30, 10),
                'columns' => self::subset(self::get($input, 'columns'), self::STANDINGS_COLUMNS, ['rank', 'flag', 'name', 'total']),
                'title' => self::boolean(self::get($input, 'title'), true),
                'rowStyle' => [
                    'background' => self::color(self::get(self::get($input, 'rowStyle'), 'background')),
                    'altBackground' => self::color(self::get(self::get($input, 'rowStyle'), 'altBackground')),
                    'gap' => self::integer(self::get(self::get($input, 'rowStyle'), 'gap'), 0, 40, 4),
                ],
                'whenEmpty' => self::enum(self::get($input, 'whenEmpty'), ['nothing', 'text'], 'nothing'),
                'emptyText' => self::plain(self::get($input, 'emptyText'), 80, multiline: false, trim: true),
            ],
            default => [], // box
        };
    }

    /**
     * D §5.1: `published` (the default) shows nothing before the round's results are published; `live` shows them as
     * they are entered - only by the organiser's explicit choice.
     *
     * @return array{source: string, whenUnpublished: string, unpublishedText: string}
     */
    private static function resultsSource(mixed $input): array
    {
        return [
            'source' => self::enum(self::get($input, 'source'), ['published', 'live'], 'published'),
            'whenUnpublished' => self::enum(self::get($input, 'whenUnpublished'), ['nothing', 'text'], 'nothing'),
            'unpublishedText' => self::plain(self::get($input, 'unpublishedText'), 80, multiline: false, trim: true),
        ];
    }

    /**
     * @param list<DisplayDesignError> $errors
     * @return array{source: string, path: null|string, fit: string}
     */
    private function imageProps(mixed $input, string $path, string $competitionId, array &$errors): array
    {
        $source = self::enum(self::get($input, 'source'), ['upload', 'competition_logo', 'series_logo', 'organization_logo'], 'upload');
        $imagePath = $this->imagePath(self::get($input, 'path'), $path . '/path', $competitionId, $errors);

        return [
            'source' => $source,
            'path' => $source === 'upload' ? $imagePath : null,
            'fit' => self::enum(self::get($input, 'fit'), ['contain', 'cover'], 'contain'),
        ];
    }

    /**
     * @param list<DisplayDesignError> $errors
     * @return array{images: list<array{path: string}>, seconds: int, transition: string, fit: string}
     */
    private function rotatorProps(mixed $input, string $path, string $competitionId, array &$errors): array
    {
        $images = [];
        $imagesInput = self::get($input, 'images');

        if (self::isList($imagesInput) && is_array($imagesInput)) {
            if (count($imagesInput) > self::MAX_IMAGES) {
                $errors[] = new DisplayDesignError($path . '/images', 'too_many_images');
            } else {
                foreach (array_values($imagesInput) as $index => $image) {
                    $imagePath = $this->imagePath(self::get($image, 'path'), $path . '/images/' . $index . '/path', $competitionId, $errors);

                    if ($imagePath !== null) {
                        $images[] = ['path' => $imagePath];
                    }
                }
            }
        }

        return [
            'images' => $images,
            'seconds' => self::integer(self::get($input, 'seconds'), 3, 120, 8),
            'transition' => self::enum(self::get($input, 'transition'), ['none', 'fade'], 'fade'),
            'fit' => self::enum(self::get($input, 'fit'), ['contain', 'cover'], 'contain'),
        ];
    }

    /**
     * `auto` (the display picks the standing of the round it shows) or a standing's id, lowercased - anything else is
     * `auto`. Whether the id is a published standing of this event is the state's question (an unknown one shows
     * nothing), not the design's: a standing can be published or deleted after the design was saved.
     */
    private static function standingRef(mixed $value): string
    {
        return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : 'auto';
    }

    /**
     * @return array{target: string, url: null|string, fg: string, bg: string, quietZone: bool}
     */
    private static function qrProps(mixed $input): array
    {
        $target = self::enum(self::get($input, 'target'), ['event_page', 'round_results', 'custom'], 'event_page');
        $url = self::get($input, 'url');

        return [
            'target' => $target,
            'url' => $target === 'custom' && is_string($url) && mb_strlen($url) <= self::URL_MAX_LENGTH && preg_match(self::URL_PATTERN, $url) === 1 ? $url : null,
            'fg' => self::color(self::get($input, 'fg')) ?? '#000000',
            'bg' => self::color(self::get($input, 'bg')) ?? '#ffffff',
            'quietZone' => self::boolean(self::get($input, 'quietZone'), true),
        ];
    }

    /**
     * A picture uploaded for this event's displays (competition-displays/<competitionId>/<uuid>.<ext>) - never an
     * external URL, never another event's file (removing it could delete their picture).
     *
     * @param list<DisplayDesignError> $errors
     */
    private function imagePath(mixed $value, string $path, string $competitionId, array &$errors): null|string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $pattern = '~^competition-displays/' . preg_quote($competitionId, '~') . '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpe?g|png|gif|webp)\z~';

        if (preg_match($pattern, $value) !== 1) {
            $errors[] = new DisplayDesignError($path, 'foreign_image');

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $keys
     * @return array<string, null|string>
     */
    private static function colorMap(mixed $input, array $keys): array
    {
        $map = [];

        foreach ($keys as $key) {
            $map[$key] = self::color(self::get($input, $key));
        }

        return $map;
    }

    /**
     * @param list<string> $keys
     * @return array<string, null|string>
     */
    private static function textMap(mixed $input, array $keys, int $maxLength): array
    {
        $map = [];

        foreach ($keys as $key) {
            $text = self::plain(self::get($input, $key), $maxLength, multiline: false, trim: true);
            $map[$key] = $text !== '' ? $text : null;
        }

        return $map;
    }

    private static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || $value === [];
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) || ($value instanceof stdClass && get_object_vars($value) === []);
    }

    private static function get(mixed $object, string $key): mixed
    {
        if ($object instanceof stdClass && property_exists($object, $key)) {
            return $object->{$key};
        }

        return null;
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function color(mixed $value): null|string
    {
        return is_string($value) && preg_match(self::COLOR_PATTERN, $value) === 1 ? strtolower($value) : null;
    }

    /**
     * @param list<string> $allowed
     */
    private static function enum(mixed $value, array $allowed, string $default): string
    {
        return self::optionalEnum($value, $allowed) ?? $default;
    }

    /**
     * @param list<string> $allowed
     */
    private static function optionalEnum(mixed $value, array $allowed): null|string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function boolean(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * The allowed values given, in the allowed order, each once; not a list = the default.
     *
     * @param list<string> $allowed
     * @param list<string> $default
     * @return list<string>
     */
    private static function subset(mixed $value, array $allowed, array $default): array
    {
        if (!self::isList($value)) {
            return $default;
        }

        $given = is_array($value) ? $value : [];

        return array_values(array_filter($allowed, static fn (string $item): bool => in_array($item, $given, true)));
    }

    private static function integer(mixed $value, int $min, int $max, int $default): int
    {
        return self::optionalInteger($value, $min, $max) ?? $default;
    }

    private static function optionalInteger(mixed $value, int $min, int $max): null|int
    {
        if (!self::isNumber($value)) {
            return null;
        }

        $clamped = max($min, min($max, (float) $value));

        // Half away from zero by floor - the same IEEE operations as schema.js (PHP's round() pre-rounds differently)
        return (int) (($clamped < 0 ? -1 : 1) * floor(abs($clamped) + 0.5));
    }

    private static function decimal(mixed $value, int|float $min, int|float $max, int|float $default): int|float
    {
        return self::optionalDecimal($value, $min, $max) ?? $default;
    }

    /**
     * Two decimals; a whole number comes out as an int, so PHP and the browser encode it the same way.
     */
    private static function optionalDecimal(mixed $value, int|float $min, int|float $max): null|int|float
    {
        if (!self::isNumber($value)) {
            return null;
        }

        $clamped = max((float) $min, min((float) $max, (float) $value));
        $rounded = ($clamped < 0 ? -1 : 1) * floor(abs($clamped) * 100 + 0.5) / 100;

        return $rounded == floor($rounded) ? (int) $rounded : $rounded;
    }

    /**
     * Plain text: CRLF → LF, line breaks → spaces on one-line texts, control characters removed, trimmed of spaces and
     * line breaks (not on prefixes/suffixes, whose spaces matter), cut to $maxLength characters.
     */
    private static function plain(mixed $value, int $maxLength, bool $multiline, bool $trim): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = str_replace("\r\n", "\n", $value);

        if ($multiline === false) {
            $value = str_replace("\n", ' ', $value);
        }

        $value = (string) preg_replace(self::CONTROL_CHARACTERS, '', $value);

        if ($trim) {
            $value = (string) preg_replace('/^[ \n]+|[ \n]+\z/u', '', $value);
        }

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $style
     */
    private static function put(array &$style, string $key, mixed $value): void
    {
        if ($value !== null) {
            $style[$key] = $value;
        }
    }
}
