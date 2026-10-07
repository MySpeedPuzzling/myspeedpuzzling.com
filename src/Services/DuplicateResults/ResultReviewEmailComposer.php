<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Results\ResultReviewEmailMarkedTime;
use SpeedPuzzling\Web\Results\ResultReviewEmailVerificationAnswer;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Your results" e-mail (docs/features/duplicate-results.md, "Telling players") - subject, template, context and
 * headers in one place, so the real sending and the preview (`myspeedpuzzling:send-result-review-email-preview`)
 * can never drift apart.
 *
 * Its sections: results that may show up twice, copies removed automatically, times awaiting verification and the
 * moderators' answers to "The time is correct" (docs/features/suspicious-time-review.md, "Where they see it"). An
 * e-mail with verification content only has its own subject, title, intro and preheader; otherwise the duplicate
 * wording leads.
 */
readonly final class ResultReviewEmailComposer
{
    public const int LISTED_MAX = 3;

    // The review page's section of the times awaiting verification
    public const string VERIFICATION_ANCHOR = 'awaiting-verification';

    public function __construct(
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private ResultEmailsUnsubscribeUrl $unsubscribeUrl,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<ResultReviewEmailCase> $cases the open cases, the strongest of each set first
     * @param list<AutoRemovedResult> $removals
     * @param list<ResultReviewEmailMarkedTime> $markedTimes
     * @param list<ResultReviewEmailVerificationAnswer> $verificationAnswers
     */
    public function compose(
        string $contactId,
        string $playerId,
        null|string $playerName,
        string $emailAddress,
        string $locale,
        bool $first,
        array $cases,
        array $removals,
        array $markedTimes,
        array $verificationAnswers,
    ): TemplatedEmail {
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($playerId, $locale);
        $verificationOnly = $cases === [] && $removals === [];
        // Which subject, title, intro and preheader: result_review.{subject,title,intro,preheader}_<variant>
        $variant = match (true) {
            $verificationOnly && $markedTimes !== [] => 'verification',
            $verificationOnly && $verificationAnswers !== [] => 'verification_answered',
            $cases === [] => 'removed',
            $first => 'first',
            default => 'weekly',
        };
        // Only the verification wording counts: "a result" or "results"
        $count = $variant === 'verification_answered' ? count($verificationAnswers) : count($markedTimes);

        // One line per result, however many copies: a result saved by three teammates is three cases of one set.
        // The first case of a set is its strongest (the cases come ordered so) and describes it
        $sets = array_map(
            static fn (array $keys): ResultReviewEmailCase => $cases[$keys[0]],
            DuplicateSets::group(array_map(static fn (ResultReviewEmailCase $case): array => [$case->timeAId, $case->timeBId], $cases)),
        );

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($emailAddress)
            ->locale($locale)
            ->subject($this->translator->trans('result_review.subject_' . $variant, ['%count%' => $count], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/result_review.html.twig')
            ->context([
                'contactId' => $contactId,
                'variant' => $variant,
                'count' => $count,
                'playerName' => $playerName,
                'sets' => array_slice($sets, 0, self::LISTED_MAX),
                'moreSets' => max(0, count($sets) - self::LISTED_MAX),
                'removals' => array_slice($removals, 0, self::LISTED_MAX),
                'moreRemovals' => max(0, count($removals) - self::LISTED_MAX),
                'markedTimes' => array_slice($markedTimes, 0, self::LISTED_MAX),
                'moreMarkedTimes' => max(0, count($markedTimes) - self::LISTED_MAX),
                // Every answer gets its line - each was asked for by the player
                'verificationAnswers' => $verificationAnswers,
                'reviewFragment' => $markedTimes !== [] || $verificationAnswers !== [] ? self::VERIFICATION_ANCHOR : null,
                'locale' => $locale,
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($playerId, $emailAddress, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'notifications');
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        return $email;
    }
}
