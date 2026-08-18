<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\AccountDeletionRequest;
use SpeedPuzzling\Web\Message\SendAccountDeletionLink;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mails the "confirm deleting your account" link for a token
 * RequestAccountDeletion already minted and committed. Kept separate from that
 * handler so the row is written in its own transaction before any mail goes out.
 */
#[AsMessageHandler]
final readonly class SendAccountDeletionLinkHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendAccountDeletionLink $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            return;
        }

        $player = $this->playerRepository->findByUserId($userAccount->userId);
        $locale = $player !== null && $player->locale !== null
            ? $player->locale
            : $message->fallbackLocale;

        $confirmUrl = $this->urlGenerator->generate(
            'confirm_account_deletion',
            ['token' => $message->token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // The export page is per-player and localized; without a player row there is
        // nothing to export, so the mail simply drops that button
        $exportUrl = $player === null ? null : $this->urlGenerator->generate(
            'export_puzzler_data',
            ['playerId' => $player->id->toString(), '_locale' => $locale ?? 'en'],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->to($userAccount->email)
            ->locale($locale)
            ->subject($this->translator->trans('account_deletion.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/account_deletion.html.twig')
            ->context([
                'confirmUrl' => $confirmUrl,
                'exportUrl' => $exportUrl,
                'accountEmail' => $userAccount->email,
                'expiresInMinutes' => AccountDeletionRequest::LIFETIME_MINUTES,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);

        $this->logger->info('Account deletion confirmation link issued', [
            'user_id' => $userAccount->userId,
        ]);
    }
}
