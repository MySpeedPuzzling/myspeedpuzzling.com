<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Live Component requests the way live_controller.js sends them: the props of the last render plus the models the browser
 * re-sends on its own. TestLiveComponent::call() sends neither, so a test through it cannot see a prop hook running on
 * every real request - "Show more" stuck on its second page, on Compare (2026-10-03) and on the puzzle leaderboard
 * (2026-10-09).
 */
trait LiveComponentBrowserRequests
{
    /**
     * $root is the component's root element of the last render (the page or the previous response). Sent like the browser
     * does: an action as a POST to the component's URL + action, a plain re-render (a model changed, $updated) as a POST
     * to the URL - or as a GET where the component asks for it (`method: 'get'`).
     *
     * @param array<string, mixed> $updated the models the visitor changed (a ticked filter)
     * @param array<string, mixed> $args
     */
    private function browserLiveRequest(KernelBrowser $client, Crawler $root, null|string $action = null, array $updated = [], array $args = []): Crawler
    {
        $url = (string) $root->attr('data-live-url-value');
        self::assertNotSame('', $url, 'Not the root element of a live component');
        $props = self::renderedLiveProps($root);
        $updated = [...self::modelsTheBrowserResends($root), ...$updated];
        $headers = ['HTTP_ACCEPT' => 'application/vnd.live-component+html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        if ($action === null && $root->attr('data-live-request-method-value') === 'get') {
            $client->request('GET', $url, [
                'props' => json_encode($props, JSON_THROW_ON_ERROR),
                'updated' => json_encode((object) $updated, JSON_THROW_ON_ERROR),
            ], server: $headers);
        } elseif ($action === null) {
            $client->request('POST', $url, [
                'data' => json_encode(['props' => $props, 'updated' => (object) $updated], JSON_THROW_ON_ERROR),
            ], server: $headers);
        } else {
            $client->request('POST', $url . '/' . rawurlencode($action), [
                'data' => json_encode(['props' => $props, 'updated' => (object) $updated, 'args' => (object) $args], JSON_THROW_ON_ERROR),
            ], server: $headers);
        }

        self::assertResponseIsSuccessful();

        return new Crawler((string) $client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * What live_controller.js (synchronizeValueOfModelFields) marks as changed after a render without anybody touching a
     * thing: it writes each prop into its non-multiple <select data-model> - `${value}`, so null is "null" - and reads the
     * select back; a value no option has leaves the browser on the first option, and anything !== the prop is re-sent.
     *
     * @return array<string, string>
     */
    private static function modelsTheBrowserResends(Crawler $root): array
    {
        $props = self::renderedLiveProps($root);
        $updated = [];

        foreach ($root->filter('select[data-model]:not([multiple])') as $select) {
            assert($select instanceof \DOMElement);
            $directive = $select->getAttribute('data-model');
            $model = substr($directive, (int) strrpos('|' . $directive, '|'));
            $prop = $props[$model] ?? null;
            $written = match (true) {
                $prop === null => 'null',
                is_bool($prop) => $prop ? 'true' : 'false',
                is_scalar($prop) => (string) $prop,
                default => '',
            };
            $options = (new Crawler($select))->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
            $value = in_array($written, $options, true) ? $written : ($options[0] ?? '');

            if ($value !== $prop) {
                $updated[$model] = $value;
            }
        }

        return $updated;
    }

    /**
     * @return array<mixed>
     */
    private static function renderedLiveProps(Crawler $root): array
    {
        $props = json_decode((string) $root->attr('data-live-props-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($props);

        return $props;
    }
}
