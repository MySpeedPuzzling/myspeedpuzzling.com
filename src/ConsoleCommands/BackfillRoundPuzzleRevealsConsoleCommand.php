<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use SpeedPuzzling\Web\Services\ImageCachePurgeList;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One-off after the reveal model (docs/features/competitions-management/README.md "Hide Until Round Starts"): marks
 * the round puzzles whose puzzle the round created, and sets the puzzle's site-wide hide dates to the reveal moment.
 * Lists what it does, what already exists on those puzzles and which cached picture URLs to purge; changes nothing
 * without --write. The old picture objects are deleted after the commit (DeleteObsoletePuzzleImage, async).
 */
#[AsCommand('myspeedpuzzling:backfill-round-puzzle-reveals')]
final class BackfillRoundPuzzleRevealsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private ImageCachePurgeList $imageCachePurgeList,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Apply the changes (default: dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = $input->getOption('write') === true;
        $envelope = $this->messageBus->dispatch(new BackfillRoundPuzzleReveals(dryRun: !$write));

        /** @var null|array{changes: list<string>, unmatched: list<string>, eventPageOnly: list<string>, records: list<string>, obsoleteImages: list<string>} $result */
        $result = $envelope->last(HandledStamp::class)?->getResult();
        $changes = $result['changes'] ?? [];
        $obsoleteImages = $result['obsoleteImages'] ?? [];
        $records = $result['records'] ?? [];
        $eventPageOnly = $result['eventPageOnly'] ?? [];
        $unmatched = $result['unmatched'] ?? [];

        $io = new SymfonyStyle($input, $output);
        $io->section('Round puzzles that created their puzzle and reveal later');
        $io->listing($changes === [] ? ['nothing to change'] : $changes);
        $io->section('Already recorded on those puzzles - hidden from their owners until the reveal');
        $io->listing($records === [] ? ['none'] : $records);
        $io->section('Round puzzles secret on their event page only - left so, check they are catalogue puzzles');
        $io->listing($eventPageOnly === [] ? ['none'] : $eventPageOnly);
        $io->section('Other puzzles hidden in the future - not touched, review by hand');
        $io->listing($unmatched === [] ? ['none'] : $unmatched);

        if ($obsoleteImages !== []) {
            $io->section(sprintf('Old picture names to purge from the caches %s', $write ? '(after this run)' : '(after the --write run)'));
            $io->text('images-cache (nginx) on lily, in /srv/myspeedpuzzling:');
            $io->text(sprintf('  docker compose exec images-cache rm -f %s', implode(' ', $this->imageCachePurgeList->nginxCacheFiles($obsoleteImages))));
            $io->text('Cloudflare (img.myspeedpuzzling.com): Caching > Configuration > Custom Purge > URL, or the purge_cache API with these "files":');
            $io->listing($this->imageCachePurgeList->publicUrls($obsoleteImages));
        }

        $io->success(sprintf('%d round puzzles %s.', count($changes), $write ? 'changed' : 'would change (dry run, add --write)'));

        return self::SUCCESS;
    }
}
