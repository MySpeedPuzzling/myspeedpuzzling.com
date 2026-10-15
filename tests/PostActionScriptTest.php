<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The one-tap actions of the browse pages (docs/features/navigation GEST-15, decision 99): assets/post_action.js under
 * node (tests/post-action-harness.mjs). A tap posts the action's fields, a GET form's own fields and the CSRF token;
 * a Turbo Stream answer is applied, a redirect is rendered as a Turbo visit from the answer fetch already has (replace
 * when it comes back to this page), a page answered without a redirect shows this page again; every success clears
 * Turbo's cache and marks the document as posted; a refusal (422) or a failure plays the reject haptic and shows the
 * link's failure text, nothing else; one request per action at a time.
 */
final class PostActionScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $result) {
            self::assertFalse(is_array($result) && array_key_exists('threw', $result), $case . ': ' . json_encode($result));
        }
    }

    public function testTheContract(): void
    {
        self::assertSame([
            'ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
            'STREAM_TYPE' => 'text/vnd.turbo-stream.html',
            'TOKEN_FIELD' => '_token',
        ], self::results()['contract']);
    }

    public function testWhatAnAnswerMeans(): void
    {
        self::assertSame([
            'refused' => ['kind' => 'refused'],
            'serverError' => ['kind' => 'failed'],
            'stream' => ['kind' => 'stream'],
            'backHere' => ['kind' => 'visit', 'url' => 'https://msp.test/en/puzzle?brand=x#list', 'action' => 'replace'],
            'elsewhere' => ['kind' => 'visit', 'url' => 'https://msp.test/en/compare', 'action' => 'advance'],
            'pageWithoutRedirect' => ['kind' => 'refresh'],
        ], self::results()['outcomes']);
    }

    public function testTheBody(): void
    {
        self::assertSame([
            'linkFields' => [['context', 'detail'], ['collectionId', ''], ['_token', 'tok']],
            // A GET form's own fields - never its token field, never a nameless one
            'formFields' => [['country', 'cz'], ['back', '/en/puzzlers'], ['_token', 'tok']],
            'noToken' => [['subject', 'p-1']],
        ], self::results()['body']);
    }

    public function testAStreamAnswerIsApplied(): void
    {
        self::assertSame([
            'kind' => 'stream',
            'fetch' => [[
                'url' => '/en/wishlist/1/remove',
                'method' => 'POST',
                'accept' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
                'credentials' => 'same-origin',
                'body' => [['context', 'detail']],
            ]],
            'streams' => ['<turbo-stream action="replace"></turbo-stream>'],
            'visits' => [],
            'clears' => 1,
            'posts' => 1,
            'haptics' => [],
            'toasts' => [],
        ], self::results()['stream answer']);
    }

    public function testARedirectIsRenderedFromTheAnswer(): void
    {
        /** @var array{kind: string, visits: list<mixed>, clears: int, posts: int} $here */
        $here = self::results()['redirect back here'];

        self::assertSame('visit', $here['kind']);
        self::assertSame([[
            'url' => 'https://msp.test/en/puzzle?brand=x',
            'action' => 'replace',
            // Like a Turbo POST form: no copy of the page from before the tap
            'shouldCacheSnapshot' => false,
            'response' => ['statusCode' => 200, 'redirected' => true, 'responseHTML' => '<html>page</html>'],
        ]], $here['visits']);
        self::assertSame([1, 1], [$here['clears'], $here['posts']]);

        self::assertSame([
            'kind' => 'visit',
            'visits' => [[
                'url' => 'https://msp.test/en/puzzlers?scope=cz',
                'action' => 'advance',
                'shouldCacheSnapshot' => false,
                'response' => ['statusCode' => 200, 'redirected' => true, 'responseHTML' => '<html>players</html>'],
            ]],
            'clears' => 1,
            'posts' => 1,
        ], self::results()['redirect elsewhere']);
    }

    public function testARefusalOrAFailureChangesNothing(): void
    {
        self::assertSame([
            'kind' => 'refused',
            'fetch' => 1,
            'streams' => [],
            'visits' => [],
            'clears' => 0,
            'posts' => 0,
            'haptics' => ['reject'],
            'toasts' => ['That did not work.'],
        ], self::results()['refused']);

        self::assertSame([
            'kind' => 'failed',
            'haptics' => ['reject'],
            'toasts' => ['That did not work.'],
            'clears' => 0,
        ], self::results()['network failure']);
    }

    public function testOneRequestPerActionAtATime(): void
    {
        self::assertSame(['second' => 'busy', 'firstKind' => 'stream', 'third' => 'stream', 'fetches' => 2], self::results()['one request at a time']);
    }

    /**
     * Instant Back review F9: the fourteen actions were <button>s - their links say role="button" and Space taps them as
     * it does a button (post_action_controller.js), never anything else
     */
    public function testSpaceTapsTheLinkAsItDidTheButton(): void
    {
        self::assertSame([
            'keydown' => 'prevent',
            'keyup' => 'activate',
            'oldSpacebar' => 'activate',
            'enter' => null,
            'letter' => null,
            'withShift' => null,
            'withCtrl' => null,
            'composing' => null,
            'keypress' => null,
            'nothing' => null,
        ], self::results()['space activates']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/post-action-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
