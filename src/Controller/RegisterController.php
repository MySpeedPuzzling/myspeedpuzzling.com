<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\FormData\RegistrationFormData;
use SpeedPuzzling\Web\FormType\RegistrationFormType;
use SpeedPuzzling\Web\Message\RegisterUser;
use SpeedPuzzling\Web\Message\SendEmailVerificationLink;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use SpeedPuzzling\Web\Security\UserAccountProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Native registration (Stage A of issue #147). Until the `native_registration`
 * flag flips, signing up still happens on Auth0's hosted page, so this route
 * hands over to /login - which is the Auth0 redirect while the flag is off.
 *
 * The account and the player are created by one handler in one transaction; this
 * controller only logs the new user in and asks for the verification email.
 */
final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly Security $security,
        private readonly UserAccountProvider $userAccountProvider,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $registrationIpLimiter,
        private readonly bool $nativeRegistrationEnabled,
    ) {
    }

    #[Route(
        path: '/register',
        name: 'register',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if ($this->nativeRegistrationEnabled === false) {
            return $this->redirectToRoute('login');
        }

        if ($this->getUser() !== null) {
            return $this->redirectToRoute('my_profile');
        }

        $data = new RegistrationFormData();
        $form = $this->createForm(RegistrationFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rateLimit = $this->registrationIpLimiter
                ->create($request->getClientIp() ?? 'unknown')
                ->consume();

            if ($rateLimit->isAccepted() === false) {
                $this->addFlash('warning', $this->translator->trans('auth.register.too_many_attempts'));

                return $this->redirectToRoute('register');
            }

            try {
                $envelope = $this->messageBus->dispatch(
                    new RegisterUser(
                        email: $data->email,
                        plainPassword: $data->plainPassword,
                        locale: $request->getLocale(),
                    ),
                );
            } catch (HandlerFailedException $exception) {
                // The handler's checks are advisory - two registrations racing on the
                // same address get past both and one loses at the unique index. Same
                // situation for the user, so it deserves the same message rather than
                // a "something went wrong" and a Sentry alert for a benign race.
                $reason = $exception->getPrevious();

                if ($reason instanceof EmailAlreadyRegistered || $reason instanceof UniqueConstraintViolationException) {
                    // D8: the unique-email error is an accepted enumeration tradeoff.
                    // The copy points at signing in rather than only saying "taken" -
                    // through window A the collision is usually the user's own older
                    // Auth0 account, and a second account would strand it.
                    $form->get('email')->addError(
                        new FormError($this->translator->trans('auth.register.email_already_registered')),
                    );

                    return $this->render('register.html.twig', [
                        'form' => $form->createView(),
                    ]);
                }

                $this->logger->error('Native registration failed', [
                    'exception' => $exception,
                ]);

                $this->addFlash('danger', $this->translator->trans('auth.register.failed'));

                return $this->redirectToRoute('register');
            }

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            $userId = $handledStamp->getResult();
            assert(is_string($userId));

            // Straight into the session: the account was just created with a password
            // the visitor chose. Verification gates nothing (D7) - it is asked for by
            // email, never enforced here.
            //
            // The authenticator must be named: through window A the `main` firewall
            // carries LoginFormAuthenticator, the Auth0 authenticator and the login
            // link, and Security::login() refuses to guess between them.
            $this->security->login(
                $this->userAccountProvider->loadUserByIdentifier($userId),
                authenticatorName: LoginFormAuthenticator::class,
                firewallName: 'main',
            );

            $this->messageBus->dispatch(
                new SendEmailVerificationLink(
                    userId: $userId,
                    fallbackLocale: $request->getLocale(),
                ),
            );

            return $this->redirectToRoute('registration_welcome');
        }

        return $this->render('register.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
