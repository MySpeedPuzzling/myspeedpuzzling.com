<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\RoundResultsPublisher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class PublishRoundResultsHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private Connection $database,
        private ClockInterface $clock,
        private RoundResultsPublisher $publisher,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(PublishRoundResults $message): void
    {
        $round = $this->roundRepository->get($message->roundId);

        $alreadyPublished = $round->areResultsPublished();
        $round->publishResults($this->clock->now());
        $this->publisher->publishPublicationChanged($round->id->toString(), true);

        if ($message->notifyParticipants === false || $alreadyPublished === true) {
            return;
        }

        $competition = $round->competition;

        $eventUrl = $this->urlGenerator->generate('event_detail', [
            'slug' => $competition->slug,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($this->getNotifiableRecipients($message->roundId) as $recipient) {
            $locale = $recipient['locale'] ?? 'en';

            $subject = $this->translator->trans(
                'competition_results_published.subject',
                ['%competitionName%' => $competition->name],
                domain: 'emails',
                locale: $locale,
            );

            $email = (new TemplatedEmail())
                ->to($recipient['email'])
                ->locale($locale)
                ->subject($subject)
                ->htmlTemplate('emails/competition_results_published.html.twig')
                ->context([
                    'competitionName' => $competition->name,
                    'roundName' => $round->name,
                    'eventUrl' => $eventUrl,
                ]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        }
    }

    /**
     * Connected players of participants with a result in this round, deduped by email.
     *
     * @return array<array{email: string, locale: null|string}>
     */
    private function getNotifiableRecipients(string $roundId): array
    {
        $query = <<<SQL
SELECT DISTINCT p.email, p.locale
FROM round_result rr
LEFT JOIN competition_participant direct_cp ON direct_cp.id = rr.participant_id
LEFT JOIN competition_participant_round cpr ON cpr.team_id = rr.team_id
LEFT JOIN competition_participant team_cp ON team_cp.id = cpr.participant_id AND team_cp.deleted_at IS NULL
INNER JOIN player p ON p.id = COALESCE(direct_cp.player_id, team_cp.player_id)
WHERE rr.round_id = :roundId
AND p.email IS NOT NULL
SQL;

        /** @var array<array{email: string, locale: null|string}> $rows */
        $rows = $this->database->executeQuery($query, ['roundId' => $roundId])->fetchAllAssociative();

        return $rows;
    }
}
