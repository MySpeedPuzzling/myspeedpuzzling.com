<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2ClientRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\RequestOAuth2ClientAccess;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class RequestOAuth2ClientAccessHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
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

        $subject = $this->translator->trans(
            'oauth2_client_request.subject',
            ['%clientName%' => $message->clientName],
            domain: 'emails',
        );

        $email = (new TemplatedEmail())
            ->to('jan.mikes@myspeedpuzzling.com')
            ->subject($subject)
            ->htmlTemplate('emails/oauth2_client_request.html.twig')
            ->context([
                'playerName' => $player->name ?? 'Unknown',
                'clientName' => $message->clientName,
                'purpose' => $message->purpose,
                'adminUrl' => $adminUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
