<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Exceptions\AccountDeletionTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidAccountDeletionToken;
use SpeedPuzzling\Web\Message\ConfirmAccountDeletion;
use SpeedPuzzling\Web\Query\GetAccountDeletionSummary;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ValidateAccountDeletionToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mailed "delete my account" link (docs/features/account-deletion.md).
 *
 * GET only shows the last-chance page - mail clients and link scanners prefetch
 * URLs found in mail, so a GET that deleted would delete accounts nobody asked
 * to delete. The destructive step is the POST, behind CSRF and an explicit
 * "I understand" checkbox.
 *
 * Anonymous by design, like the password-reset page: the token is the proof,
 * and the link gets opened wherever the mail is read. No session is started
 * for it (#164) and Referrer-Policy: same-origin keeps the token from leaking
 * to anything off-site the page links out to. Deliberately not `no-referrer`:
 * that makes browsers send `Origin: null` on the page's own same-origin form
 * POST (Fetch spec), which leaves the stateless CSRF check with only
 * `Sec-Fetch-Site` to go on - fine on current browsers, a dead button on
 * older Safari. `same-origin` shows the URL only to this server, which issued
 * the token in the first place.
 */
final class ConfirmAccountDeletionController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'confirm_account_deletion';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidateAccountDeletionToken $validateAccountDeletionToken,
        private readonly PlayerRepository $playerRepository,
        private readonly GetAccountDeletionSummary $getAccountDeletionSummary,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/delete-account/{token}',
        name: 'confirm_account_deletion',
        // Deliberately looser than the token's real shape (64 hex chars): a link the
        // mail client wrapped or truncated should reach the controller and get the
        // "this link does not work" page, not a bare 404
        requirements: ['token' => '[0-9a-zA-Z]{1,128}'],
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $token): Response
    {
        try {
            $userAccount = $this->validateAccountDeletionToken->validate($token);
        } catch (AccountDeletionTokenExpired) {
            return $this->renderDeadLink('expired');
        } catch (InvalidAccountDeletionToken) {
            return $this->renderDeadLink('invalid');
        }

        if ($request->isMethod('POST') === false) {
            return $this->renderLastChance($token, $userAccount, confirmationMissing: false);
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($request->request->getBoolean('confirm') === false) {
            return $this->renderLastChance($token, $userAccount, confirmationMissing: true);
        }

        // Signed in as the account that is about to go: sign out FIRST, so the logout
        // audit row is written while the account exists and cascades away with it.
        // (After the deletion it would linger as an orphan carrying an IP address.)
        $currentUser = $this->security->getUser();

        if ($currentUser instanceof UserAccount && $currentUser->userId === $userAccount->userId) {
            $this->security->logout(validateCsrfToken: false);
        }

        try {
            $this->messageBus->dispatch(new ConfirmAccountDeletion(token: $token));
        } catch (HandlerFailedException $exception) {
            $reason = $exception->getPrevious();

            if ($reason instanceof AccountDeletionTokenExpired) {
                return $this->renderDeadLink('expired');
            }

            if ($reason instanceof InvalidAccountDeletionToken) {
                return $this->renderDeadLink('invalid');
            }

            $this->logger->error('Account deletion failed', [
                'exception' => $exception,
                'user_id' => $userAccount->userId,
            ]);

            return $this->renderDeadLink('failed');
        }

        return $this->redirectToRoute('account_deleted');
    }

    private function renderLastChance(string $token, UserAccount $userAccount, bool $confirmationMissing): Response
    {
        $player = $this->playerRepository->findByUserId($userAccount->userId);

        return $this->keepReferrerOnSite($this->render('account_deletion_confirm.html.twig', [
            'token' => $token,
            'account_email' => $userAccount->email,
            'player_name' => $player?->name,
            'player_code' => $player?->code,
            'player_id' => $player?->id->toString(),
            // "This is what goes with it": the concrete numbers, so the decision is
            // made against real times and puzzles rather than an abstract warning
            'summary' => $player === null ? null : $this->getAccountDeletionSummary->byPlayerId($player->id->toString()),
            'confirmation_missing' => $confirmationMissing,
        ]));
    }

    private function renderDeadLink(string $outcome): Response
    {
        return $this->keepReferrerOnSite($this->render('account_deletion_dead_link.html.twig', [
            'outcome' => $outcome,
            'headline' => $this->translator->trans('account_deletion.' . $outcome . '.headline'),
            'message' => $this->translator->trans('account_deletion.' . $outcome . '.message'),
        ]));
    }

    private function keepReferrerOnSite(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
