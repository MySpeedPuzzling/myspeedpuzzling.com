<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SendResultReviewEmailPreview;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\ResultReviewEmailPreviewVariant;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * See the "Your results" e-mail in your own inbox before players get it (docs/features/duplicate-results.md,
 * "Sending") - sample data, the real template, headers and transport, writes nothing.
 */
#[AsCommand(
    name: 'myspeedpuzzling:send-result-review-email-preview',
    description: 'Send a preview of the "Your results" e-mail with sample data to one address',
)]
final class SendResultReviewEmailPreviewConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Where to send the preview')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Language: ' . implode(', ', ListmonkNewsletterLists::LOCALES), 'en')
            ->addOption('variant', null, InputOption::VALUE_REQUIRED, 'Which e-mail: ' . implode(', ', ResultReviewEmailPreviewVariant::names()), ResultReviewEmailPreviewVariant::First->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $locale */
        $locale = $input->getOption('locale');
        /** @var string $variantName */
        $variantName = $input->getOption('variant');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error(sprintf('"%s" is not an e-mail address.', $email));

            return self::FAILURE;
        }

        if (in_array($locale, ListmonkNewsletterLists::LOCALES, true) === false) {
            $io->error(sprintf('Unknown locale "%s" - use one of: %s.', $locale, implode(', ', ListmonkNewsletterLists::LOCALES)));

            return self::FAILURE;
        }

        $variant = ResultReviewEmailPreviewVariant::tryFrom($variantName);

        if ($variant === null) {
            $io->error(sprintf('Unknown variant "%s" - use one of: %s.', $variantName, implode(', ', ResultReviewEmailPreviewVariant::names())));

            return self::FAILURE;
        }

        $this->messageBus->dispatch(new SendResultReviewEmailPreview($email, $locale, $variant));

        $io->success(sprintf('Preview "%s" (%s) queued for %s - the messenger consumer sends it.', $variant->value, $locale, $email));

        return self::SUCCESS;
    }
}
