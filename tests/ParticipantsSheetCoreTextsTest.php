<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The core texts of the participants spreadsheet (templates/participants_sheet/_texts_core.html.twig) are one JSON
 * object holding every text the core JavaScript asks for - a key the JS uses but the partial misses would show its key
 * to the organiser. Plural texts come as browser_translation() objects ({message, locale}).
 */
final class ParticipantsSheetCoreTextsTest extends KernelTestCase
{
    // Keys built from a prefix in the JS (`marker_${state}`...) - each listed value must exist
    private const array DYNAMIC = [
        'marker_' => ['saving', 'waiting', 'conflict', 'refused', 'warning', 'info'],
        'status_hint_' => ['saved', 'saving', 'waiting', 'offline', 'attention', 'auth', 'forbidden', 'gone'],
        'round_kind_' => ['solo', 'duo', 'team'],
        'preview_status_' => ['new', 'change', 'same', 'warning', 'error', 'skip'],
        'field_' => ['name', 'country', 'externalId', 'note', 'result', 'table_number', 'qualified'],
        'op_' => ['newParticipant', 'player', 'place', 'newTeam', 'renameTeam', 'deleteTeam', 'remove', 'restore', 'teamSize'],
        'action_' => ['edit', 'field', 'link', 'unlink', 'add_person', 'remove', 'restore', 'place', 'round_in', 'round_out', 'new_team', 'put_in_team', 'clear_member', 'rename_team', 'delete_team', 'team_size', 'results', 'paste', 'clear', 'fill', 'keep_mine'],
        'help_keys_' => ['move', 'edit', 'type', 'commit', 'cancel', 'tab', 'space', 'list', 'select', 'select_column', 'copy', 'fill', 'clear', 'undo', 'leave', 'ime'],
        'help_does_' => ['move', 'edit', 'type', 'commit', 'cancel', 'tab', 'space', 'list', 'select', 'select_column', 'copy', 'fill', 'clear', 'undo', 'leave', 'ime'],
        'help_task_' => ['saving', 'saving_title', 'names', 'names_title', 'pairs', 'pairs_title', 'results', 'results_title', 'undo', 'undo_title', 'leave', 'leave_title'],
        'reason_' => ['participant_not_found', 'participant_removed', 'name_blank', 'name_too_long', 'external_id_too_long', 'note_too_long', 'invalid_country', 'team_name_too_long', 'id_taken', 'round_not_found', 'not_a_team_round', 'team_not_found', 'team_of_another_round', 'player_not_found', 'player_linked_elsewhere', 'has_result_in_round', 'has_result_in_event', 'team_has_result', 'invalid_team_size', 'invalid_change', 'external_id_taken', 'too_many_changes', 'has_result_in_round_own_time', 'has_result_in_event_own_time', 'team_has_result_emptied', 'team_has_result_waitlisted_only'],
    ];

    public function testEveryTextTheCoreScriptsUseIsInTheJson(): void
    {
        $texts = $this->render();
        $used = [];

        $files = [
            ...glob(__DIR__ . '/../assets/participants_sheet/*.js') ?: [],
            ...glob(__DIR__ . '/../assets/participants_sheet/views/people_view.js') ?: [],
            __DIR__ . '/../assets/controllers/participants_sheet_controller.js',
        ];

        foreach ($files as $file) {
            preg_match_all("/\\bt[c]?\\('([A-Za-z0-9_]+)'/", (string) file_get_contents($file), $matches);
            $used = [...$used, ...$matches[1]];
        }

        foreach (self::DYNAMIC as $prefix => $values) {
            foreach ($values as $value) {
                $used[] = $prefix . $value;
            }
        }

        // Undo/redo labels, plural counts of the tabs and the problems panel toggle are chosen in a ternary
        $used = [...$used, 'undo_label', 'undo_label_none', 'redo_label', 'redo_label_none', 'undo_refused', 'redo_refused', 'tab_count_pairs', 'tab_count_teams', 'problems_show', 'problems_hide', 'people_no_pair_yet', 'people_no_team_yet', 'people_change_round_in', 'people_change_round_out', 'grid_cut', 'grid_copied', 'login_url', 'undo_unsaved', 'redo_unsaved', 'undo_label_mac', 'redo_label_mac', 'status_attention_offline', 'undo_nothing', 'redo_nothing', 'undo_done', 'redo_done', 'undo_done_elsewhere', 'redo_done_elsewhere', 'grid_dropped', 'grid_dropped_empty'];

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the JS uses but _texts_core.html.twig does not have');
        self::assertGreaterThan(150, count($used));
    }

    public function testPluralTextsCarryTheirMessageAndLocale(): void
    {
        $texts = $this->render();

        self::assertSame(['message' => '1 waiting - offline|%count% waiting - offline', 'locale' => 'en'], $texts['status_offline'] ?? null);
        self::assertSame('All saved', $texts['status_saved'] ?? null);
        self::assertSame('Changed meanwhile', $texts['marker_conflict'] ?? null);
        self::assertSame(['message' => '1 change on another tab needs you.|%count% changes on other tabs need you.', 'locale' => 'en'], $texts['notify_problems_elsewhere'] ?? null);
        self::assertSame('This tab could not be loaded. Check the connection and try again.', $texts['view_load_failed'] ?? null);
        self::assertIsString($texts['login_url'] ?? null);
        self::assertStringStartsWith('/', $texts['login_url']);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(): array
    {
        $container = self::bootKernel()->getContainer();
        $request = Request::create('/en/participants-sheet/x?tab=people');
        $request->setLocale('en');
        $container->get('request_stack')->push($request);
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        $json = self::getContainer()->get(Environment::class)->render('participants_sheet/_texts_core.html.twig');
        $texts = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }
}
