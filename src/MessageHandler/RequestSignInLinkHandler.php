<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RequestSignInLink;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\SingleUseLoginLinkHandler;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Email me a sign-in link" (D6, issue #147) - the rescue for everybody whose
 * password manager filed the credential under the old Auth0 sign-in domain.
 *
 * Unknown addresses are a silent no-op: the controller responds identically
 * either way, so the endpoint cannot be used to probe which emails have an
 * account. Rate limiting lives in the controller, before this handler runs.
 */
#[AsMessageHandler]
final readonly class RequestSignInLinkHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private SingleUseLoginLinkHandler $loginLinkHandler,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private int $signInLinkLifetimeSeconds,
    ) {
    }

    public function __invoke(RequestSignInLink $message): void
    {
        $userAccount = $this->userAccountRepository->findByEmail($message->email);

        if ($userAccount === null) {
            $this->logger->info('Sign-in link requested for an address without an account');

            return;
        }

        $loginLinkDetails = $this->loginLinkHandler->createLoginLink($userAccount);

        $player = $this->playerRepository->findByUserId($userAccount->userId);
        $locale = $player !== null && $player->locale !== null
            ? $player->locale
            : $message->fallbackLocale;

        $email = (new TemplatedEmail())
            ->to($userAccount->email)
            ->locale($locale)
            ->subject($this->translator->trans('sign_in_link.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/sign_in_link.html.twig')
            ->context([
                'signInUrl' => $loginLinkDetails->getUrl(),
                'expiresInMinutes' => intdiv($this->signInLinkLifetimeSeconds, 60),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);

        // No email address in the log line - the counter this feeds (Phase 5 exit
        // metrics) only ever needs the volume
        $this->logger->info('Sign-in link issued', [
            'user_id' => $userAccount->userId,
            'legacy_auth0' => $userAccount->legacyAuth0,
        ]);
    }
}
