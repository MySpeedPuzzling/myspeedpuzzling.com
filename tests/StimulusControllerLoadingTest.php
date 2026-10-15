<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A Stimulus controller that hands Chart.js a plugin or options in `chartjs:pre-connect` must load eagerly. The Chart.js
 * controller itself is lazy (assets/controllers.json) and fires that event once, when it builds the chart - a lazy chunk
 * of the listening controller can arrive after it, and the chart silently renders without its plugin. That hid the
 * leaderboard's Median and You markers on the first puzzle page after a deploy (2026-09-30).
 */
final class StimulusControllerLoadingTest extends TestCase
{
    public function testControllersListeningToChartJsPreConnectLoadEagerly(): void
    {
        $listeners = [];

        foreach (glob(__DIR__ . '/../assets/controllers/*_controller.js') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, 'chartjs:pre-connect') === false) {
                continue;
            }

            // The way @symfony/stimulus-bridge reads it: a comment anywhere with stimulusFetch: 'lazy' (which is also why
            // no comment may merely mention that directive - the loader would try to evaluate it and fail the build)
            $listeners[] = basename($file);
            self::assertDoesNotMatchRegularExpression(
                '/stimulusFetch\s*:\s*[\'"]lazy[\'"]/',
                $source,
                basename($file) . ' listens to chartjs:pre-connect, so it must load eagerly',
            );
        }

        // The guard would pass without checking anything if the chart controllers moved or were renamed
        self::assertContains('leaderboard_chart_controller.js', $listeners);
        self::assertContains('time_chart_controller.js', $listeners);
    }

    /**
     * The one-tap actions (docs/features/navigation GEST-15, QA-04 R09) and the in-page confirmation (GEST-13) replaced
     * forms that posted in one tap without any controller. A lazy chunk not yet arrived made the first tap after a load
     * follow the link to the confirmation view instead - one tap more than before. They load with the page.
     */
    public function testTheOneTapActionsLoadEagerly(): void
    {
        foreach (['post_action_controller.js', 'confirm_action_controller.js'] as $controller) {
            $source = file_get_contents(__DIR__ . '/../assets/controllers/' . $controller);
            self::assertIsString($source, $controller);
            self::assertDoesNotMatchRegularExpression('/stimulusFetch\s*:\s*[\'"]lazy[\'"]/', $source, $controller . ' must load eagerly');
        }
    }
}
