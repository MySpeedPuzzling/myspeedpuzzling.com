<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\RejectOAuth2ClientRequest;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class RejectOAuth2ClientRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuth2ClientRequestRepository $requestRepository,
        private MailerInterface $mailer,
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
            $email = (new Email())
                ->to($playerEmail)
                ->subject('Your OAuth2 application request was not approved')
                ->html(sprintf(
                    '<p>Your OAuth2 application request for <strong>%s</strong> was not approved.</p>'
                    . '<p>Reason: %s</p>'
                    . '<p>If you have questions, please contact us.</p>',
                    $request->clientName,
                    $message->reason,
                ));

            $this->mailer->send($email);
        }
    }
}
