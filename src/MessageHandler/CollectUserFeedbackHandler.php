<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CollectUserFeedback;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CollectUserFeedbackHandler
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(CollectUserFeedback $message): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $userEmail = $profile->email ?? 'anonymous@speedpuzzling.cz';

        $email = (new TemplatedEmail())
            ->to('simona@speedpuzzling.cz')
            ->replyTo($userEmail, 'simona@speedpuzzling.cz')
            ->subject('MySpeedPuzzling Feedback')
            ->htmlTemplate('emails/feedback.html.twig')
            ->context([
                'name' => $profile->playerName ?? $profile->playerId ?? 'Anonymous',
                'userEmail' => $userEmail,
                'url' => $message->url,
                'message' => $message->message,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
