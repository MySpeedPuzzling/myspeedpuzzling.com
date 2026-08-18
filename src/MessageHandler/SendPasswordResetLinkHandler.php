<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\Message\SendPasswordResetLink;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mails a reset link for a token RequestPasswordReset already minted and
 * committed. Kept separate from that handler so the row is written in its own
 * transaction before any mail goes out - a link can never arrive for a request
 * that failed to persist.
 */
#[AsMessageHandler]
final readonly class SendPasswordResetLinkHandler
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

    public function __invoke(SendPasswordResetLink $message): void
    {
        $userAccount = $this->userAccountRepository->findByEmail($message->email);

        if ($userAccount === null) {
            return;
        }

        $resetUrl = $this->urlGenerator->generate(
            'password_reset',
            ['token' => $message->token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $player = $this->playerRepository->findByUserId($userAccount->userId);
        $locale = $player !== null && $player->locale !== null
            ? $player->locale
            : $message->fallbackLocale;

        $email = (new TemplatedEmail())
            ->to($userAccount->email)
            ->locale($locale)
            ->subject($this->translator->trans('password_reset.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/password_reset.html.twig')
            ->context([
                'resetUrl' => $resetUrl,
                'expiresInMinutes' => ResetPasswordRequest::LIFETIME_MINUTES,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);

        $this->logger->info('Password reset link issued', [
            'user_id' => $userAccount->userId,
            'legacy_auth0' => $userAccount->legacyAuth0,
        ]);
    }
}
