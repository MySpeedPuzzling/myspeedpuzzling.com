<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Message\RequestSignInLink;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\SingleUseLoginLinkHandler;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Services\SignInCodeHasher;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use SpeedPuzzling\Web\Value\ReturnUrl;
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
        private AuthAuditRecorder $authAuditRecorder,
        private int $signInLinkLifetimeSeconds,
        private SignInCodeHasher $signInCodeHasher,
    ) {
    }

    public function __invoke(RequestSignInLink $message): void
    {
        // Recorded for unknown addresses too - the audit handler looks the account
        // up by email and leaves it null when there is none (probing visibility)
        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::SignInLinkRequested,
            email: $message->email,
        ));

        $userAccount = $this->userAccountRepository->findByEmail($message->email);

        if ($userAccount === null) {
            $this->logger->info('Sign-in link requested for an address without an account');

            return;
        }

        // One mail, two ways in (auth UX redesign phase 2): the link for whichever
        // browser opens it, the code for the browser that asked - an in-app
        // browser (Instagram, Facebook) hands links to the phone's own browser
        $requestId = $message->requestId ?? Uuid::uuid7();
        $code = SignInCodeHasher::generate();

        $loginLinkDetails = $this->loginLinkHandler->createLoginLinkReturningTo(
            $userAccount,
            ReturnUrl::tryFrom($message->returnPath),
            $requestId,
            $this->signInCodeHasher->hash($requestId, $code),
        );

        $player = $this->playerRepository->findByUserId($userAccount->userId);
        $locale = $player !== null && $player->locale !== null
            ? $player->locale
            : $message->fallbackLocale;

        $email = (new TemplatedEmail())
            ->to($userAccount->email)
            ->locale($locale)
            // The code in the subject: readable in the notification, no app switch
            ->subject($this->translator->trans('sign_in_link.subject', ['%code%' => $code], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/sign_in_link.html.twig')
            ->context([
                'signInUrl' => $loginLinkDetails->getUrl(),
                'code' => $code,
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
