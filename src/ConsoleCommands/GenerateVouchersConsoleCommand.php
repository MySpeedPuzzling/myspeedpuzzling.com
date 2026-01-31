<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use SpeedPuzzling\Web\Message\GenerateVouchers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand('myspeedpuzzling:vouchers:generate')]
final class GenerateVouchersConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of vouchers to generate', '1');
        $this->addOption('months', 'm', InputOption::VALUE_REQUIRED, 'Months value for each voucher', '1');
        $this->addOption('valid-until', 'u', InputOption::VALUE_REQUIRED, 'Voucher validity date (Y-m-d format)');
        $this->addOption('code-length', 'l', InputOption::VALUE_REQUIRED, 'Length of voucher code', '16');
        $this->addOption('note', null, InputOption::VALUE_REQUIRED, 'Internal note for the vouchers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $countString */
        $countString = $input->getOption('count');
        $count = (int) $countString;

        /** @var string $monthsString */
        $monthsString = $input->getOption('months');
        $months = (int) $monthsString;

        /** @var string|null $validUntilString */
        $validUntilString = $input->getOption('valid-until');

        /** @var string $codeLengthString */
        $codeLengthString = $input->getOption('code-length');
        $codeLength = (int) $codeLengthString;

        /** @var string|null $note */
        $note = $input->getOption('note');

        if ($validUntilString === null) {
            $io->error('The --valid-until option is required');
            return self::FAILURE;
        }

        $validUntil = DateTimeImmutable::createFromFormat('Y-m-d', $validUntilString);

        if ($validUntil === false) {
            $io->error('Invalid date format. Please use Y-m-d format (e.g., 2026-12-31)');
            return self::FAILURE;
        }

        $validUntil = $validUntil->setTime(23, 59, 59);

        if ($count < 1) {
            $io->error('Count must be at least 1');
            return self::FAILURE;
        }

        if ($months < 1) {
            $io->error('Months value must be at least 1');
            return self::FAILURE;
        }

        if ($codeLength < 8 || $codeLength > 32) {
            $io->error('Code length must be between 8 and 32');
            return self::FAILURE;
        }

        $io->info(sprintf(
            'Generating %d voucher(s) with %d month(s) value, valid until %s',
            $count,
            $months,
            $validUntil->format('Y-m-d'),
        ));

        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: $count,
                monthsValue: $months,
                validUntil: $validUntil,
                codeLength: $codeLength,
                internalNote: $note,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);

        if ($handledStamp !== null) {
            /** @var array<string> $codes */
            $codes = $handledStamp->getResult();

            $io->success(sprintf('Successfully generated %d voucher(s):', count($codes)));
            $io->listing($codes);
        }

        return self::SUCCESS;
    }
}
