<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use SpeedPuzzling\Web\Message\GenerateVouchers;
use SpeedPuzzling\Web\Value\VoucherType;
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
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Voucher type (free_months|percentage_discount)', 'free_months');
        $this->addOption('months', 'm', InputOption::VALUE_REQUIRED, 'Months value for free_months vouchers');
        $this->addOption('percentage', 'p', InputOption::VALUE_REQUIRED, 'Discount percentage (1-100) for percentage_discount vouchers');
        $this->addOption('max-uses', null, InputOption::VALUE_REQUIRED, 'Maximum number of claims allowed', '1');
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

        /** @var string $typeString */
        $typeString = $input->getOption('type');
        $voucherType = VoucherType::tryFrom($typeString);

        if ($voucherType === null) {
            $io->error('Invalid voucher type. Use "free_months" or "percentage_discount"');
            return self::FAILURE;
        }

        /** @var string|null $monthsString */
        $monthsString = $input->getOption('months');
        $months = $monthsString !== null ? (int) $monthsString : null;

        /** @var string|null $percentageString */
        $percentageString = $input->getOption('percentage');
        $percentage = $percentageString !== null ? (int) $percentageString : null;

        /** @var string $maxUsesString */
        $maxUsesString = $input->getOption('max-uses');
        $maxUses = (int) $maxUsesString;

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

        if ($voucherType === VoucherType::FreeMonths) {
            if ($months === null || $months < 1) {
                $io->error('Months value (--months) is required and must be at least 1 for free_months vouchers');
                return self::FAILURE;
            }
        }

        if ($voucherType === VoucherType::PercentageDiscount) {
            if ($percentage === null || $percentage < 1 || $percentage > 100) {
                $io->error('Percentage (--percentage) is required and must be between 1 and 100 for percentage_discount vouchers');
                return self::FAILURE;
            }
        }

        if ($maxUses < 1) {
            $io->error('Max uses must be at least 1');
            return self::FAILURE;
        }

        if ($codeLength < 8 || $codeLength > 32) {
            $io->error('Code length must be between 8 and 32');
            return self::FAILURE;
        }

        if ($voucherType === VoucherType::FreeMonths) {
            $io->info(sprintf(
                'Generating %d free months voucher(s) with %d month(s) value, valid until %s',
                $count,
                $months,
                $validUntil->format('Y-m-d'),
            ));
        } else {
            $io->info(sprintf(
                'Generating %d percentage discount voucher(s) with %d%% off, max %d uses, valid until %s',
                $count,
                $percentage,
                $maxUses,
                $validUntil->format('Y-m-d'),
            ));
        }

        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: $count,
                validUntil: $validUntil,
                voucherType: $voucherType,
                monthsValue: $months,
                percentageDiscount: $percentage,
                maxUses: $maxUses,
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
