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
        $templates = match ($event->newStatus) {
            FeatureRequestStatus::Completed => [
                'author' => 'emails/feature_request_completed.html.twig',
                'upvoter' => 'emails/feature_request_completed_upvoter.html.twig',
                'authorSubjectKey' => 'feature_request_completed.subject',
                'upvoterSubjectKey' => 'feature_request_completed.upvoter.subject',
            ],
            FeatureRequestStatus::Declined => [
                'author' => 'emails/feature_request_declined.html.twig',
                'upvoter' => 'emails/feature_request_declined_upvoter.html.twig',
                'authorSubjectKey' => 'feature_request_declined.subject',
                'upvoterSubjectKey' => 'feature_request_declined.upvoter.subject',
            ],
            default => null,
        };

        if ($templates === null) {
            return;
        }

        $featureRequest = $this->featureRequestRepository->get($event->featureRequestId->toString());
        $author = $featureRequest->author;

        if ($author->email !== null) {
            $this->sendEmail(
                toEmail: $author->email,
                locale: $author->locale ?? 'en',
                template: $templates['author'],
                subjectKey: $templates['authorSubjectKey'],
                featureRequestId: $event->featureRequestId->toString(),
                featureRequestTitle: $featureRequest->title,
                githubUrl: $featureRequest->githubUrl,
                adminComment: $featureRequest->adminComment,
            );
        }

        $voters = $this->getFeatureRequestVoters->excludingPlayer(
            featureRequestId: $event->featureRequestId->toString(),
            excludedPlayerId: $author->id->toString(),
        );

        foreach ($voters as $voter) {
            $this->sendEmail(
                toEmail: $voter->email,
                locale: $voter->locale ?? 'en',
                template: $templates['upvoter'],
                subjectKey: $templates['upvoterSubjectKey'],
                featureRequestId: $event->featureRequestId->toString(),
                featureRequestTitle: $featureRequest->title,
                githubUrl: $featureRequest->githubUrl,
                adminComment: $featureRequest->adminComment,
            );
        }
    }

    private function sendEmail(
        string $toEmail,
        string $locale,
        string $template,
        string $subjectKey,
        string $featureRequestId,
        string $featureRequestTitle,
        null|string $githubUrl,
        null|string $adminComment,
    ): void {
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
                'featureRequestId' => $featureRequestId,
                'featureRequestTitle' => $featureRequestTitle,
                'githubUrl' => $githubUrl,
                'adminComment' => $adminComment,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
