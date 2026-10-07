<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A secret competition puzzle's automatic reveal is the round's start plus the round's reveal delay, computed in exactly
 * one place: RoundPuzzleReveal (revealAt()/automaticRevealAt() in PHP, sqlRevealAt()/sqlHidden() in SQL). Anything
 * else adding minutes to a round start - a hard-coded "+10 minutes", an INTERVAL, its own make_interval(), the delay
 * glued into a modifier, a timestamp plus seconds, a Twig or JavaScript date shifted by hand - would reveal on its own
 * clock, which is how a puzzle comes out early. A new match fails this test until its author has decided (use
 * RoundPuzzleReveal, or - for a match that is unrelated date code, a false positive the broad patterns accept on
 * purpose - allow-list the file with a reason saying so); an entry whose file no longer matches fails it too, so the
 * list only shrinks.
 *
 * The patterns are scoped to what such code must touch - a round start (startsAt / starts_at), the delay
 * (revealDelayMinutes / reveal_delay_minutes) or an amount of minutes - so unrelated date code (a stopwatch, a token
 * expiry in hours or seconds) passes.
 */
final class RoundRevealMomentComputedOnlyHereTest extends TestCase
{
    private const array ALLOWED = [
        'src/Value/RoundPuzzleReveal.php' => 'The one place: PHP and SQL of the reveal moment.',
    ];

    /**
     * A round start, as PHP, Twig, SQL or JavaScript names it: startsAt, roundStartsAt, starts_at, round_starts_at,
     * startAt, a Stimulus startsAtValue (never startedAt - a stopwatch).
     */
    private const string START = '(?:starts?_?at\w*)';

    /**
     * The round's reveal delay.
     */
    private const string DELAY = '(?:revealDelayMinutes|reveal_delay_minutes)';

    /**
     * What computes a moment from a round start, from the delay or from minutes.
     */
    private const array PATTERNS = [
        // cr.starts_at + INTERVAL …, cr.starts_at + make_interval(…), INTERVAL '10 minutes' + cr.starts_at
        'SQL arithmetic on a start' => '/\bstarts_at\s*[+-]|\+\s*(?:\w+\.)?starts_at\b/i',
        // $round->startsAt->modify(…) / ->add(…) / ->sub(…) in PHP, round.startsAt.modify(…) / .add(…) in Twig or JS
        'date arithmetic on a start' => '/' . self::START . '\)?\s*(?:->|\.)\s*(?:add|sub|modify|setTimestamp)\s*\(/i',
        // $round->startsAt->getTimestamp() + …, round.startsAt.timestamp + …, round.startsAt|date('U') + …
        'timestamp arithmetic on a start' => '/' . self::START . '\s*(?:->|\.)\s*(?:getTimestamp\s*\(\s*\)|timestamp)\s*[+-]|' . self::START . '\s*\|\s*date\s*\(\s*[\'"]U[\'"]\s*\)\s*[+-]/i',
        'Twig arithmetic on a start' => '/' . self::START . '\s*\|\s*date_modify/i',
        // make_interval(…) anywhere, INTERVAL '10 minutes', '10 minutes'::interval
        'an interval of minutes' => '/make_interval\s*\(|INTERVAL\s*\'\s*\d+\s*min|\'\s*\d+\s*min\w*\s*\'\s*::\s*interval/i',
        // new DateInterval('PT10M') / ('PT%dM') / ('PT' . $x . 'M') / ("PT{$x}M") / (sprintf('PT…')), '+10 minutes',
        // '+%d min', '+%s minutes', "+{$delay} minutes"
        'a DateInterval or modifier of minutes' => '/PT%?\d*[dM]?M[\'"]|new\s+\\\\?DateInterval\s*\(\s*(?:[\'"]PT[\'"]\s*\.|"PT\{?\$|sprintf\s*\()|[\'"][+-]\s*(?:%[sd]|\d+|\{?\$[\w>-]+\}?)\s*min/i',
        // the delay multiplied or added: $round->revealDelayMinutes * 60, 60 * $round->revealDelayMinutes,
        // cr.reveal_delay_minutes * INTERVAL '1 minute', … + reveal_delay_minutes
        'arithmetic with the reveal delay' => '/' . self::DELAY . '\b\s*(?:[*+]|-\s*[\d$(])|(?<=[\w)\]\'"])\s*[*+]\s*(?:\(int\)\s*)?(?:\$?\w+(?:->\w+)*(?:->|\.))?' . self::DELAY . '\b/i',
        // the delay glued into a modifier or an interval: SQL (cr.reveal_delay_minutes || ' minutes')::interval, Twig
        // '+' ~ round.revealDelayMinutes ~ ' minutes', PHP '+' . $round->revealDelayMinutes . ' minutes'
        'the reveal delay concatenated' => '/' . self::DELAY . '\b\s*\)?\s*(?:\|\||~|\.\s*[\'"])|(?:\|\||~|[\'"]\s*\.)\s*\(?\s*(?:\$?[\w.]+(?:->\w+)*(?:->|\.))?' . self::DELAY . '\b/i',
        // JavaScript: start.getTime() + delay * 60000 (or * 60 * 1000), date.setMinutes(date.getMinutes() + …)
        'JavaScript minutes added to a time' => '/(?:getTime|valueOf)\s*\(\s*\)\s*\+[^;\n]*?(?:\*\s*60\s*\*\s*1000|\*\s*60_?000\b|\*\s*6e4\b|\b60_?000\s*\*|\b60\s*\*\s*1000\s*\*)|setMinutes\s*\([^;\n]*getMinutes\s*\(\s*\)\s*\+/i',
    ];

    public function testTheRevealMomentIsComputedOnlyByRoundPuzzleReveal(): void
    {
        $projectDir = dirname(__DIR__);
        $undecided = [];
        $staleAllowlist = self::ALLOWED;

        $files = (new Finder())
            ->in([$projectDir . '/src', $projectDir . '/templates', $projectDir . '/assets'])
            ->files()
            ->name(['*.php', '*.twig', '*.js', '*.mjs', '*.ts']);

        foreach ($files as $file) {
            $matched = self::matchedPatterns($file->getContents());

            if ($matched === []) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($projectDir) + 1);

            if (array_key_exists($path, self::ALLOWED)) {
                unset($staleAllowlist[$path]);

                continue;
            }

            $undecided[] = sprintf('%s (%s)', $path, implode(', ', $matched));
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files match a pattern of computing a moment from a round start, the reveal delay or minutes - a secret puzzle\'s reveal comes only from RoundPuzzleReveal (revealAt(), automaticRevealAt(), sqlRevealAt(), sqlHidden()). Use it. The patterns are broad on purpose and accept false positives: a match that computes no reveal moment (other date code that happens to match) goes into ALLOWED with a reason saying why it is unrelated - never loosen a pattern for it.');
        self::assertSame([], array_keys($staleAllowlist), 'Allow-listed files that no longer compute such a moment - remove them from the list.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysToWriteAnEarlyReveal(): iterable
    {
        // SQL
        yield 'SQL: start + INTERVAL' => ["cr.starts_at + INTERVAL '10 minutes'"];
        yield 'SQL: start + make_interval' => ['cr.starts_at + make_interval(mins => 15)'];
        yield 'SQL: start + interval, no space' => ["starts_at+interval '25 minutes'"];
        yield 'SQL: interval + start' => ["INTERVAL '10 minutes' + cr.starts_at"];
        yield 'SQL: a minutes literal cast' => ["cr.starts_at + '10 minutes'::interval"];
        yield 'SQL: the delay concatenated into an interval' => ["cr.starts_at + (cr.reveal_delay_minutes || ' minutes')::interval"];
        yield 'SQL: an interval concatenated from the delay' => ["('' || cr.reveal_delay_minutes || ' minutes')::interval"];
        yield 'SQL: the delay times an interval' => ["cr.reveal_delay_minutes * INTERVAL '1 minute'"];
        yield 'SQL: an interval times the delay' => ["INTERVAL '1 minute' * cr.reveal_delay_minutes"];
        yield 'SQL: make_interval of the delay' => ['make_interval(mins => cr.reveal_delay_minutes)'];
        // PHP
        yield 'PHP: modify a start' => ["\$round->startsAt->modify('+10 minutes')"];
        yield 'PHP: modify a start by the delay' => ["\$round->startsAt->modify(sprintf('+%s minutes', \$round->revealDelayMinutes))"];
        yield 'PHP: a modifier of %s minutes on any moment' => ["\$start->modify(sprintf('+%s minutes', \$delay))"];
        yield 'PHP: a modifier of %d minutes' => ["sprintf('+%d minutes', \$round->revealDelayMinutes)"];
        yield 'PHP: a modifier with an interpolated delay' => ['$start->modify("+{$delay} minutes")'];
        yield 'PHP: a modifier with an interpolated property' => ['$start->modify("+$round->revealDelayMinutes minutes")'];
        yield 'PHP: add a DateInterval to a start' => ["\$roundStartsAt->add(new DateInterval('PT10M'))"];
        yield 'PHP: a DateInterval concatenated' => ["new DateInterval('PT' . \$minutes . 'M')"];
        yield 'PHP: a DateInterval interpolated' => ['new DateInterval("PT{$minutes}M")'];
        yield 'PHP: a DateInterval interpolated, no braces' => ['new \DateInterval("PT$minutes" . "M")'];
        yield 'PHP: a DateInterval from sprintf' => ["new DateInterval(sprintf('PT%sM', \$minutes))"];
        yield 'PHP: a timestamp plus seconds' => ['$round->startsAt->getTimestamp() + $round->revealDelayMinutes * 60'];
        yield 'PHP: a timestamp plus a constant' => ['$startsAt->getTimestamp() + 600'];
        yield 'PHP: a start set from a timestamp' => ['$startsAt->setTimestamp($t)'];
        yield 'PHP: the delay times 60' => ['$round->revealDelayMinutes * 60'];
        yield 'PHP: 60 times the delay' => ['60 * $round->revealDelayMinutes'];
        yield 'PHP: the delay concatenated into a modifier' => ["'+' . \$round->revealDelayMinutes . ' minutes'"];
        yield 'PHP: any moment + 10 minutes' => ["\$revealAt->modify('+10 minutes')"];
        // Twig
        yield 'Twig: date_modify on a start' => ["{{ round.startsAt|date_modify('+10 minutes') }}"];
        yield 'Twig: modify a start' => ["{{ round.startsAt.modify('+10 minutes')|date }}"];
        yield 'Twig: modify a start by the delay' => ["{% set reveal = round.startsAt.modify('+' ~ round.revealDelayMinutes ~ ' minutes') %}"];
        yield 'Twig: add to a start' => ['{% set reveal = round.startsAt.add(interval) %}'];
        yield 'Twig: the delay concatenated' => ["{% set modifier = '+' ~ round.revealDelayMinutes ~ ' minutes' %}"];
        yield 'Twig: the delay concatenated first' => ["{% set modifier = round.reveal_delay_minutes ~ ' minutes' %}"];
        yield 'Twig: a start timestamp plus seconds' => ['{{ round.startsAt.timestamp + 600 }}'];
        yield 'Twig: a start as a Unix time plus seconds' => ["{{ round.startsAt|date('U') + delay * 60 }}"];
        // JavaScript
        yield 'JS: a start plus minutes in milliseconds' => ['new Date(startsAt.getTime() + delayMinutes * 60000)'];
        yield 'JS: a start plus minutes, 60 * 1000' => ['const revealAt = start.getTime() + this.delayValue * 60 * 1000;'];
        yield 'JS: valueOf plus minutes' => ['start.valueOf() + 60000 * minutes'];
        yield 'JS: setMinutes' => ['reveal.setMinutes(reveal.getMinutes() + delay)'];
        yield 'JS: a start shifted by a method' => ['const reveal = dayjs(this.startsAtValue).add(delay, "minute")'];
    }

    #[DataProvider('waysToWriteAnEarlyReveal')]
    public function testThePatternsCatchTheWaysAnEarlyRevealCouldBeWritten(string $snippet): void
    {
        self::assertNotSame([], self::matchedPatterns($snippet), $snippet);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function innocentCode(): iterable
    {
        yield 'PHP: a start formatted' => ["\$round->startsAt->format('c')"];
        yield 'PHP: the reveal through RoundPuzzleReveal' => ['RoundPuzzleReveal::automaticRevealAt($round->startsAt, $round->revealDelayMinutes)'];
        yield 'PHP: the delay passed on' => ['$round->changeRevealDelay($message->revealDelayMinutes ?? $round->revealDelayMinutes);'];
        yield 'PHP: the delay compared' => ['if ($revealDelayMinutes !== $round->revealDelayMinutes) {'];
        yield 'PHP: the delay printed' => ["sprintf('reveal delay %d minutes -> %d', \$round->revealDelayMinutes, 10)"];
        yield 'PHP: a stopwatch resumed' => ["\$this->stopwatchStartedAt->modify(sprintf('+%d seconds', \$pauseSeconds))"];
        yield 'PHP: a token expiry in hours' => ["\$now->modify('+1 hour')"];
        yield 'PHP: a DateInterval of days' => ["new DateInterval('P1D')"];
        yield 'PHP: a DateInterval of seconds' => ["new DateInterval('PT30S')"];
        yield 'PHP: one second back' => ["\$revealAt->modify('-1 second')"];
        yield 'SQL: a start read' => ['cr.starts_at AS round_starts_at'];
        yield 'SQL: ordered by start' => ['ORDER BY cr.starts_at, cr.id'];
        yield 'SQL: a start compared' => ['WHERE cr.starts_at <= :now'];
        yield 'SQL: the delay read' => ['cr.reveal_delay_minutes AS round_reveal_delay_minutes'];
        yield 'SQL: the delay compared' => ['WHERE reveal_delay_minutes <> :default'];
        yield 'Twig: the delay as a translation count' => ["{{ 'competition.reveal.after_start'|trans({'%count%': round.revealDelayMinutes}) }}"];
        yield 'Twig: a start shown' => ['{{ zoned_datetime(round.startsAt, round.timezone) }}'];
        yield 'Twig: a start as a data attribute' => ['data-starts-at="{{ round.startsAt|date(\'c\') }}"'];
        yield 'JS: a countdown' => ['const remaining = this.startsAtValue - Date.now();'];
        yield 'JS: a time in milliseconds' => ['const elapsed = Date.now() - startedAt.getTime();'];
        yield 'JS: a timeout of a minute' => ['setTimeout(refresh, 60000);'];
    }

    #[DataProvider('innocentCode')]
    public function testThePatternsLeaveUnrelatedCodeAlone(string $snippet): void
    {
        self::assertSame([], self::matchedPatterns($snippet), $snippet);
    }

    /**
     * @return list<string> the names of the patterns the code matches
     */
    private static function matchedPatterns(string $code): array
    {
        $matched = [];

        foreach (self::PATTERNS as $what => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $matched[] = $what;
            }
        }

        return $matched;
    }
}
