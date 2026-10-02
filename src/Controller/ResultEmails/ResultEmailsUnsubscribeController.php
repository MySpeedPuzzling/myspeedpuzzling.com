<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultEmails;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnsubscribeFromResultEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unsubscribe from the "Your results" e-mails (docs/features/duplicate-results.md, "Sending"), reached through the
 * signed link of the e-mail - no sign-in needed (ResultEmailsUnsubscribeUrl).
 *
 * POST switches the e-mails off: the RFC 8058 one-click POST of mail clients (`List-Unsubscribe-Post`) and the
 * button of the page. GET only shows the page - mail scanners open links, they must not unsubscribe anybody.
 */
final class ResultEmailsUnsubscribeController extends AbstractController
{
    public function __construct(
        readonly private UriSigner $uriSigner,
        readonly private PlayerRepository $playerRepository,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/{_locale}/result-emails/unsubscribe/{playerId}',
        name: 'result_emails_unsubscribe',
        requirements: ['playerId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $playerId): Response
    {
        if ($this->uriSigner->checkRequest($request) === false) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            // A deleted player answers 404 (PlayerNotFound)
            $this->messageBus->dispatch(new UnsubscribeFromResultEmails($playerId));

            return $this->redirect($request->getUri(), Response::HTTP_SEE_OTHER);
        }

        $player = $this->playerRepository->get($playerId);

        $response = $this->render('result_emails/unsubscribe.html.twig', [
            'subscribed' => $player->resultEmailsEnabled,
            'action_url' => $request->getUri(),
        ]);

        // Personal state behind a capability URL: never in a shared cache
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
