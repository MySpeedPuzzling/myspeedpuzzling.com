<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The People texts of the participants spreadsheet (templates/participants_sheet/_texts_people.html.twig) are one JSON
 * object holding every text the People modules ask for (`say('key')` / `sayCount('key', n)`): a key the JS uses but the
 * partial misses would show its key to the organiser. The core texts those modules use (`t('key')`) must be in the core
 * partial. Plural texts come as browser_translation() objects ({message, locale}).
 */
final class ParticipantsSheetPeopleTextsTest extends KernelTestCase
{
    private const array PEOPLE_FILES = [
        'assets/participants_sheet/views/people_view.js',
        'assets/participants_sheet/views/person_editor.js',
        'assets/participants_sheet/views/people_list_view.js',
        'assets/participants_sheet/registration_actions.js',
        'assets/participants_sheet/people_paste.js',
    ];

    // Keys built from a prefix in the JS (`filter_${key}`...) - each listed value must exist
    private const array DYNAMIC = [
        'filter_' => ['all', 'no_round', 'no_solo', 'multi_solo', 'joined', 'waitlist', 'not_paid', 'paid', 'checked_in', 'not_checked_in', 'duplicates', 'removed'],
        'column_' => ['name', 'country', 'player', 'rounds', 'externalId', 'source', 'joined', 'registration', 'registered', 'paid', 'checkedIn', 'note'],
        'col_' => ['externalId', 'source', 'joined', 'registration', 'registered', 'paid', 'checkedIn', 'note'],
        'source_' => ['self_joined', 'imported', 'manual'],
        'status_' => ['reserved', 'paid', 'waitlisted'],
        'reg_' => ['markPaid', 'unmarkPaid', 'promote', 'promoteAndMarkPaid', 'checkIn', 'undoCheckIn', 'markPaid_help', 'unmarkPaid_help', 'promote_help', 'promoteAndMarkPaid_help', 'checkIn_help', 'undoCheckIn_help'],
        'reg_done_' => ['markPaid', 'unmarkPaid', 'promote', 'promoteAndMarkPaid', 'checkIn', 'undoCheckIn'],
        'make_' => ['pair_title', 'team_title', 'pair_confirm', 'team_confirm', 'pair_done', 'team_done'],
        'make_team_line_from_tray_' => ['pair', 'team'],
        'make_team_size_' => ['pair', 'team'],
        'paste_change_' => ['externalId', 'note'],
        'paste_looks_' => ['country', 'number', 'email'],
        'sort_done_' => ['asc', 'desc'],
        'bulk_reg_title_' => ['markPaid', 'checkIn'],
        'bulk_reg_confirm_' => ['markPaid', 'checkIn'],
        'bulk_reg_done_' => ['markPaid', 'checkIn'],
        'bulk_reg_none_' => ['markPaid', 'checkIn'],
        'bulk_reg_stopped_' => ['auth', 'forbidden'],
    ];

    // Chosen in a ternary - not found by the scan
    private const array CHOSEN = [
        'column_shown', 'column_hidden', 'bulk_make_pair_in', 'bulk_make_team_in', 'bulk_solo_in_done', 'bulk_solo_out_done',
        'editor_new_pair', 'editor_new_team', 'editor_pair_in', 'editor_team_in', 'editor_in_no_pair', 'editor_in_no_team',
        'editor_new_pair_done', 'editor_new_team_done', 'card_no_pair', 'card_no_team', 'list_empty', 'list_empty_search',
        'reg_failed', 'reg_failed_offline', 'reg_failed_auth', 'counter_spots', 'counter_spots_capacity', 'source_imported',
        'source_manual', 'first_in_line', 'first_in_line_no_capacity', 'editor_previous_none', 'editor_next_none',
        'selected_count', 'selection_cleared',
    ];

    public function testEveryTextThePeopleScriptsUseIsInTheJson(): void
    {
        $people = $this->render('participants_sheet/_texts_people.html.twig');
        $core = $this->render('participants_sheet/_texts_core.html.twig');
        $usedPeople = [];
        $usedCore = [];

        foreach (self::PEOPLE_FILES as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../' . $file);
            preg_match_all("/\\bsay(?:Count)?\\('([A-Za-z0-9_]+)'/", $source, $matches);
            $usedPeople = [...$usedPeople, ...$matches[1]];
            preg_match_all("/\\bt[c]?\\('([A-Za-z0-9_]+)'/", $source, $matches);
            $usedCore = [...$usedCore, ...$matches[1]];
        }

        foreach (self::DYNAMIC as $prefix => $values) {
            foreach ($values as $value) {
                $usedPeople[] = $prefix . $value;
            }
        }

        $usedPeople = [...$usedPeople, ...self::CHOSEN];

        self::assertSame([], array_values(array_diff(array_unique($usedPeople), array_keys($people))), 'texts the People JS uses but _texts_people.html.twig does not have');
        self::assertSame([], array_values(array_diff(array_unique($usedCore), array_keys($core))), 'core texts the People JS uses but _texts_core.html.twig does not have');
        self::assertGreaterThan(150, count(array_unique($usedPeople)));
    }

    public function testEveryPeopleTextIsUsed(): void
    {
        $people = $this->render('participants_sheet/_texts_people.html.twig');
        $sources = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents(__DIR__ . '/../' . $file), self::PEOPLE_FILES));
        $unused = [];

        foreach (array_keys($people) as $key) {
            $dynamic = false;

            foreach (self::DYNAMIC as $prefix => $values) {
                if (str_starts_with($key, $prefix) && in_array(substr($key, strlen($prefix)), $values, true)) {
                    $dynamic = true;
                }
            }

            if (!$dynamic && !str_contains($sources, "'" . $key . "'")) {
                $unused[] = $key;
            }
        }

        self::assertSame([], $unused, 'texts in _texts_people.html.twig nothing asks for');
    }

    public function testPluralTextsCarryTheirMessageAndLocale(): void
    {
        $texts = $this->render('participants_sheet/_texts_people.html.twig');

        self::assertSame(['message' => '1 selected|%count% selected', 'locale' => 'en'], $texts['selected_count'] ?? null);
        self::assertSame('Waitlist #%position%', $texts['status_waitlisted_position'] ?? null);
        self::assertSame('Paid on %date%, before the registration was cancelled', $texts['paid_before'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(string $template): array
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
