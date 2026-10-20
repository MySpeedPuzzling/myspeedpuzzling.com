<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\FeatureRequestStatusChanged;
use SpeedPuzzling\Web\Query\GetFeatureRequestVoters;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\FeatureRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\FeatureRequestEmails;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Completed or declined request → one e-mail per person (docs/features/feature-requests.md): the author, the upvoters
 * and everybody following every request (FeatureRequestEmails::All), each with its own wording. A player with
 * FeatureRequestEmails::None gets nothing, not even about their own request. Every e-mail carries a one-click
 * unsubscribe.
 */
#[AsMessageHandler]
readonly final class NotifyWhenFeatureRequestStatusChanged
{
    public function __construct(
        private FeatureRequestRepository $featureRequestRepository,
        private GetFeatureRequestVoters $getFeatureRequestVoters,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private FeatureRequestEmailsUnsubscribeUrl $unsubscribeUrl,
    ) {
    }

    public function __invoke(FeatureRequestStatusChanged $event): void
    {
        $templates = match ($event->newStatus) {
            FeatureRequestStatus::Completed => [
                'author' => 'emails/feature_request_completed.html.twig',
                'upvoter' => 'emails/feature_request_completed_upvoter.html.twig',
                'follower' => 'emails/feature_request_completed_follower.html.twig',
                'authorSubjectKey' => 'feature_request_completed.subject',
                'upvoterSubjectKey' => 'feature_request_completed.upvoter.subject',
                'followerSubjectKey' => 'feature_request_completed.follower.subject',
            ],
            FeatureRequestStatus::Declined => [
                'author' => 'emails/feature_request_declined.html.twig',
                'upvoter' => 'emails/feature_request_declined_upvoter.html.twig',
                'follower' => 'emails/feature_request_declined_follower.html.twig',
                'authorSubjectKey' => 'feature_request_declined.subject',
                'upvoterSubjectKey' => 'feature_request_declined.upvoter.subject',
                'followerSubjectKey' => 'feature_request_declined.follower.subject',
            ],
            default => null,
        };

        if ($templates === null) {
            return;
        }

        $featureRequest = $this->featureRequestRepository->get($event->featureRequestId->toString());
        $author = $featureRequest->author;

        $authorEmail = $author === null || $author->featureRequestEmails === FeatureRequestEmails::None
            ? null
            : $this->playerAccountEmail->ofPlayer($author);

        if ($author !== null && $authorEmail !== null) {
            $this->sendEmail(
                playerId: $author->id->toString(),
                toEmail: $authorEmail,
                locale: $author->locale,
                template: $templates['author'],
                subjectKey: $templates['authorSubjectKey'],
                reason: 'author',
                featureRequestId: $event->featureRequestId->toString(),
                featureRequestTitle: $featureRequest->title,
                githubUrl: $featureRequest->githubUrl,
                adminComment: $featureRequest->adminComment,
            );
        }

        $excludedPlayerId = $author?->id->toString() ?? '';

        $voters = $this->getFeatureRequestVoters->excludingPlayer(
            featureRequestId: $event->featureRequestId->toString(),
            excludedPlayerId: $excludedPlayerId,
        );

        foreach ($voters as $voter) {
            $this->sendEmail(
                playerId: $voter->playerId,
                toEmail: $voter->email,
                locale: $voter->locale,
                template: $templates['upvoter'],
                subjectKey: $templates['upvoterSubjectKey'],
                reason: 'upvoter',
                featureRequestId: $event->featureRequestId->toString(),
                featureRequestTitle: $featureRequest->title,
                githubUrl: $featureRequest->githubUrl,
                adminComment: $featureRequest->adminComment,
            );
        }

        $followers = $this->getFeatureRequestVoters->followersOfAllRequests(
            featureRequestId: $event->featureRequestId->toString(),
            excludedPlayerId: $excludedPlayerId,
        );

        foreach ($followers as $follower) {
            $this->sendEmail(
                playerId: $follower->playerId,
                toEmail: $follower->email,
                locale: $follower->locale,
                template: $templates['follower'],
                subjectKey: $templates['followerSubjectKey'],
                reason: 'follower',
                featureRequestId: $event->featureRequestId->toString(),
                featureRequestTitle: $featureRequest->title,
                githubUrl: $featureRequest->githubUrl,
                adminComment: $featureRequest->adminComment,
            );
        }
    }

    /**
     * @param 'author'|'upvoter'|'follower' $reason why this person gets it - picks the small print under the e-mail
     */
    private function sendEmail(
        string $playerId,
        string $toEmail,
        null|string $locale,
        string $template,
        string $subjectKey,
        string $reason,
        string $featureRequestId,
        string $featureRequestTitle,
        null|string $githubUrl,
        null|string $adminComment,
    ): void {
        $locale ??= 'en';

        $subject = $this->translator->trans(
            $subjectKey,
            ['%title%' => $featureRequestTitle],
            domain: 'emails',
            locale: $locale,
        );

        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($playerId, $locale);

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
                'optOutKey' => 'feature_request_emails.opt_out.' . $reason,
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($playerId, $toEmail, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');
        // One-click unsubscribe (RFC 8058), like the unread-messages digest
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);
    }
}
