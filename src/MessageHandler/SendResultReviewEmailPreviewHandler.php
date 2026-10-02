<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\SendResultReviewEmailPreview;
use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewEmailComposer;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;
use SpeedPuzzling\Web\Value\ResultReviewEmailPreviewVariant;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A "Your results" e-mail with sample data, to see it in a real inbox before players get it
 * (docs/features/duplicate-results.md, "Sending"). Composed by the same ResultReviewEmailComposer as the real
 * e-mail - template, translations, headers, `notifications` transport - only the subject says "[Preview]".
 *
 * Writes nothing: no contact, no removal touched. The ids behind its links belong to nobody - the review button
 * opens the real review page (the `from=rc-` visit matches no contact, nothing is recorded), the unsubscribe and
 * settings links answer 404, so clicking them changes nothing for any player.
 */
#[AsMessageHandler]
readonly final class SendResultReviewEmailPreviewHandler
{
    public const string PREVIEW_PLAYER_ID = '00000000-0000-7000-8000-000000000000';
    public const string PREVIEW_CONTACT_ID = '00000000-0000-7000-8000-000000000001';
    public const string SUBJECT_PREFIX = '[Preview] ';

    public function __construct(
        private ResultReviewEmailComposer $emailComposer,
        private MailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendResultReviewEmailPreview $message): void
    {
        $now = $this->clock->now();
        $cases = $message->variant === ResultReviewEmailPreviewVariant::Removed ? [] : $this->sampleCases($now);

        $email = $this->emailComposer->compose(
            contactId: self::PREVIEW_CONTACT_ID,
            playerId: self::PREVIEW_PLAYER_ID,
            playerName: 'Alex',
            emailAddress: $message->emailAddress,
            locale: ListmonkNewsletterLists::normalizeLocale($message->locale),
            first: $message->variant === ResultReviewEmailPreviewVariant::First,
            cases: $cases,
            removals: $this->sampleRemovals($now),
        );
        $email->subject(self::SUBJECT_PREFIX . $email->getSubject());

        $this->mailer->send($email);
    }

    /**
     * Four results - three listed, "…and 1 more".
     *
     * @return list<ResultReviewEmailCase>
     */
    private function sampleCases(DateTimeImmutable $now): array
    {
        return [
            $this->sampleCase(1, DuplicateKind::SameTracker, 'Circle of Colors: Tropical', 4226, $now->modify('-9 days')),
            $this->sampleCase(2, DuplicateKind::TeammateCopy, 'Songs of Extinct Birds', 4928, $now->modify('-23 days')),
            $this->sampleCase(3, DuplicateKind::SoloAndGroup, 'Starry Night', 2773, $now->modify('-41 days')),
            $this->sampleCase(4, DuplicateKind::SameTracker, 'Wildlife Collage', 7395, $now->modify('-64 days')),
        ];
    }

    private function sampleCase(int $number, DuplicateKind $kind, string $puzzleName, int $seconds, DateTimeImmutable $solvedAt): ResultReviewEmailCase
    {
        return new ResultReviewEmailCase(
            caseId: sprintf('00000000-0000-7000-8000-0000000001%02d', $number),
            timeAId: sprintf('00000000-0000-7000-8000-0000000002%02d', $number),
            timeBId: sprintf('00000000-0000-7000-8000-0000000003%02d', $number),
            tier: DuplicateTier::Strong,
            kind: $kind,
            puzzleName: $puzzleName,
            secondsToSolve: $seconds,
            solvedAt: $solvedAt,
        );
    }

    /**
     * @return list<AutoRemovedResult>
     */
    private function sampleRemovals(DateTimeImmutable $now): array
    {
        return [
            new AutoRemovedResult(
                removalId: '00000000-0000-7000-8000-000000000401',
                puzzleId: '00000000-0000-7000-8000-000000000501',
                puzzleName: 'Disney Family',
                secondsToSolve: 5104,
                solvedAt: $now->modify('-5 days'),
                savedAt: $now->modify('-5 days'),
                removedAt: $now->modify('-1 day'),
                keptTimeId: '00000000-0000-7000-8000-000000000601',
            ),
        ];
    }
}
