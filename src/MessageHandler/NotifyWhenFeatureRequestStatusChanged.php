<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\FeatureRequestStatusChanged;
use SpeedPuzzling\Web\Query\GetFeatureRequestVoters;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class NotifyWhenFeatureRequestStatusChanged
{
    public function __construct(
        private FeatureRequestRepository $featureRequestRepository,
        private GetFeatureRequestVoters $getFeatureRequestVoters,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(FeatureRequestStatusChanged $event): void
    {
        $featureRequest = $this->featureRequestRepository->get($event->featureRequestId->toString());
        $author = $featureRequest->author;

        if ($author->email !== null) {
            $this->sendEmail(
                toEmail: $author->email,
                locale: $author->locale ?? 'en',
                template: 'emails/feature_request_status_changed.html.twig',
                subjectKey: 'feature_request_status_changed.subject',
                featureRequestTitle: $featureRequest->title,
                oldStatus: $event->oldStatus,
                newStatus: $event->newStatus,
                adminComment: $featureRequest->adminComment,
            );
        }

        $voters = ($this->getFeatureRequestVoters)->excludingPlayer(
            featureRequestId: $event->featureRequestId->toString(),
            excludedPlayerId: $author->id->toString(),
        );

        foreach ($voters as $voter) {
            $this->sendEmail(
                toEmail: $voter->email,
                locale: $voter->locale ?? 'en',
                template: 'emails/feature_request_status_changed_upvoter.html.twig',
                subjectKey: 'feature_request_status_changed.upvoter.subject',
                featureRequestTitle: $featureRequest->title,
                oldStatus: $event->oldStatus,
                newStatus: $event->newStatus,
                adminComment: $featureRequest->adminComment,
            );
        }
    }

    private function sendEmail(
        string $toEmail,
        string $locale,
        string $template,
        string $subjectKey,
        string $featureRequestTitle,
        FeatureRequestStatus $oldStatus,
        FeatureRequestStatus $newStatus,
        null|string $adminComment,
    ): void {
        $oldStatusLabel = $this->translator->trans(
            'feature_request_status_changed.status.' . $oldStatus->value,
            domain: 'emails',
            locale: $locale,
        );

        $newStatusLabel = $this->translator->trans(
            'feature_request_status_changed.status.' . $newStatus->value,
            domain: 'emails',
            locale: $locale,
        );

        $subject = $this->translator->trans(
            $subjectKey,
            ['%title%' => $featureRequestTitle],
            domain: 'emails',
            locale: $locale,
        );

        $email = (new TemplatedEmail())
            ->to($toEmail)
            ->locale($locale)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context([
                'featureRequestTitle' => $featureRequestTitle,
                'oldStatus' => $oldStatusLabel,
                'newStatus' => $newStatusLabel,
                'adminComment' => $adminComment,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
