<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publisher-domain verification file for the Microsoft app registration
 * (docs/features/auth-hardening/setup-microsoft.md, "Branding & properties"):
 * Microsoft fetches it to confirm that myspeedpuzzling.com belongs to whoever
 * registered the app. Served from MICROSOFT_CLIENT_ID so no redeploy with a
 * hard-coded id is needed; 404 while Microsoft sign-in is not configured.
 */
final class MicrosoftIdentityAssociationController extends AbstractController
{
    public function __construct(
        private readonly string $microsoftClientId,
    ) {
    }

    #[Route(
        path: '/.well-known/microsoft-identity-association.json',
        name: 'microsoft_identity_association',
        methods: ['GET', 'HEAD'],
    )]
    public function __invoke(): JsonResponse
    {
        if ($this->microsoftClientId === '') {
            throw new NotFoundHttpException();
        }

        $response = new JsonResponse([
            'associatedApplications' => [
                ['applicationId' => $this->microsoftClientId],
            ],
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
