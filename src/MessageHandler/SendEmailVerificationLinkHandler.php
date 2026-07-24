<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\SendEmailVerificationLink;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\EmailVerificationTokenSigner;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends (or re-sends) the "confirm your email address" link. The token is
 * stateless (EmailVerificationTokenSigner) and binds the address it was sent to,
 * so an outstanding link dies the moment the account email changes.
 *
 * An account that is already verified, or gone, is a silent no-op - the callers
 * (registration, change-email, the resend button) must not behave differently.
 */
#[AsMessageHandler]
final readonly class SendEmailVerificationLinkHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private EmailVerificationTokenSigner $tokenSigner,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendEmailVerificationLink $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            $this->logger->info('Email verification link requested for an unknown account');

            return;
        }

        if ($userAccount->emailVerifiedAt !== null) {
            return;
        }

        $expiresAt = $this->clock->now()->modify(EmailVerificationTokenSigner::LIFETIME);
        $token = $this->tokenSigner->generate($userAccount, $expiresAt);

        $verificationUrl = $this->urlGenerator->generate(
            'verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $player = $this->playerRepository->findByUserId($userAccount->userId);
        $locale = $player !== null && $player->locale !== null
            ? $player->locale
            : $message->fallbackLocale;

        $email = (new TemplatedEmail())
            ->to($userAccount->email)
            ->locale($locale)
            ->subject($this->translator->trans('verify_email.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/verify_email.html.twig')
            ->context([
                'verificationUrl' => $verificationUrl,
                'signInLinkUrl' => $this->urlGenerator->generate('sign_in_link_request', [], UrlGeneratorInterface::ABSOLUTE_URL),
                // Window A only: a native registrant who logs out meets the Auth0 form,
                // which has no identity for them and which we cannot brand or link from.
                // The sign-in link is their way back in (implementation-plan §2c).
                'showSignInLinkRescue' => $userAccount->legacyAuth0 === false,
                'expiresInHours' => 24,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);

        $this->logger->info('Email verification link issued', [
            'user_id' => $userAccount->userId,
        ]);
    }
}
