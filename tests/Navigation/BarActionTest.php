<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\BarAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * data-bar-action values come from BarAction through bar_action() (NAV-14): a known tool renders its value, a typo
 * fails at render instead of shipping a value the native app's path configuration does not know.
 */
final class BarActionTest extends KernelTestCase
{
    public function testKnownToolsRenderTheirValue(): void
    {
        $twig = self::getContainer()->get(Environment::class);

        foreach (BarAction::cases() as $action) {
            self::assertSame($action->value, $twig->createTemplate(sprintf("{{ bar_action('%s') }}", $action->value))->render());
        }
    }

    public function testATypoFailsAtRender(): void
    {
        $this->expectException(RuntimeError::class);

        self::getContainer()->get(Environment::class)->createTemplate("{{ bar_action('serch') }}")->render();
    }
}
