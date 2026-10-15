<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * GEST-14 (docs/features/navigation README §4.13 G9; Instant Back review F5 / Q3): "live" = every page whose state is
 * pushed by Mercure, a heartbeat or live video. A Back to one never shows the browser's back/forward-cache copy - the
 * element carrying its live controller says [data-bfcache-reload] (assets/instant_back.js reloads the page).
 *
 * The list of live controllers is the controllers that open a Mercure stream or send a heartbeat (found here), plus the
 * live video studio; the global notifications hub (mercure-hub, on every signed-in page) keeps only badges current and
 * is handed over or closed by itself (GEST-14).
 */
final class LivePagesReloadOnBackTest extends TestCase
{
    /** Controller file => its Stimulus identifier */
    private const array LIVE_CONTROLLERS = [
        'compete_controller.js' => 'compete',
        'conversation_list_controller.js' => 'conversation-list',
        'conversation_realtime_controller.js' => 'conversation-realtime',
        'live_results_controller.js' => 'live-results',
        'online_control_controller.js' => 'online-control',
        'online_review_controller.js' => 'online-review',
        'online_watch_controller.js' => 'online-watch',
        'results_desk_controller.js' => 'results-desk',
        'results_overview_controller.js' => 'results-overview',
        'round_seating_controller.js' => 'round-seating',
        'round_stopwatch_controller.js' => 'round-stopwatch',
    ];

    /** Live without Mercure: the host's video studio (LiveKit) and the personal stopwatch (its clock) */
    private const array ALSO_LIVE = ['online-studio', 'stopwatch'];

    private const string ROOT = __DIR__ . '/../';

    public function testTheLiveControllersAreKnown(): void
    {
        $found = [];

        foreach (new Finder()->files()->in(self::ROOT . 'assets/controllers')->name('*_controller.js') as $file) {
            if (preg_match('~EventSource|mercure|heartbeat~i', $file->getContents()) === 1 && $file->getFilename() !== 'mercure_hub_controller.js') {
                $found[] = $file->getFilename();
            }
        }

        sort($found);
        $listed = array_keys(self::LIVE_CONTROLLERS);
        sort($listed);

        self::assertSame($listed, $found, 'a controller that keeps its page live is listed here - and its pages reload on Back');
    }

    public function testEveryLivePageReloadsOnBack(): void
    {
        $live = [...array_values(self::LIVE_CONTROLLERS), ...self::ALSO_LIVE];
        $seen = [];
        $missing = [];

        foreach (new Finder()->files()->in(self::ROOT . 'templates')->name('*.twig') as $file) {
            preg_match_all('~<[a-z][a-z0-9-]*\b(?:[^>{]|\{\{.*?\}\}|\{%.*?%\})*>~s', $file->getContents(), $tags);

            foreach ($tags[0] as $tag) {
                if (preg_match('~\sdata-controller="([^"]*)"~', $tag, $controllers) !== 1) {
                    continue;
                }

                foreach (preg_split('~\s+~', trim($controllers[1])) ?: [] as $identifier) {
                    if (in_array($identifier, $live, true)) {
                        $seen[$identifier] = true;

                        if (!str_contains($tag, 'data-bfcache-reload')) {
                            $missing[] = $file->getRelativePathname() . ': ' . $identifier;
                        }
                    }
                }
            }
        }

        self::assertSame([], $missing);
        self::assertCount(count($live), $seen, 'every live controller is on a page: ' . implode(', ', array_diff($live, array_keys($seen))));
    }
}
