<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\Sandbox\SandboxGate;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Enter the sandbox" on the sandbox's welcome page (SandboxGate): remembers it for a year and continues to the page
 * the visitor asked for. A POST on purpose - crawlers follow links, they do not submit forms. No CSRF token: the
 * cookie only says the welcome page was seen. 404 anywhere SANDBOX is 0.
 */
final class SandboxEnterController extends AbstractController
{
    public function __construct(
        private readonly SandboxMode $sandboxMode,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/sandbox/enter', name: 'sandbox_enter', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->sandboxMode->on === false) {
            throw new NotFoundHttpException();
        }

        $return = ReturnUrl::tryFrom($request->request->getString('return'));
        $response = new RedirectResponse($return !== null ? (string) $return : '/', Response::HTTP_SEE_OTHER);

        $response->headers->setCookie(Cookie::create(SandboxGate::COOKIE)
            ->withValue('1')
            ->withExpires($this->clock->now()->modify('+' . SandboxGate::COOKIE_DAYS . ' days'))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }
}
