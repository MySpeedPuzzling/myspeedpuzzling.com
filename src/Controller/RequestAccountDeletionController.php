<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\RequestAccountDeletion;
use SpeedPuzzling\Web\Message\SendAccountDeletionLink;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Danger zone" step one: e-mails the signed-in user a link that opens the
 * last-chance page (docs/features/account-deletion.md). Nothing is deleted
 * here - and nothing can be, the link is the second factor.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RequestAccountDeletionController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'request_account_deletion';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $accountDeletionRequestLimiter,
    ) {
    }

    #[Route(
        path: '/request-account-deletion',
        name: 'request_account_deletion',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Window A: a legacy Auth0 session has no user_account row to bind the token
        // to. Nothing to offer it here - the profile page is where it came from.
        if (!$user instanceof UserAccount) {
            return $this->redirectToRoute('edit_profile');
        }

        $userAccount = $user;

        $rateLimit = $this->accountDeletionRequestLimiter
            ->create($userAccount->userId)
            ->consume();

        if ($rateLimit->isAccepted() === false) {
            $this->addFlash('warning', $this->translator->trans('edit_profile.danger_zone.too_many_requests'));

            return $this->redirectToRoute('edit_profile');
        }

        try {
            $envelope = $this->messageBus->dispatch(
                new RequestAccountDeletion(userId: $userAccount->userId),
            );

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            $token = $handledStamp->getResult();
            assert($token instanceof AccountDeletionToken);

            $this->messageBus->dispatch(
                new SendAccountDeletionLink(
                    userId: $userAccount->userId,
                    token: $token->toString(),
                    fallbackLocale: $request->getLocale(),
                ),
            );
        } catch (HandlerFailedException $exception) {
            $this->logger->error('Could not issue an account deletion link', [
                'exception' => $exception,
            ]);

            $this->addFlash('danger', $this->translator->trans('edit_profile.danger_zone.failed'));

            return $this->redirectToRoute('edit_profile');
        }

        $this->addFlash('success', $this->translator->trans('edit_profile.danger_zone.sent', [
            '%email%' => $userAccount->email,
        ]));

        return $this->redirectToRoute('edit_profile');
    }
}
