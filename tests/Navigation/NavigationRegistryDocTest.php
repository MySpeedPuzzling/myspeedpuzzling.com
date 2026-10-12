<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\ConsoleCommands\DumpNavigationDocConsoleCommand;
use SpeedPuzzling\Web\Navigation\NavigationDocRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/navigation.md is the generated mirror of NavigationRegistry (F-01): it must be exactly what the renderer
 * produces now. A changed registry without a regenerated file - or a hand edit of the file - fails here.
 */
final class NavigationRegistryDocTest extends KernelTestCase
{
    public function testTheMirrorIsCurrent(): void
    {
        $path = __DIR__ . '/../../' . DumpNavigationDocConsoleCommand::DOC_PATH;
        self::assertFileExists($path);

        self::assertSame(
            self::getContainer()->get(NavigationDocRenderer::class)->render(),
            file_get_contents($path),
            'docs/navigation.md is out of date or was edited by hand: run bin/console myspeedpuzzling:navigation:dump-doc',
        );
    }
}
