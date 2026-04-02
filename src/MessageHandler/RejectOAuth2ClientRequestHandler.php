<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\RejectOAuth2ClientRequest;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class RejectOAuth2ClientRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuth2ClientRequestRepository $requestRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RejectOAuth2ClientRequest $message): void
    {
        $request = $this->requestRepository->get($message->requestId);
        $admin = $this->entityManager->find(Player::class, $message->adminPlayerId);
        assert($admin !== null);

        $request->reject($admin, $message->reason);
        $this->entityManager->flush();

        $playerEmail = $request->player->email;

        if ($playerEmail !== null) {
            $playerLocale = $request->player->locale ?? 'en';

            $subject = $this->translator->trans(
                'oauth2_client_rejected.subject',
                domain: 'emails',
                locale: $playerLocale,
            );

            $email = (new TemplatedEmail())
                ->to($playerEmail)
                ->locale($playerLocale)
                ->subject($subject)
                ->htmlTemplate('emails/oauth2_client_rejected.html.twig')
                ->context([
                    'clientName' => $request->clientName,
                    'reason' => $message->reason,
                ]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        }
    }
}
