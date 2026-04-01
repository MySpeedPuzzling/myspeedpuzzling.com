<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2ClientRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\RequestOAuth2ClientAccess;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class RequestOAuth2ClientAccessHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestOAuth2ClientAccess $message): void
    {
        $player = $this->entityManager->find(Player::class, $message->playerId);
        assert($player !== null);

        $now = new DateTimeImmutable();

        $request = new OAuth2ClientRequest(
            id: Uuid::fromString($message->requestId),
            player: $player,
            clientName: $message->clientName,
            clientDescription: $message->clientDescription,
            purpose: $message->purpose,
            applicationType: $message->applicationType,
            requestedScopes: $message->requestedScopes,
            redirectUris: $message->redirectUris,
            fairUsePolicyAcceptedAt: $now,
            createdAt: $now,
        );

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $adminUrl = $this->urlGenerator->generate('admin_oauth2_client_request_detail', [
            'requestId' => $message->requestId,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->to('jan.mikes@myspeedpuzzling.com')
            ->subject('New OAuth2 Client Request: ' . $message->clientName)
            ->html(sprintf(
                '<p>New OAuth2 client access request from <strong>%s</strong>.</p>'
                . '<p>Application: <strong>%s</strong></p>'
                . '<p>Purpose: %s</p>'
                . '<p><a href="%s">Review in admin</a></p>',
                $player->name ?? 'Unknown',
                $message->clientName,
                $message->purpose,
                $adminUrl,
            ));

        $this->mailer->send($email);
    }
}
