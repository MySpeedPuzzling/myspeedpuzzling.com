<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CannotUnlinkLastSignInMethod;
use SpeedPuzzling\Web\Exceptions\OauthIdentityNotFound;
use SpeedPuzzling\Web\Message\UnlinkOauthIdentity;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Disconnects a provider from "Connected sign-in methods". Deliberately NOT
 * gated on the per-provider feature flag: a linked identity must stay
 * removable even after its provider gets switched off. The ≥1-sign-in-method
 * invariant lives in the handler.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class UnlinkSocialIdentityController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/account/social/{provider}/disconnect',
        name: 'social_unlink',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user, string $provider): Response
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        if ($oauthProvider === null) {
            throw new NotFoundHttpException();
        }

        if (!$this->isCsrfTokenValid('social_unlink_' . $oauthProvider->value, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('edit_profile');
        }

        if (!$user instanceof UserAccount) {
            return $this->redirectToRoute('edit_profile');
        }

        try {
            $this->messageBus->dispatch(new UnlinkOauthIdentity(
                userId: $user->userId,
                provider: $oauthProvider,
            ));
        } catch (HandlerFailedException $exception) {
            $reason = $exception->getPrevious();

            if ($reason instanceof CannotUnlinkLastSignInMethod) {
                $this->addFlash('warning', $this->translator->trans('edit_profile.social.cannot_unlink_last'));

                return $this->redirectToRoute('edit_profile');
            }

            if (!$reason instanceof OauthIdentityNotFound) {
                $this->logger->error('Unlinking a social identity failed.', [
                    'exception' => $exception,
                ]);
            }

            $this->addFlash('danger', $this->translator->trans('flashes.unknown_error'));

            return $this->redirectToRoute('edit_profile');
        }

        $this->addFlash('success', $this->translator->trans('edit_profile.social.disconnected', [
            '%provider%' => $oauthProvider->displayName(),
        ]));

        return $this->redirectToRoute('edit_profile');
    }
}
