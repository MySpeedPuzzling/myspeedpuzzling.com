<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Test;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-only controller for logging in users during Panther E2E tests.
 *
 * This controller is only registered in dev/test environments via config/packages/dev/services.php
 * and config/packages/test/services.php. It creates a native UserAccount session, bypassing
 * the login form. The find-or-create write happens directly (not through Messenger) on purpose:
 * the endpoint must stay idempotent across Panther tests sharing one database.
 */
#[Route(path: '/_test/login', name: 'test_login')]
final class TestLoginController extends AbstractController
{
    public function __construct(
        readonly private Security $security,
        readonly private UserAccountRepository $userAccountRepository,
        readonly private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $userId = $request->query->getString('userId');
        $email = $request->query->getString('email');
        $name = $request->query->getString('name');

        $userAccount = $this->userAccountRepository->findByUserId($userId);
        $accountExisted = $userAccount !== null;

        if ($userAccount === null) {
            $userAccount = new UserAccount(
                Uuid::uuid7(),
                $userId,
                $email,
                new DateTimeImmutable(),
            );

            if (str_starts_with($userId, 'auth0|')) {
                // Mirror the state the Stage B import leaves behind
                $userAccount->applyAuth0Import($email, null, true, new DateTimeImmutable());
            }

            $this->entityManager->persist($userAccount);
            $this->entityManager->flush();
        }

        // The firewall carries multiple authenticators (window A) - the target
        // authenticator must be named explicitly or Security::login() throws
        $this->security->login($userAccount, LoginFormAuthenticator::class, 'main');

        // The db/account details make cross-database session bugs diagnosable
        // from the response alone (per-test database churn, see PantherDatabaseManager)
        return new Response(sprintf(
            'Logged in as %s [db=%s account=%s]',
            $name,
            $this->entityManager->getConnection()->getDatabase() ?? 'unknown',
            $accountExisted ? 'existing' : 'created',
        ));
    }
}
