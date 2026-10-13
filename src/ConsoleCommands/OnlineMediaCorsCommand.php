<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaBucketCors;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ops (docs/features/online-competitions/README.md §5.11): the bucket CORS rule participant phones need to upload
 * evidence from the site's origin. Prints the rule by default; `--apply` writes it into the bucket, keeping the
 * bucket's other rules (OnlineMediaBucketCors). The production bucket is changed only with Jan's OK.
 */
#[AsCommand(
    name: 'myspeedpuzzling:online-media:cors',
    description: 'Print (default) or apply (--apply) the bucket CORS rule that lets participant phones upload online competition evidence',
)]
final class OnlineMediaCorsCommand extends Command
{
    public function __construct(
        private readonly OnlineMediaBucketCors $onlineMediaBucketCors,
        #[Autowire(env: 'APP_URL')]
        private readonly string $appUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the rule into the bucket (keeps its other rules)')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'primary or secondary', OnlineMediaStore::Primary->value)
            ->addOption('origin', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Allowed origin(s) - default: the site\'s own (APP_URL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storeOption = $input->getOption('store');
        $store = is_string($storeOption) ? OnlineMediaStore::tryFrom($storeOption) : null;

        if ($store === null) {
            $io->error('--store must be "primary" or "secondary".');

            return Command::FAILURE;
        }

        /** @var list<string> $originOptions */
        $originOptions = $input->getOption('origin');
        $origins = [];

        foreach ($originOptions !== [] ? $originOptions : [$this->appUrl] as $url) {
            $origin = self::origin($url);

            if ($origin === null) {
                $io->error(sprintf('"%s" is not an origin (https://host[:port]).', $url));

                return Command::FAILURE;
            }

            $origins[] = $origin;
        }

        $origins = array_values(array_unique($origins));

        if ($input->getOption('apply') !== true) {
            $io->writeln(json_encode(['CORSRules' => [$this->onlineMediaBucketCors->rule($origins)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $io->note(sprintf('Not applied. With --apply the rule "%s" replaces its old version in the %s bucket; the bucket\'s other rules stay.', OnlineMediaBucketCors::RULE_ID, $store->value));

            return Command::SUCCESS;
        }

        $rules = $this->onlineMediaBucketCors->apply($store, $origins);

        $io->writeln(json_encode(['CORSRules' => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $io->success(sprintf('The %s bucket now allows uploads from %s.', $store->value, implode(', ', $origins)));

        return Command::SUCCESS;
    }

    /**
     * `scheme://host[:port]` of a URL - null when it has none
     */
    private static function origin(string $url): null|string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
