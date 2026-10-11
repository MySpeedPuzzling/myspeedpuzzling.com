<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ChangeContentDigestFrequency;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unsubscribe from the activity digest e-mail, reached through the signed link of the e-mail - no sign-in needed
 * (ContentDigestUnsubscribeUrl). Same contract as DigestEmailsUnsubscribeController.
 *
 * POST switches the digest off: the RFC 8058 one-click POST of mail clients (`List-Unsubscribe-Post`, body
 * `List-Unsubscribe=One-Click`) gets a plain 200 - RFC 8058 forbids redirecting it - and the button of the page a
 * 303 back to the page. GET only shows the page - mail scanners open links, they must not unsubscribe anybody.
 */
final class UnsubscribeContentDigestController extends AbstractController
{
    public function __construct(
        readonly private UriSigner $uriSigner,
        readonly private PlayerRepository $playerRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private PlayerAccountEmail $playerAccountEmail,
        readonly private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
    ) {
    }

    #[Route(
        path: '/{_locale}/activity-digest/unsubscribe/{playerId}',
        name: 'content_digest_unsubscribe',
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
            $this->messageBus->dispatch(new ChangeContentDigestFrequency(
                playerId: $playerId,
                frequency: ContentDigestFrequency::Off,
            ));

            if ($request->request->getString('List-Unsubscribe') === 'One-Click') {
                return new Response('Unsubscribed.', Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            return $this->redirect($request->getUri(), Response::HTTP_SEE_OTHER);
        }

        $player = $this->playerRepository->get($playerId);
        $email = $this->playerAccountEmail->ofPlayer($player);

        $response = $this->render('unsubscribe_content_digest.html.twig', [
            'subscribed' => $player->contentDigestFrequency !== ContentDigestFrequency::Off,
            'action_url' => $request->getUri(),
            // "Rather less often?" - the token preferences page, no sign-in needed
            'preferences_url' => $email === null ? null : $this->emailPreferencesLinkGenerator->forPlayer($playerId, $email, $player->locale),
        ]);

        // Personal state behind a capability URL: never in a shared cache
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
