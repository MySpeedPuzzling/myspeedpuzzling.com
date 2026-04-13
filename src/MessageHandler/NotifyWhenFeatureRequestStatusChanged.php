<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\FeatureRequestStatusChanged;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class NotifyWhenFeatureRequestStatusChanged
{
    public function __construct(
        private FeatureRequestRepository $featureRequestRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(FeatureRequestStatusChanged $event): void
    {
        $featureRequest = $this->featureRequestRepository->get($event->featureRequestId->toString());
        $player = $featureRequest->author;

        if ($player->email === null) {
            return;
        }

        $playerLocale = $player->locale ?? 'en';

        $oldStatusLabel = $this->translator->trans(
            'feature_request_status_changed.status.' . $event->oldStatus->value,
            domain: 'emails',
            locale: $playerLocale,
        );

        $newStatusLabel = $this->translator->trans(
            'feature_request_status_changed.status.' . $event->newStatus->value,
            domain: 'emails',
            locale: $playerLocale,
        );

        $subject = $this->translator->trans(
            'feature_request_status_changed.subject',
            ['%title%' => $featureRequest->title],
            domain: 'emails',
            locale: $playerLocale,
        );

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($playerLocale)
            ->subject($subject)
            ->htmlTemplate('emails/feature_request_status_changed.html.twig')
            ->context([
                'featureRequestTitle' => $featureRequest->title,
                'oldStatus' => $oldStatusLabel,
                'newStatus' => $newStatusLabel,
                'adminComment' => $featureRequest->adminComment,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
