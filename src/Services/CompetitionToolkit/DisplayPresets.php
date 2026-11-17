<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use InvalidArgumentException;
use SpeedPuzzling\Web\Exceptions\InvalidDisplayDesign;
use SpeedPuzzling\Web\Value\DisplayDesign;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;

/**
 * The 7 designs a display can start from (D-displays-overlays.md §6), as data: only the competition's own data, so a
 * fresh display works without any editing. Frames are % of the 1920×1080 canvas, font sizes logical px at 1080p.
 *
 * definition() is the compact form (only what differs from the sanitizer's defaults); design() is it normalised by
 * DisplayDesignSanitizer, which is what a new display stores. DisplayPresetsTest pins that normalising keeps every
 * value of the definition and that a normalised preset normalises to itself.
 *
 * The v1 cuts (R-review-and-plan.md §3) apply: the lower third shows the round name instead of D's spotlight line.
 */
final readonly class DisplayPresets
{
    public function __construct(
        private DisplayDesignSanitizer $sanitizer,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidArgumentException for a preset of the other kind
     * @throws InvalidDisplayDesign never for the presets below (pinned by the test)
     */
    public function design(DisplayPreset $preset, DisplayKind $kind, string $competitionId): array
    {
        return $this->sanitizer->normaliseArray(self::definition($preset, $kind), $competitionId);
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidArgumentException for a preset of the other kind
     */
    public static function definition(DisplayPreset $preset, DisplayKind $kind): array
    {
        if (!$preset->fits($kind)) {
            throw new InvalidArgumentException(sprintf('The preset %s is not for a display of kind %s', $preset->value, $kind->value));
        }

        $design = DisplayDesign::blank($kind);
        $design['elements'] = match ($preset) {
            DisplayPreset::Blank => [],
            DisplayPreset::ClassicTimer => self::classicTimer(),
            DisplayPreset::TimerWithSponsorBar => self::timerWithSponsorBar(),
            DisplayPreset::TimerWithLiveBoard => self::timerWithLiveBoard(),
            DisplayPreset::ObsLowerThird => self::obsLowerThird(),
            DisplayPreset::ObsCornerTimer => self::obsCornerTimer(),
            DisplayPreset::ObsLeaderboardSidebar => self::obsLeaderboardSidebar(),
        };

        return $design;
    }

    /**
     * The existing projector page made editable: event name, round badge, huge timer, status line, progress bar.
     *
     * @return list<array<string, mixed>>
     */
    private static function classicTimer(): array
    {
        return [
            self::element('evtname1', 'field', 'Event name', [5, 5, 90, 10], 1, ['fontSize' => 64, 'fontWeight' => 700], ['field' => 'competition_name']),
            self::element('rbadge01', 'round_badge', 'Round', [35, 17, 30, 8], 2, ['fontSize' => 44, 'radius' => 40], []),
            self::element('timer001', 'timer', 'Timer', [5, 28, 90, 40], 3, ['fontSize' => 360, 'font' => 'roboto_mono', 'fontWeight' => 700], self::timerProps()),
            self::element('status01', 'status', 'Status', [5, 70, 90, 8], 4, ['fontSize' => 56], []),
            self::element('progres1', 'progress', 'Progress', [5, 90, 90, 3], 5, ['radius' => 12], ['warningSeconds' => 300]),
        ];
    }

    /**
     * The classic layout shifted up, a white strip at the bottom: competition logo left, sponsor rotator in the middle
     * (the organiser uploads the pictures), QR code of the event page right.
     *
     * @return list<array<string, mixed>>
     */
    private static function timerWithSponsorBar(): array
    {
        return [
            self::element('evtname1', 'field', 'Event name', [5, 3, 90, 8], 1, ['fontSize' => 56, 'fontWeight' => 700], ['field' => 'competition_name']),
            self::element('rbadge01', 'round_badge', 'Round', [35, 12, 30, 7], 2, ['fontSize' => 40, 'radius' => 40], []),
            self::element('timer001', 'timer', 'Timer', [5, 21, 90, 36], 3, ['fontSize' => 320, 'font' => 'roboto_mono', 'fontWeight' => 700], self::timerProps()),
            self::element('status01', 'status', 'Status', [5, 58, 90, 7], 4, ['fontSize' => 48], []),
            self::element('progres1', 'progress', 'Progress', [5, 67, 90, 2.5], 5, ['radius' => 12], ['warningSeconds' => 300]),
            self::element('sponsbar', 'box', 'Sponsor bar', [0, 76, 100, 24], 6, ['background' => '#ffffff'], []),
            self::element('logo0001', 'image', 'Event logo', [2, 78, 16, 20], 7, [], ['source' => 'competition_logo']),
            self::element('rotator1', 'rotator', 'Sponsors', [22, 78, 58, 20], 8, [], ['seconds' => 8]),
            self::element('qrcode01', 'qr', 'Event page QR', [86, 78, 12, 20], 9, [], ['target' => 'event_page']),
        ];
    }

    /**
     * Timer on the left 60 %, the live top 10 on the right, how many have finished.
     *
     * @return list<array<string, mixed>>
     */
    private static function timerWithLiveBoard(): array
    {
        return [
            self::element('evtname1', 'field', 'Event name', [3, 4, 55, 8], 1, ['fontSize' => 52, 'fontWeight' => 700, 'align' => 'left'], ['field' => 'competition_name']),
            self::element('rbadge01', 'round_badge', 'Round', [3, 14, 25, 7], 2, ['fontSize' => 36, 'radius' => 40], []),
            self::element('timer001', 'timer', 'Timer', [3, 26, 55, 34], 3, ['fontSize' => 240, 'font' => 'roboto_mono', 'fontWeight' => 700], self::timerProps()),
            self::element('status01', 'status', 'Status', [3, 62, 55, 7], 4, ['fontSize' => 44], []),
            self::element('progres1', 'progress', 'Progress', [3, 74, 55, 3], 5, ['radius' => 12], ['warningSeconds' => 300]),
            self::element('finished', 'finished_count', 'Finished', [3, 82, 55, 8], 6, ['fontSize' => 52], ['source' => 'live']),
            self::element('boardbox', 'box', 'Board panel', [61, 4, 36, 92], 7, ['background' => '#ffffff14', 'radius' => 24], []),
            self::element('leaders1', 'leaderboard', 'Top 10', [63, 7, 32, 86], 8, ['fontSize' => 34, 'align' => 'left'], ['rows' => 10, 'source' => 'live']),
        ];
    }

    /**
     * A box bottom-left with the round name, the timer and the status - for a stream over a camera picture.
     *
     * @return list<array<string, mixed>>
     */
    private static function obsLowerThird(): array
    {
        return [
            self::element('lowerbox', 'box', 'Lower third', [3, 76, 48, 18], 1, ['background' => '#0d1017e6', 'radius' => 16], []),
            self::element('roundnm1', 'field', 'Round name', [5, 78, 44, 6], 2, ['fontSize' => 40, 'fontWeight' => 700, 'align' => 'left', 'fit' => 'shrink'], ['field' => 'round_name']),
            self::element('timer001', 'timer', 'Timer', [5, 84.5, 26, 9], 3, ['fontSize' => 96, 'font' => 'roboto_mono', 'fontWeight' => 700, 'align' => 'left'], self::timerProps()),
            self::element('status01', 'status', 'Status', [31, 84.5, 18, 9], 4, ['fontSize' => 32, 'align' => 'left'], []),
        ];
    }

    /**
     * A small rounded box top-right: the timer and a progress strip.
     *
     * @return list<array<string, mixed>>
     */
    private static function obsCornerTimer(): array
    {
        return [
            self::element('cornerbx', 'box', 'Corner box', [78, 3, 19, 12], 1, ['background' => '#0d1017d9', 'radius' => 20], []),
            self::element('timer001', 'timer', 'Timer', [79, 4, 17, 8], 2, ['fontSize' => 96, 'font' => 'roboto_mono', 'fontWeight' => 700], self::timerProps()),
            self::element('progres1', 'progress', 'Progress', [80, 12.5, 15, 1.2], 3, ['radius' => 6], ['warningSeconds' => 300]),
        ];
    }

    /**
     * A right column: round name, how many have finished, the live top 8 and the latest finishers.
     *
     * @return list<array<string, mixed>>
     */
    private static function obsLeaderboardSidebar(): array
    {
        return [
            self::element('sidebox1', 'box', 'Sidebar', [74, 3, 23, 94], 1, ['background' => '#0d1017d9', 'radius' => 20], []),
            self::element('roundnm1', 'field', 'Round name', [75, 4.5, 21, 5], 2, ['fontSize' => 36, 'fontWeight' => 700, 'fit' => 'shrink'], ['field' => 'round_name']),
            self::element('finished', 'finished_count', 'Finished', [75, 10, 21, 4], 3, ['fontSize' => 28], ['source' => 'live']),
            self::element('leaders1', 'leaderboard', 'Top 8', [75, 15, 21, 52], 4, ['fontSize' => 28, 'align' => 'left'], ['rows' => 8, 'columns' => ['rank', 'name', 'result'], 'source' => 'live']),
            self::element('ticker01', 'ticker', 'Latest finishers', [75, 70, 21, 25], 5, ['fontSize' => 24, 'align' => 'left'], ['rows' => 5, 'source' => 'live']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function timerProps(): array
    {
        return [
            'bigCountdown' => 10,
            'warningSeconds' => 300,
            'phaseColors' => ['warning' => '#fe696a', 'times_up' => '#fe696a'],
        ];
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
