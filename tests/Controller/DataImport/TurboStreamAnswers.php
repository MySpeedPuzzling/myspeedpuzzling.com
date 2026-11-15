<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Answers of the import's screens that are shown in place (docs/features/data-import/pairing-screens.md "Answers in
 * place"): a form sent the way Turbo - and the import's own script - sends it, and its answer read as the Turbo
 * Streams it is made of.
 */
trait TurboStreamAnswers
{
    /** What Turbo puts in `Accept` on every form it sends */
    private const array AS_TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

    /**
     * The answer just received is Turbo Streams, a 200: each stream by the id it targets.
     *
     * @return array<string, array{action: string, method: null|string, html: string, part: Crawler}>
     */
    private static function streams(KernelBrowser $browser): array
    {
        $response = $browser->getResponse();
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 2000));
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $response->headers->get('Content-Type'));

        $streams = [];
        preg_match_all('#<turbo-stream action="([a-z]+)"(?: method="([a-z]+)")? target="([^"]+)">(.*?)</turbo-stream>#s', (string) $response->getContent(), $found, PREG_SET_ORDER);

        foreach ($found as $stream) {
            $html = preg_match('#<template>(.*)</template>#s', $stream[4], $template) === 1 ? $template[1] : '';
            self::assertArrayNotHasKey($stream[3], $streams, 'One stream for a target.');
            $streams[$stream[3]] = [
                'action' => $stream[1],
                'method' => $stream[2] !== '' ? $stream[2] : null,
                'html' => $html,
                'part' => new Crawler('<html><body>' . $html . '</body></html>'),
            ];
        }

        self::assertNotSame([], $streams);
        // Every address a part holds is one of a page - never the address the form was posted to
        self::assertStringNotContainsString('return=' . $browser->getRequest()->getPathInfo(), (string) $response->getContent());

        return $streams;
    }
}
