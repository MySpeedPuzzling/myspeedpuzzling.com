<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Navigation\NavigationDocRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Developer tool: writes docs/navigation.md, the human-readable mirror of NavigationRegistry. It changes no
 * application state (no database, no message) - it only regenerates a committed file, so it does not dispatch a
 * message. Run it after every change of the registry; NavigationRegistryDocTest fails until the file is current.
 * The application never reads the file (docs/ is not in the production image).
 */
#[AsCommand('myspeedpuzzling:navigation:dump-doc', 'Regenerates docs/navigation.md from the navigation registry.')]
final class DumpNavigationDocConsoleCommand extends Command
{
    public const string DOC_PATH = 'docs/navigation.md';

    public function __construct(
        readonly private NavigationDocRenderer $renderer,
        #[Autowire('%kernel.project_dir%')]
        readonly private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->projectDir . '/' . self::DOC_PATH;

        (new Filesystem())->dumpFile($path, $this->renderer->render());

        (new SymfonyStyle($input, $output))->success(sprintf('%s written.', self::DOC_PATH));

        return self::SUCCESS;
    }
}
