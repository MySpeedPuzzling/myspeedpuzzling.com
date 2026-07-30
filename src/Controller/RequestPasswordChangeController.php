<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User as Auth0User;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RequestPasswordChange;
use SpeedPuzzling\Web\Services\Auth0DatabaseConnection;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RequestPasswordChangeController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'change_password';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zmenit-heslo',
            'en' => '/en/change-password',
            'es' => '/es/cambiar-contrasena',
            'ja' => '/ja/パスワード変更',
            'fr' => '/fr/changer-mot-de-passe',
            'de' => '/de/passwort-aendern',
        ],
        name: 'request_password_change',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException();
        }

        $userId = $user->getUserIdentifier();
        // This whole flow is the legacy #161 Auth0 reset-email; a native UserAccount session
        // never reaches it (edit-profile renders the native card instead), but if one does,
        // the hasPassword() guard below refuses msp| ids and flashes "not available".
        $email = $user instanceof Auth0User ? $user->getEmail() : null;

        if ($email === null || $email === '' || Auth0DatabaseConnection::hasPassword($userId) === false) {
            $this->addFlash('warning', $this->translator->trans('flashes.password_change_not_available'));

            return $this->redirectToRoute('edit_profile');
        }

        try {
            $this->messageBus->dispatch(
                new RequestPasswordChange(
                    userId: $userId,
                    email: $email,
                ),
            );
        } catch (HandlerFailedException $exception) {
            $this->logger->error('Requesting password change failed', [
                'exception' => $exception,
            ]);

            $this->addFlash('danger', $this->translator->trans('flashes.password_change_failed'));

            return $this->redirectToRoute('edit_profile');
        }

        $this->addFlash('success', $this->translator->trans('flashes.password_change_email_sent'));

        return $this->redirectToRoute('edit_profile');
    }
}
