<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Security\SignInCodeAuthenticator;
use SpeedPuzzling\Web\Security\SignInCodeRejected;
use SpeedPuzzling\Web\Services\SignInCodeCompletion;
use SpeedPuzzling\Web\Services\SignInCodePending;
use SpeedPuzzling\Web\Value\ReturnUrl;
use SpeedPuzzling\Web\Value\SignInCodeOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /verify-code - the 6-digit code form on "Check your email".
 *
 * SignInCodeAuthenticator does the work on the firewall; a success never gets
 * here (it answers 303 itself). This controller only answers a failure: the
 * same screen again with 422 and what went wrong, or - when this browser has no
 * sign-in pending any more - back to the request form.
 *
 * Except a duplicate submit of a code that has just signed this browser in
 * (iOS autofill + our auto-submit, SignInCodeCompletion): the authenticator
 * steps aside and this answers with the same 303 the first submit got.
 */
final class SignInCodeController extends AbstractController
{
    public function __construct(
        private readonly SignInCodePending $signInCodePending,
        private readonly TranslatorInterface $translator,
        private readonly int $signInLinkLifetimeSeconds,
        private readonly SignInCodeCompletion $signInCodeCompletion,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/verify-code',
        name: SignInCodeAuthenticator::ROUTE,
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));
        $failure = $request->attributes->get(SignInCodeAuthenticator::FAILURE_ATTRIBUTE);

        if ($failure === null && $this->signInCodePending->get($request) === null) {
            // The authenticator stepped aside: a duplicate of a submit that signed in
            $target = $this->signInCodeCompletion->recall($request)
                ?? ($this->getUser() !== null ? $returnUrl->path ?? $this->generateUrl('my_profile') : null);

            if ($target !== null) {
                $this->logger->info('Sign-in code submitted again after it signed in (duplicate_submit)');

                return $this->redirect($target, Response::HTTP_SEE_OTHER);
            }
        }

        $rejected = $failure instanceof SignInCodeRejected ? $failure : null;
        $outcome = $rejected?->outcome;
        $email = $rejected !== null ? $rejected->email : $this->signInCodePending->get($request)['email'] ?? null;

        if ($email === null || $outcome === SignInCodeOutcome::NoPendingRequest) {
            $this->addFlash('danger', $this->translator->trans('auth.sign_in_code.no_pending'));

            return $this->redirectToRoute(
                'sign_in_link_request',
                $returnUrl === null ? [] : ['return' => $returnUrl->path],
                Response::HTTP_SEE_OTHER,
            );
        }

        $codeError = null;
        $alert = null;

        match ($outcome) {
            SignInCodeOutcome::Wrong => $codeError = $this->translator->trans('auth.sign_in_code.wrong', [
                '%count%' => $rejected->attemptsLeft,
            ]),
            SignInCodeOutcome::Malformed => $codeError = $this->translator->trans('auth.sign_in_code.malformed'),
            SignInCodeOutcome::Throttled => $codeError = $this->translator->trans('auth.sign_in_code.throttled'),
            SignInCodeOutcome::LockedOut => $alert = $this->translator->trans('auth.sign_in_code.locked_out'),
            SignInCodeOutcome::Expired => $alert = $this->translator->trans('auth.sign_in_code.expired'),
            SignInCodeOutcome::Used => $alert = $this->translator->trans('auth.sign_in_code.used'),
            // CSRF and anything unexpected: the code may be fine, let them try again
            default => $codeError = $this->translator->trans('auth.sign_in_code.failed'),
        };

        return $this->render('sign_in_link_sent.html.twig', SignInLinkSentController::screen(
            $email,
            false,
            $returnUrl,
            $this->signInLinkLifetimeSeconds,
            // Still pending = the code form stays; locked out, expired or used = gone
            codeAvailable: $this->signInCodePending->get($request) !== null,
            codeError: $codeError,
            alert: $alert,
        ), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
