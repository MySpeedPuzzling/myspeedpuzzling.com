<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OAuth2;

use League\Bundle\OAuth2ServerBundle\Controller\AuthorizationController as BundleAuthorizationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Custom OAuth2 Authorization Controller that requires authentication
 * before delegating to the bundle's authorization controller.
 *
 * When the user is not authenticated, Symfony's security system redirects them
 * to login via LoginEntryPoint, which puts the full request URI - OAuth2 query
 * parameters and all - into ?return=. The login form echoes it back and the
 * authenticator redirects there on success, so the authorization request
 * survives the sign-in without any server-side state.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AuthorizationController extends AbstractController
{
    public function __construct(
        private readonly BundleAuthorizationController $bundleAuthorizationController,
    ) {
    }

    #[Route(
        path: '/oauth2/authorize',
        name: 'oauth2_authorize',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        // User is authenticated (guaranteed by IsGranted), delegate to the bundle
        return $this->bundleAuthorizationController->indexAction($request);
    }
}
