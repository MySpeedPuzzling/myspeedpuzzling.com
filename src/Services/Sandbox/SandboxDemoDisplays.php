<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use SpeedPuzzling\Web\Value\DisplayDesign;
use SpeedPuzzling\Web\Value\DisplayKind;

/**
 * The displays of the SPWS demo event in the World Series' own look (docs/features/competitions-management/
 * spws-demo-event.md "Displays"): navy and gold like their logo, the logo itself through the image element's
 * `competition_logo` source. Ordinary designs - the organisers change them in the display editor. Frames are % of the
 * 1920×1080 canvas, font sizes logical px at 1080p (DisplayPresets).
 */
final readonly class SandboxDemoDisplays
{
    public const string NAVY = '#0a1a6b';
    public const string NAVY_DARK = '#050b3a';
    public const string GOLD = '#d4af37';
    public const string GOLD_LIGHT = '#f3e3a0';

    /**
     * @param array<string, string> $roundIds round key => round id
     * @return list<array{name: string, kind: DisplayKind, pinnedRoundId: null|string, design: array<string, mixed>}>
     */
    public static function all(array $roundIds): array
    {
        $displays = [
            ['name' => 'SPWS hall screen', 'kind' => DisplayKind::Screen, 'pinnedRoundId' => null, 'design' => self::hallScreen()],
            ['name' => 'SPWS stream overlay', 'kind' => DisplayKind::Overlay, 'pinnedRoundId' => null, 'design' => self::streamOverlay()],
            ['name' => 'SPWS championship standings', 'kind' => DisplayKind::Screen, 'pinnedRoundId' => null, 'design' => self::standings()],
        ];

        if (isset($roundIds['t12'])) {
            $displays[] = ['name' => 'SPWS challenge board', 'kind' => DisplayKind::Screen, 'pinnedRoundId' => $roundIds['t12'], 'design' => self::challengeBoard()];
        }

        $displays[] = ['name' => 'SPWS lower third', 'kind' => DisplayKind::Overlay, 'pinnedRoundId' => null, 'design' => self::lowerThird()];

        return $displays;
    }

    /**
     * The hall's big screen: logo, event and round, a huge timer, how many finished, the live top 10.
     *
     * @return array<string, mixed>
     */
    private static function hallScreen(): array
    {
        return self::design(DisplayKind::Screen, [
            self::background(),
            self::element('logo0001', 'image', 'Logo', [2.5, 4, 9, 16], 2, [], ['source' => 'competition_logo', 'fit' => 'contain']),
            self::element('evtname1', 'field', 'Event name', [13, 5, 46, 7], 3, ['fontSize' => 46, 'fontWeight' => 700, 'align' => 'left', 'color' => self::GOLD_LIGHT, 'uppercase' => true, 'letterSpacing' => 2, 'fit' => 'shrink'], ['field' => 'competition_name']),
            self::element('roundnm1', 'field', 'Round', [13, 12.5, 46, 7], 4, ['fontSize' => 44, 'fontWeight' => 700, 'align' => 'left', 'fit' => 'shrink'], ['field' => 'round_name']),
            self::element('timer001', 'timer', 'Timer', [2.5, 25, 57, 36], 5, ['fontSize' => 300, 'font' => 'bebas', 'color' => '#ffffff', 'letterSpacing' => 4], self::timerProps()),
            self::element('status01', 'status', 'Status', [2.5, 62, 57, 7], 6, ['fontSize' => 44, 'color' => self::GOLD_LIGHT], []),
            self::element('progres1', 'progress', 'Progress', [2.5, 72, 57, 2.5], 7, ['radius' => 12], ['trackColor' => '#ffffff22', 'barColor' => self::GOLD, 'warningColor' => '#fe696a', 'warningSeconds' => 600]),
            self::element('finished', 'finished_count', 'Finished', [2.5, 80, 57, 9], 8, ['fontSize' => 56, 'fontWeight' => 700, 'color' => self::GOLD], ['source' => 'live', 'format' => 'fraction']),
            self::element('boardbox', 'box', 'Board panel', [62, 4, 35.5, 92], 9, ['background' => '#00000040', 'radius' => 24, 'border' => ['width' => 3, 'color' => self::GOLD]], []),
            self::element('boardttl', 'text', 'Board title', [64, 6, 31.5, 6], 10, ['fontSize' => 36, 'fontWeight' => 700, 'color' => self::GOLD_LIGHT, 'uppercase' => true, 'letterSpacing' => 3], ['text' => 'Live top 10']),
            self::element('leaders1', 'leaderboard', 'Top 10', [64, 13.5, 31.5, 80], 11, ['fontSize' => 32, 'align' => 'left'], [
                'rows' => 10,
                'columns' => ['rank', 'flag', 'name', 'table', 'result'],
                'source' => 'live',
                'rowStyle' => ['background' => '#ffffff12', 'altBackground' => '#ffffff06', 'gap' => 6],
            ]),
        ]);
    }

    /**
     * The OBS overlay over the camera picture: a timer box top right, the live top 8 down the right, the latest
     * finishers bottom left, the logo top left. Transparent everywhere else.
     *
     * @return array<string, mixed>
     */
    private static function streamOverlay(): array
    {
        return self::design(DisplayKind::Overlay, [
            self::element('logo0001', 'image', 'Logo', [1.5, 2.5, 7, 12.5], 1, [], ['source' => 'competition_logo', 'fit' => 'contain']),
            self::element('timerbox', 'box', 'Timer box', [74, 2.5, 24.5, 15], 2, ['gradient' => ['from' => self::NAVY, 'to' => self::NAVY_DARK, 'angle' => 180], 'radius' => 18, 'border' => ['width' => 3, 'color' => self::GOLD]], []),
            self::element('roundnm1', 'field', 'Round', [75, 3.5, 22.5, 4.5], 3, ['fontSize' => 30, 'fontWeight' => 700, 'color' => self::GOLD_LIGHT, 'fit' => 'shrink'], ['field' => 'round_name']),
            self::element('timer001', 'timer', 'Timer', [75, 8, 22.5, 8.5], 4, ['fontSize' => 96, 'font' => 'bebas', 'color' => '#ffffff', 'letterSpacing' => 2], self::timerProps()),
            self::element('sidebox1', 'box', 'Board column', [79, 19.5, 19.5, 58], 5, ['background' => '#050b3ae0', 'radius' => 18, 'border' => ['width' => 2, 'color' => self::GOLD]], []),
            self::element('finished', 'finished_count', 'Finished', [80, 20.5, 17.5, 4.5], 6, ['fontSize' => 26, 'fontWeight' => 700, 'color' => self::GOLD], ['source' => 'live']),
            self::element('leaders1', 'leaderboard', 'Top 8', [80, 26, 17.5, 50], 7, ['fontSize' => 24, 'align' => 'left'], [
                'rows' => 8,
                'columns' => ['rank', 'flag', 'name', 'result'],
                'source' => 'live',
                'rowStyle' => ['background' => '#ffffff10', 'altBackground' => '#ffffff04', 'gap' => 4],
            ]),
            self::element('tickerbx', 'box', 'Finishers box', [1.5, 80, 40, 17.5], 8, ['background' => '#050b3ae0', 'radius' => 18, 'border' => ['width' => 2, 'color' => self::GOLD]], []),
            self::element('tickerhd', 'text', 'Finishers title', [2.5, 81, 38, 4], 9, ['fontSize' => 24, 'fontWeight' => 700, 'color' => self::GOLD_LIGHT, 'uppercase' => true, 'letterSpacing' => 2, 'align' => 'left'], ['text' => 'Just finished']),
            self::element('ticker01', 'ticker', 'Latest finishers', [2.5, 85.5, 38, 11], 10, ['fontSize' => 22, 'align' => 'left'], ['rows' => 3, 'source' => 'live']),
        ]);
    }

    /**
     * The championship's cumulative standing (the standing of the shown round's category), full screen.
     *
     * @return array<string, mixed>
     */
    private static function standings(): array
    {
        return self::design(DisplayKind::Screen, [
            self::background(),
            self::element('logo0001', 'image', 'Logo', [3, 4, 9, 16], 2, [], ['source' => 'competition_logo', 'fit' => 'contain']),
            self::element('heading1', 'text', 'Heading', [14, 5, 60, 7], 3, ['fontSize' => 54, 'fontWeight' => 700, 'align' => 'left', 'color' => self::GOLD_LIGHT, 'uppercase' => true, 'letterSpacing' => 3], ['text' => 'Championship standings']),
            // No "After <round>" line: the standing shown counts every published round of its championship, not up to the
            // round the screen follows - its own title says how far it is ("Final - all 3 rounds"; UX walk 2026-11-24)
            self::element('panel001', 'box', 'Panel', [3, 22, 94, 74], 5, ['background' => '#00000040', 'radius' => 24, 'border' => ['width' => 3, 'color' => self::GOLD]], []),
            self::element('standng1', 'standings', 'Standing', [5, 24, 90, 70], 6, ['fontSize' => 32, 'align' => 'left'], [
                'standingId' => 'auto',
                'rows' => 12,
                'columns' => ['rank', 'flag', 'name', 'rounds', 'total'],
                'title' => true,
                'rowStyle' => ['background' => '#ffffff12', 'altBackground' => '#ffffff06', 'gap' => 6],
                'whenEmpty' => 'text',
                'emptyText' => 'The standings appear once the first challenge is published',
            ]),
        ]);
    }

    /**
     * A block of two challenges (pinned to Teams - Challenges 1 + 2): a live leaderboard per challenge, ranked by
     * that challenge's own time (real events L2b).
     *
     * @return array<string, mixed>
     */
    private static function challengeBoard(): array
    {
        $elements = [
            self::background(),
            self::element('logo0001', 'image', 'Logo', [3, 3, 7, 12.5], 2, [], ['source' => 'competition_logo', 'fit' => 'contain']),
            self::element('roundnm1', 'field', 'Round', [12, 4, 76, 9], 3, ['fontSize' => 60, 'fontWeight' => 700, 'color' => self::GOLD_LIGHT, 'uppercase' => true, 'letterSpacing' => 2, 'fit' => 'shrink'], ['field' => 'round_name']),
        ];

        foreach ([1, 2] as $index => $challenge) {
            $x = 3 + $index * 48;
            $elements[] = self::element(sprintf('panel%03d', $challenge), 'box', 'Challenge ' . $challenge . ' panel', [$x, 18, 46, 78], 4, ['background' => '#00000040', 'radius' => 24, 'border' => ['width' => 3, 'color' => self::GOLD]], []);
            $elements[] = self::element(sprintf('title%03d', $challenge), 'text', 'Challenge ' . $challenge, [$x + 1, 20, 44, 7], 5, ['fontSize' => 44, 'fontWeight' => 700, 'color' => self::GOLD, 'uppercase' => true, 'letterSpacing' => 2], ['text' => 'Challenge ' . $challenge]);
            $elements[] = self::element(sprintf('board%03d', $challenge), 'leaderboard', 'Challenge ' . $challenge, [$x + 1.5, 28.5, 43, 66], 6, ['fontSize' => 30, 'align' => 'left'], [
                'rows' => 10,
                'columns' => ['rank', 'flag', 'name', 'result'],
                'source' => 'live',
                'challenge' => $challenge,
                'rowStyle' => ['background' => '#ffffff12', 'altBackground' => '#ffffff06', 'gap' => 6],
            ]);
        }

        return self::design(DisplayKind::Screen, $elements);
    }

    /**
     * For the commentators: the latest finisher (or the one picked on the live control page) and the live control's
     * message, bottom left over the camera picture.
     *
     * @return array<string, mixed>
     */
    private static function lowerThird(): array
    {
        return self::design(DisplayKind::Overlay, [
            self::element('lowerbox', 'box', 'Lower third', [3, 74, 52, 21], 1, ['gradient' => ['from' => self::NAVY, 'to' => self::NAVY_DARK, 'angle' => 90], 'radius' => 18, 'border' => ['width' => 3, 'color' => self::GOLD]], []),
            self::element('logo0001', 'image', 'Logo', [4, 75.5, 9, 18], 2, [], ['source' => 'competition_logo', 'fit' => 'contain']),
            self::element('spotlt01', 'spotlight', 'Finisher', [14.5, 76, 39, 10], 3, ['fontSize' => 40, 'fontWeight' => 700, 'align' => 'left'], [
                'pick' => 'latest_finisher',
                'show' => ['table', 'name', 'flag', 'result', 'rank'],
                'source' => 'live',
            ]),
            self::element('message1', 'message', 'Message', [14.5, 86.5, 39, 7], 4, ['fontSize' => 28, 'align' => 'left', 'color' => self::GOLD_LIGHT], ['maxLines' => 1]),
        ]);
    }

    /**
     * A full-canvas navy gradient behind everything (the canvas itself takes one colour only).
     *
     * @return array<string, mixed>
     */
    private static function background(): array
    {
        return self::element('backgrnd', 'box', 'Background', [0, 0, 100, 100], 0, ['gradient' => ['from' => self::NAVY, 'to' => self::NAVY_DARK, 'angle' => 160]], []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function timerProps(): array
    {
        return [
            'bigCountdown' => 10,
            'warningSeconds' => 600,
            'phaseColors' => ['warning' => self::GOLD, 'times_up' => '#fe696a'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @return array<string, mixed>
     */
    private static function design(DisplayKind $kind, array $elements): array
    {
        $design = DisplayDesign::blank($kind);

        if ($kind === DisplayKind::Screen) {
            $design['canvas']['background']['color'] = self::NAVY_DARK;
        }

        $design['theme'] = ['font' => 'rubik', 'color' => '#ffffff', 'accent' => self::GOLD];
        $design['elements'] = $elements;

        return $design;
    }

    /**
     * @param array{0: int|float, 1: int|float, 2: int|float, 3: int|float} $frame x, y, w, h in % of the canvas
     * @param array<string, mixed> $style
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function element(string $id, string $type, string $name, array $frame, int $z, array $style, array $props): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'frame' => ['x' => $frame[0], 'y' => $frame[1], 'w' => $frame[2], 'h' => $frame[3], 'rotate' => 0],
            'z' => $z,
            'style' => $style,
            'props' => $props,
        ];
    }
}
