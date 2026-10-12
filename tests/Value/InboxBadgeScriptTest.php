<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use SpeedPuzzling\Web\Twig\BrowserTranslationTwigExtension;
use SpeedPuzzling\Web\Value\InboxBadgeParts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A realtime push changes the Inbox badge in the browser (assets/inbox_badge.js) after the server rendered it from
 * InboxBadgeParts (docs/features/navigation INB-02): both must show the same numbers, open the same tab and say the
 * same accessible name, in every locale - or a push would change what the page said for the same numbers. Runs the
 * real module under node.
 */
final class InboxBadgeScriptTest extends KernelTestCase
{
    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    private const array PARTS = [
        [0, 0, 0], [0, 1, 0], [0, 0, 1], [0, 0, 3], [0, 2, 1], [1, 0, 0], [2, 0, 0], [3, 0, 0], [5, 0, 0],
        [1, 1, 0], [1, 0, 1], [4, 3, 2], [99, 0, 0], [99, 1, 0], [150, 0, 0], [0, 98, 3], [21, 0, 1],
    ];

    private const array TARGETS = [
        'notifications' => 'for_you',
        'conversations_list' => 'messages',
        'conversations_list?tab=requests' => 'requests',
    ];

    public function testTheBrowserShowsWhatTheServerRendered(): void
    {
        self::bootKernel();
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $browserTranslation = self::getContainer()->get(BrowserTranslationTwigExtension::class);

        $cases = [];
        $expected = [];

        foreach (self::LOCALES as $locale) {
            $labels = [];

            foreach (['none', 'messages', 'for_you', 'mixed'] as $kind) {
                $labels[$kind] = $browserTranslation->browserTranslation('inbox.badge.name.' . $kind, locale: $locale);
            }

            foreach (self::PARTS as [$forYou, $conversations, $requests]) {
                $parts = new InboxBadgeParts($forYou, $conversations, $requests);
                $target = $parts->target();
                $targetKey = $target['route'] . ($target['params'] === [] ? '' : '?' . http_build_query($target['params']));

                $cases[] = ['forYou' => $forYou, 'conversations' => $conversations, 'requests' => $requests, 'labels' => $labels];
                $expected[] = sprintf(
                    '%s %d/%d/%d: %s %s %s → %s "%s"',
                    $locale,
                    $forYou,
                    $conversations,
                    $requests,
                    $parts->display(),
                    $parts->display(InboxBadgeParts::PART_FOR_YOU),
                    $parts->display(InboxBadgeParts::PART_MESSAGES),
                    self::TARGETS[$targetKey],
                    $translator->trans('inbox.badge.name.' . $parts->labelKind(), ['%count%' => $parts->labelCount()], null, $locale),
                );
            }
        }

        $actual = [];

        foreach ($this->runInNode($cases) as $index => $result) {
            $actual[] = sprintf(
                '%s %d/%d/%d: %s %s %s → %s "%s"',
                self::LOCALES[intdiv($index, count(self::PARTS))],
                $cases[$index]['forYou'],
                $cases[$index]['conversations'],
                $cases[$index]['requests'],
                $result['total'],
                $result['for_you'],
                $result['messages'],
                $result['target'],
                $result['label'] ?? 'null',
            );
        }

        self::assertSame($expected, $actual);
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<array{total: string, for_you: string, messages: string, target: string, label: null|string}>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/../inbox-badge-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{total: string, for_you: string, messages: string, target: string, label: null|string}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
