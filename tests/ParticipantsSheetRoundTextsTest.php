<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The round texts of the participants spreadsheet (templates/participants_sheet/_texts_round.html.twig) are one JSON
 * object holding every text the round tabs' JavaScript asks for (views/team_round_view.js, solo_round_view.js,
 * round_cards_view.js, round/round_common.js) - a key the JS uses but the partial misses would show its key to the
 * organiser. Calls on the core texts (`core.t(…)`) are the core partial's business (ParticipantsSheetCoreTextsTest).
 */
final class ParticipantsSheetRoundTextsTest extends KernelTestCase
{
    // `tk(key)` = `key_pair` / `key_team`; keys built from a prefix (`paste_result_${reason}`); keys picked in a ternary
    private const array DYNAMIC = [
        'paste_result_' => ['unknown_name', 'unknown_entry', 'unknown_code', 'empty', 'not_in_round', 'waitlisted', 'below_list', 'no_entry', 'listed_again', 'new_pair', 'new_team'],
        'paste_results_' => ['intro', 'intro_tables'],
        'paste_create_' => ['pair', 'team'],
        'paste_looks_like_' => ['email', 'number', 'country'],
        'sorted_' => ['by_rank'],
        'where_tray_' => ['pair', 'team'],
        'size_too_many_' => ['pair', 'team'],
        'size_same_name_' => ['pair', 'team', 'other_pair', 'other_team'],
        'filter_without_' => ['pair', 'team'],
        'tray_title_' => ['pair', 'team'],
        'tray_short_' => ['pair', 'team'],
        'count_new_' => ['pairs', 'teams'],
        'count_' => ['pairs_matched', 'teams_matched', 'moves', 'new_people', 'to_choose', 'shared_names', 'results', 'replaces', 'skipped', 'unreadable', 'listed_twice', 'into_round', 'already_in'],
        'results_' => ['shown', 'hidden'],
        'member_' => ['ambiguous', 'unknown'],
        'added_' => ['to_round', 'new_to_round'],
        'link_' => ['live_entry', 'results_desk', 'seating'],
    ];

    private const array FILES = [
        'views/team_round_view.js',
        'views/solo_round_view.js',
        'views/round_cards_view.js',
        'round/round_common.js',
    ];

    public function testEveryTextTheRoundScriptsUseIsInTheJson(): void
    {
        $texts = $this->render();
        $used = [];

        foreach (self::FILES as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../assets/participants_sheet/' . $file);
            // The core texts' own calls
            $source = (string) preg_replace("/core\\.tc?\\('[A-Za-z0-9_]+'/", '', $source);

            preg_match_all("/\\bt[c]?\\('([A-Za-z0-9_]+)'/", $source, $matches);
            $used = [...$used, ...$matches[1]];

            preg_match_all("/\\btk\\('([A-Za-z0-9_]+)'/", $source, $matches);
            foreach ($matches[1] as $key) {
                $used[] = $key . '_pair';
                $used[] = $key . '_team';
            }
        }

        foreach (self::DYNAMIC as $prefix => $values) {
            foreach ($values as $value) {
                $used[] = $prefix . $value;
            }
        }

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the round scripts use but _texts_round.html.twig does not have');
        self::assertGreaterThan(150, count(array_unique($used)));
    }

    // Core texts the round views ask for through a ternary
    private const array CORE_DYNAMIC = ['tab_count_pairs', 'tab_count_teams'];

    /**
     * The round views also read core texts (`this.core.t(…)` / `.tc(…)`): every one must be in the core partial
     * (templates/participants_sheet/_texts_core.html.twig) - review D NIT.
     */
    public function testEveryCoreTextTheRoundScriptsUseIsInTheCoreJson(): void
    {
        $texts = $this->render('participants_sheet/_texts_core.html.twig');
        $used = self::CORE_DYNAMIC;

        foreach (self::FILES as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../assets/participants_sheet/' . $file);
            preg_match_all("/core\\.tc?\\('([A-Za-z0-9_]+)'/", $source, $matches);
            $used = [...$used, ...$matches[1]];
        }

        $used = array_values(array_unique($used));
        $missing = array_values(array_diff($used, array_keys($texts)));

        self::assertSame([], $missing, 'core texts the round scripts use but _texts_core.html.twig does not have');
        self::assertContains('team_no_name', $used);
    }

    public function testPluralTextsCarryTheirMessageAndLocaleAndResultsReuseOfficialResultsTexts(): void
    {
        $texts = $this->render();

        self::assertSame(['message' => 'In the round without a pair (1)|In the round without a pair (%count%)', 'locale' => 'en'], $texts['tray_title_pair'] ?? null);
        self::assertSame('%count%/%expected% - incomplete', $texts['size_incomplete'] ?? null);
        self::assertSame('Did not start', $texts['result_did_not_start'] ?? null);
        self::assertSame('%placed% / %pieces% pcs', $texts['result_pieces_placed_of'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(string $template = 'participants_sheet/_texts_round.html.twig'): array
    {
        $container = self::bootKernel()->getContainer();
        $request = Request::create('/en/participants-sheet/x?tab=people');
        $request->setLocale('en');
        $container->get('request_stack')->push($request);
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        $json = self::getContainer()->get(Environment::class)->render($template);
        $texts = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }
}
