<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Exceptions\EmailVerificationTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidEmailVerificationToken;
use SpeedPuzzling\Web\Message\VerifyEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confirms an email address (issue #147). Deliberately open to anonymous
 * visitors: the token is self-contained and proves control of the mailbox on its
 * own, and links get opened on whichever device reads the mail. It grants no
 * session - the outcome is a message, not a login.
 *
 * Replaying a live link is a no-op (D18): the handler is idempotent, so a link
 * clicked twice - or prefetched by a mail client - says "verified" both times.
 */
final class VerifyEmailController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/verify-email',
        name: 'verify_email',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $token = $request->query->getString('token');

        if ($token === '') {
            return $this->renderOutcome('invalid');
        }

        try {
            $this->messageBus->dispatch(new VerifyEmail(token: $token));
        } catch (HandlerFailedException $exception) {
            $reason = $exception->getPrevious();

            if ($reason instanceof EmailVerificationTokenExpired) {
                return $this->renderOutcome('expired');
            }

            if ($reason instanceof InvalidEmailVerificationToken) {
                return $this->renderOutcome('invalid');
            }

            $this->logger->error('Email verification failed', [
                'exception' => $exception,
            ]);

            return $this->renderOutcome('failed');
        }

        return $this->renderOutcome('success');
    }

    private function renderOutcome(string $outcome): Response
    {
        return $this->render('verify_email.html.twig', [
            'outcome' => $outcome,
            'headline' => $this->translator->trans('auth.verify_email.' . $outcome . '.headline'),
            'message' => $this->translator->trans('auth.verify_email.' . $outcome . '.message'),
        ]);
    }
}
