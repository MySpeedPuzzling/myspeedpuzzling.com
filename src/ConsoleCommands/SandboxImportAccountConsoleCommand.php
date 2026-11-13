<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\CopyAccountToSandbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Reads one account of myspeedpuzzling:sandbox:export-account from stdin and copies it into the sandbox (same e-mail,
 * same password; an e-mail registered there already only gets production's password). Refuses anywhere but the
 * sandbox (SANDBOX=1). docs/features/sandbox/README.md "Copying a real account".
 */
#[AsCommand(
    name: 'myspeedpuzzling:sandbox:import-account',
    description: 'Copy one exported production account into the sandbox, read from stdin (SANDBOX=1 only).',
)]
final class SandboxImportAccountConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = trim((string) file_get_contents('php://stdin'));

        /** @var array{email?: mixed, passwordHash?: mixed, emailVerified?: mixed, name?: mixed, code?: mixed, country?: mixed, locale?: mixed, timezone?: mixed} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_string($data['email'] ?? null) || !is_string($data['code'] ?? null)) {
            $io->error('Not an export of myspeedpuzzling:sandbox:export-account.');

            return self::FAILURE;
        }

        $string = static fn (mixed $value): null|string => is_string($value) && $value !== '' ? $value : null;

        $envelope = $this->messageBus->dispatch(new CopyAccountToSandbox(
            email: $data['email'],
            passwordHash: $string($data['passwordHash'] ?? null),
            emailVerified: ($data['emailVerified'] ?? false) === true,
            name: $string($data['name'] ?? null),
            code: $data['code'],
            country: $string($data['country'] ?? null),
            locale: $string($data['locale'] ?? null),
            timezone: $string($data['timezone'] ?? null),
        ));

        $created = $envelope->last(HandledStamp::class)?->getResult() === true;
        $io->success(sprintf(
            '%s: %s - signs in on the sandbox with the same e-mail and password.',
            $data['email'],
            $created ? 'account created' : 'was registered here already, now has the production password',
        ));

        return self::SUCCESS;
    }
}
