<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * In-page confirmations (assets/confirm_dialog.js, docs/features/navigation GEST-13, decision 60): MSP's own modal
 * <dialog> instead of window.confirm - Cancel left, action right, danger style, Esc / scrim / Cancel answer false, focus
 * back on the trigger, inside an open Bootstrap modal, never a history layer (leaving the page answers false);
 * Turbo.config.forms.confirm; guardLeave() for the synchronous leave guards; the watchdog that reports a native confirm
 * an iPhone refused (decision 102: afterRestore = loaded by a traverse until GEST-15).
 *
 * Runs the real module under node in jsdom with the real <template> of templates/base.html.twig
 * (tests/confirm-dialog-harness.mjs).
 */
final class ConfirmDialogScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach ($this->results() as $case => $facts) {
            self::assertIsArray($facts, $case);
            self::assertArrayNotHasKey('threw', $facts, $case . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
        }
    }

    public function testTheActionAnswersTrueAndTheFocusGoesBack(): void
    {
        $facts = $this->case('action resolves true');

        self::assertSame([
            'parent' => 'body',
            'open' => true,
            'role' => 'alertdialog',
            'labelledByMessage' => true,
            // Plain text, line breaks kept (CSS white-space: pre-line)
            'message' => "Delete this stopwatch?\nIt cannot be undone.",
            // The labels of the template in base.html.twig
            'cancel' => 'Cancel (template)',
            'action' => 'Confirm (template)',
            'actionClass' => 'btn-primary',
            // Cancel left, action right
            'buttons' => ['cancel', 'action'],
            'focused' => 'action',
        ], $facts['opened']);
        self::assertTrue($facts['answer']);
        self::assertSame(['showModal', 'close'], $facts['calls']);
        self::assertSame(0, $facts['left']);
        self::assertSame('trigger', $facts['focus']);
        self::assertFalse($facts['isOpen']);
    }

    public function testCancelAnswersFalse(): void
    {
        self::assertSame(['answer' => false, 'left' => 0, 'focus' => 'trigger'], $this->case('cancel resolves false'));
    }

    public function testEscapeAnswersFalseBeforeThePageOrTheLayerHelperSeesIt(): void
    {
        self::assertSame([
            'answer' => false,
            'prevented' => true,
            // A page's own Escape (live results) and the layer helper's never run while the question is open
            'seenWhileOpen' => [],
            'seenAfter' => ['document capture', 'document bubble'],
            'focus' => 'trigger',
        ], $this->case('escape resolves false before anybody else sees it'));

        // The dialog's own close request (Esc, Android Back gesture)
        self::assertSame(['answer' => false, 'prevented' => true, 'left' => 0], $this->case('cancel event resolves false'));
    }

    public function testATapOnTheScrimAnswersFalse(): void
    {
        self::assertSame(['afterDrag' => 'pending', 'afterBox' => 'pending', 'answer' => false], $this->case('scrim resolves false'));
    }

    public function testDangerStyleLabelAndFocus(): void
    {
        $facts = $this->case('danger style, labels and focus');

        // Destructive: the safe answer has the focus
        self::assertSame(['actionClass' => 'btn-danger', 'action' => 'Remove', 'focused' => 'cancel'], self::pick($facts['danger'], ['actionClass', 'action', 'focused']));
        self::assertSame(['actionClass' => 'btn-primary', 'action' => 'Confirm (template)', 'focused' => 'action'], self::pick($facts['plain'], ['actionClass', 'action', 'focused']));
        self::assertSame(1, $facts['dialogs']);

        self::assertSame(['first' => false, 'second' => 'pending', 'dialogs' => 1, 'message' => 'Second?'], $this->case('a newer question replaces an open one'));
    }

    public function testInsideAnOpenBootstrapModalTheDialogIsAppendedToIt(): void
    {
        self::assertSame(['aroundTheTrigger' => 'report', 'lastOpenModal' => 'other', 'noModal' => 'body'], $this->case('inside an open Bootstrap modal'));
        self::assertSame(['afterOther' => 'pending', 'answer' => false, 'left' => 0], $this->case('the modal around it hiding resolves false'));
    }

    public function testItIsNoHistoryLayerLeavingThePageAnswersFalse(): void
    {
        self::assertSame([
            'popstate' => ['answer' => false, 'left' => 0],
            'turbo:before-visit' => ['answer' => false, 'left' => 0],
            'turbo:before-cache' => ['answer' => false, 'left' => 0],
        ], $this->case('leaving the page resolves false'));
    }

    public function testTurboAsksThroughTheDialog(): void
    {
        $facts = $this->case('Turbo confirm method');

        self::assertTrue($facts['installed']);
        // data-turbo-confirm-danger on the form, the label from the submitter's data-turbo-confirm-action
        self::assertSame(['message' => 'Revoke?', 'action' => 'Revoke access', 'actionClass' => 'btn-danger'], self::pick($facts['formDanger'], ['message', 'action', 'actionClass']));
        self::assertFalse($facts['byForm']);
        self::assertSame('revoke-submit', $facts['focusAfterCancel']);
        // ... or on the submitter itself
        self::assertSame(['message' => 'Block?', 'action' => 'Block', 'actionClass' => 'btn-danger'], self::pick($facts['submitterDanger'], ['message', 'action', 'actionClass']));
        self::assertTrue($facts['bySubmitter']);
        self::assertSame('block-submit', $facts['focusAfterAction']);
        // No submitter (Enter in a field): the form's submit button
        self::assertSame(['action' => 'Confirm (template)', 'actionClass' => 'btn-primary'], self::pick($facts['plain'], ['action', 'actionClass']));
        self::assertFalse($facts['noSubmitter']);
        self::assertSame('convert-submit', $facts['focusWithoutSubmitter']);
    }

    public function testALeaveGuardAsksAndVisitsAgainOnlyOnYes(): void
    {
        $facts = $this->case('guardLeave asks and visits again only on yes');

        self::assertTrue($facts['firstPrevented']);
        self::assertSame(['message' => 'Leave? Unsaved changes are lost.', 'actionClass' => 'btn-danger', 'focused' => 'cancel'], self::pick($facts['asked'], ['message', 'actionClass', 'focused']));
        self::assertSame(0, $facts['visitsAfterNo']);
        self::assertTrue($facts['secondPrevented']);
        // After the yes: exactly one visit, through the layer helper, let through by the guard
        self::assertSame([['url' => 'https://msp.test/en/events', 'prevented' => false]], $facts['visits']);
        self::assertSame([false, false, true], $facts['guardResults']);
        self::assertFalse($facts['cleanPrevented']);
        self::assertSame(0, $facts['dialogs']);

        self::assertSame(['visited' => ['https://msp.test/en/hub']], $this->case('leaveTo without the layer helper'));
    }

    public function testWithoutTheTemplateTheDialogStillAsksInThePage(): void
    {
        $facts = $this->case('fallback dialog without the template');

        self::assertSame(['cancel' => 'Cancel', 'action' => 'OK', 'actionClass' => 'btn-danger', 'buttons' => ['cancel', 'action']], self::pick($facts['facts'], ['cancel', 'action', 'actionClass', 'buttons']));
        self::assertTrue($facts['answer']);

        self::assertSame(['images' => 0, 'message' => 'Remove <img src=x onerror=alert(1)> from the referees?'], $this->case('message is plain text'));
    }

    public function testTheWatchdogReportsARefusedNativeConfirmOnIphonesOncePerPageView(): void
    {
        $facts = $this->case('watchdog');
        $refused = [false, false, false];
        $asked = ['Approve this application?', 'Again?', 'After a visit?'];

        // One report per page view (a Turbo visit starts the next), the native answer passed on untouched
        self::assertSame(['answers' => $refused, 'native' => $asked, 'reports' => [
            ['afterLayerBack' => true, 'afterRestore' => false],
            ['afterLayerBack' => true, 'afterRestore' => false],
        ]], $facts['ios refused at once']);
        self::assertSame(['reports' => [['afterLayerBack' => false, 'afterRestore' => true], ['afterLayerBack' => false, 'afterRestore' => true]]], self::pick($facts['ios refused at 50 ms'], ['reports']));
        // GEST-15 hands in its restore kind
        self::assertSame(['reports' => [['afterLayerBack' => false, 'afterRestore' => 'snapshot'], ['afterLayerBack' => false, 'afterRestore' => 'snapshot']]], self::pick($facts['GEST-15 restore kind'], ['reports']));

        foreach (['ios answered after 300 ms', 'ios confirmed at once', 'android refused at once', 'desktop refused at once', 'ios inside the app'] as $name) {
            self::assertSame(['reports' => [], 'native' => $asked], self::pick($facts[$name], ['reports', 'native']), $name);
        }

        self::assertSame(['answers' => [true, true, true]], self::pick($facts['ios confirmed at once'], ['answers']));
    }

    public function testTheWatchdogDecisionAndTheRestoreFlag(): void
    {
        self::assertSame([
            'refusedAtOnce' => true,
            'atTheLimit' => true,
            'justOver' => false,
            'confirmed' => false,
            'alreadyReported' => false,
            'android' => false,
            'other' => false,
            'nativeApp' => false,
            'noTiming' => false,
        ], $this->case('reportsSuppressed matrix'));

        self::assertSame([
            'traverse' => true,
            'push' => false,
            'reload' => false,
            'noActivation' => false,
            'noNavigationApi' => false,
            'throws' => false,
        ], $this->case('loadedByTraverse until GEST-15'));

        self::assertSame(['templateId' => 'msp-confirm-template', 'within' => 50], $this->case('constants'));
    }

    /**
     * The template the module clones is rendered on every page (web and app), outside every block a page overrides,
     * with its labels from the translations.
     */
    public function testBaseRendersTheTemplateOutsideTheOverridableBlocks(): void
    {
        $base = (string) file_get_contents(__DIR__ . '/../templates/base.html.twig');
        $start = strpos($base, '<template id="msp-confirm-template">');

        self::assertNotFalse($start);
        self::assertSame(1, substr_count($base, 'id="msp-confirm-template"'));
        self::assertStringContainsString("{{ 'confirm_dialog.cancel'|trans }}", $base);
        self::assertStringContainsString("{{ 'confirm_dialog.confirm'|trans }}", $base);

        // Every {% block %} opened before it is closed before it: no page can empty it (participants sheet, stopwatch)
        $before = substr($base, 0, $start);
        self::assertSame(
            preg_match_all('~\{%-?\s*block\s+\w+\s*-?%\}~', $before),
            preg_match_all('~\{%-?\s*endblock(?:\s+\w+)?\s*-?%\}~', $before),
            'the confirm template sits inside a {% block %} a page may override',
        );
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function pick(mixed $facts, array $keys): array
    {
        self::assertIsArray($facts);
        $picked = [];

        foreach ($keys as $key) {
            $picked[$key] = $facts[$key] ?? null;
        }

        return $picked;
    }

    /**
     * @return array<mixed>
     */
    private function case(string $name): array
    {
        $facts = $this->results()[$name] ?? null;
        self::assertIsArray($facts, $name . ' has no result in tests/confirm-dialog-harness.mjs');
        self::assertArrayNotHasKey('threw', $facts, $name);

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        if (self::$results === null) {
            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

            $process = new Process([$node, '--no-warnings', __DIR__ . '/confirm-dialog-harness.mjs']);
            $process->mustRun();

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results = $decoded;
        }

        return self::$results;
    }
}
