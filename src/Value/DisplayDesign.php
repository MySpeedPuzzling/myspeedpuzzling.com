<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The stored design of a competition display - schema v1 (docs/features/competitions-management/toolkit/
 * D-displays-overlays.md §4). D1 stores only the "blank" design a new display starts from; the sanitizer, the presets
 * and the save endpoint (D2) validate and normalise everything else against the same shape.
 */
final readonly class DisplayDesign
{
    public const int SCHEMA_VERSION = 1;
    public const string ASPECT = '16:9';
    public const string DEFAULT_BACKGROUND_COLOR = '#0d1017';
    public const string DEFAULT_CHROMA_COLOR = '#00b140';
    public const string DEFAULT_FONT = 'rubik';
    public const string DEFAULT_TEXT_COLOR = '#ffffff';
    public const string DEFAULT_ACCENT_COLOR = '#fe696a';

    /**
     * An empty canvas: opaque dark background for a hall screen, transparent for a stream overlay, no elements.
     *
     * @return array{
     *     schema: int,
     *     canvas: array{aspect: string, background: array{type: string, color: string, chroma: string}},
     *     theme: array{font: string, color: string, accent: string},
     *     elements: list<array<string, mixed>>,
     * }
     */
    public static function blank(DisplayKind $kind): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'canvas' => [
                'aspect' => self::ASPECT,
                'background' => [
                    'type' => $kind === DisplayKind::Overlay ? 'transparent' : 'color',
                    'color' => self::DEFAULT_BACKGROUND_COLOR,
                    'chroma' => self::DEFAULT_CHROMA_COLOR,
                ],
            ],
            'theme' => [
                'font' => self::DEFAULT_FONT,
                'color' => self::DEFAULT_TEXT_COLOR,
                'accent' => self::DEFAULT_ACCENT_COLOR,
            ],
            'elements' => [],
        ];
    }
}
