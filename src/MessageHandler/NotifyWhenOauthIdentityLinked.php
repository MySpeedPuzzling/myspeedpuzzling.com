<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Events\OauthIdentityLinked;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Security notice: "Google/Apple/Facebook sign-in was connected to your
 * account - if this wasn't you, disconnect it and change your password".
 * A linked identity is a new, password-less way into the account, so the
 * owner hears about every one of them, whichever path linked it (rule-2
 * auto-link, settings connect, interstitial connect). A brand-new account
 * registered through a provider gets no notice - nothing existed to protect.
 *
 * Localized to the player's language (auth mails come in all six locales, D17).
 */
#[AsMessageHandler]
final readonly class NotifyWhenOauthIdentityLinked
{
    private const string FALLBACK_LOCALE = 'en';

    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OauthIdentityLinked $event): void
    {
        $userAccount = $this->userAccountRepository->findById($event->userAccountId);

        if ($userAccount === null) {
            // Account deleted before the worker got here - nobody to warn
            $this->logger->info('Social link notice skipped, the account no longer exists.');

            return;
        }

        $player = $this->playerRepository->findByUserId($userAccount->userId);
        $locale = $player->locale ?? self::FALLBACK_LOCALE;
        $providerName = $event->provider->displayName();

        $email = (new TemplatedEmail())
            ->to($userAccount->email)
            ->locale($locale)
            ->subject($this->translator->trans(
                'oauth_identity_linked.subject',
                ['%provider%' => $providerName],
                domain: 'emails',
                locale: $locale,
            ))
            ->htmlTemplate('emails/oauth_identity_linked.html.twig')
            ->context([
                'providerName' => $providerName,
                'accountEmail' => $userAccount->email,
                'linkedAt' => $event->linkedAt->setTimezone(new \DateTimeZone('UTC'))->format('d.m.Y H:i') . ' UTC',
                'manageUrl' => $this->urlGenerator->generate(
                    'edit_profile',
                    ['_locale' => $locale],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'resetPasswordUrl' => $this->urlGenerator->generate(
                    'request_password_reset',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
