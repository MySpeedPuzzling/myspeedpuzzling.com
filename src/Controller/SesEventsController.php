<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\InvalidSnsMessage;
use SpeedPuzzling\Web\Message\ProcessSesEvent;
use SpeedPuzzling\Web\Services\Mailer\Sns\SnsMessageVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Amazon SNS posts SES events here (delivery, bounce, complaint, open, click…; docs/features/mass-mailing/README.md).
 * Every message is signature-checked and must come from one of our topics (SES_EVENTS_TOPIC_ARNS - empty = 404).
 * A subscription is confirmed by fetching its SubscribeURL; a notification is handed to the async queue, so SNS
 * gets its 2xx at once. Any non-2xx makes SNS retry, which is what a failure on our side should do.
 */
final class SesEventsController extends AbstractController
{
    private const int MAX_BODY_BYTES = 256 * 1024;

    public function __construct(
        private readonly SnsMessageVerifier $verifier,
        private readonly MessageBusInterface $messageBus,
        #[Autowire(service: 'ses_events.http_client')]
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/ses-events', name: 'ses_events', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->verifier->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $message = $this->verifier->verify($body);
        } catch (InvalidSnsMessage $exception) {
            $this->logger->info('SES events endpoint refused a message', ['exception' => $exception]);

            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $type = $message['Type'] ?? null;

        if ($type === 'SubscriptionConfirmation') {
            $subscribeUrl = $message['SubscribeURL'] ?? null;

            if (!is_string($subscribeUrl) || !SnsMessageVerifier::isSnsUrl($subscribeUrl)) {
                return new Response('', Response::HTTP_FORBIDDEN);
            }

            $this->httpClient->request('GET', $subscribeUrl, ['timeout' => 5])->getContent();
            $this->logger->info('SES events: SNS subscription confirmed', ['topic' => $message['TopicArn'] ?? null]);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ($type === 'Notification' && is_string($message['Message'] ?? null) && is_string($message['MessageId'] ?? null)) {
            $this->messageBus->dispatch(new ProcessSesEvent($message['Message'], $message['MessageId']));
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
