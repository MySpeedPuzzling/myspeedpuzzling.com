<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Test;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-only diagnostic endpoint: reports who the current session resolves to and
 * which database this request is talking to. Panther's loginUser() probes it
 * right after /_test/login - a login that "succeeded" but did not stick then
 * fails fast with an answer instead of surfacing later as a baffling
 * element-not-found on a page that quietly rendered anonymous.
 */
#[Route(path: '/_test/whoami', name: 'test_whoami')]
final class TestWhoAmIController extends AbstractController
{
    public function __construct(
        readonly private Security $security,
        readonly private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->security->getUser();

        return new Response(sprintf(
            'whoami=%s db=%s',
            $user?->getUserIdentifier() ?? 'anonymous',
            $this->entityManager->getConnection()->getDatabase() ?? 'unknown',
        ));
    }
}
