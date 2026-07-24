<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\SendEmailVerificationLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ResendEmailVerificationController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'resend_email_verification';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $emailVerificationResendLimiter,
    ) {
    }

    #[Route(
        path: '/resend-email-verification',
        name: 'resend_email_verification',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Window A: a legacy Auth0 session has no user_account row to verify against.
        // The button that posts here is only rendered for native accounts, so this is
        // the belt to that template's braces.
        if (!$user instanceof UserAccount) {
            return $this->redirectToRoute('edit_profile');
        }

        $userAccount = $user;

        $rateLimit = $this->emailVerificationResendLimiter
            ->create($userAccount->userId)
            ->consume();

        if ($rateLimit->isAccepted() === false) {
            $this->addFlash('warning', $this->translator->trans('auth.verify_email.resend_too_many'));

            return $this->redirectToRoute('edit_profile');
        }

        try {
            $this->messageBus->dispatch(
                new SendEmailVerificationLink(
                    userId: $userAccount->userId,
                    fallbackLocale: $request->getLocale(),
                ),
            );
        } catch (HandlerFailedException $exception) {
            $this->logger->error('Could not resend the email verification link', [
                'exception' => $exception,
            ]);

            $this->addFlash('danger', $this->translator->trans('auth.verify_email.resend_failed'));

            return $this->redirectToRoute('edit_profile');
        }

        $this->addFlash('success', $this->translator->trans('auth.verify_email.resend_sent'));

        return $this->redirectToRoute('edit_profile');
    }
}
