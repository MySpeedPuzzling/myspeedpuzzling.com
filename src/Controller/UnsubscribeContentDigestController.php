<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\ChangeContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Signed one-click unsubscribe for the content digest (RFC 8058 + digest footer link).
 * POST unsubscribes immediately (one-click mail clients); GET shows a confirm button
 * first — link prefetchers must never unsubscribe anyone.
 */
final class UnsubscribeContentDigestController extends AbstractController
{
    public function __construct(
        readonly private UriSigner $uriSigner,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/unsubscribe/content-digest/{playerId}',
        name: 'unsubscribe_content_digest',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $playerId): Response
    {
        if ($this->uriSigner->checkRequest($request) === false) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->messageBus->dispatch(new ChangeContentDigestFrequency(
                playerId: $playerId,
                frequency: ContentDigestFrequency::None,
            ));

            return $this->render('unsubscribe_content_digest.html.twig', [
                'state' => 'done',
                'confirm_url' => null,
            ]);
        }

        return $this->render('unsubscribe_content_digest.html.twig', [
            'state' => 'confirm',
            'confirm_url' => $request->getUri(),
        ]);
    }
}
