<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Your results" e-mail (docs/features/duplicate-results.md, "Telling players") - subject, template, context and
 * headers in one place, so the real sending and the preview (`myspeedpuzzling:send-result-review-email-preview`)
 * can never drift apart.
 */
readonly final class ResultReviewEmailComposer
{
    public const int LISTED_MAX = 3;

    public function __construct(
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private ResultEmailsUnsubscribeUrl $unsubscribeUrl,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<ResultReviewEmailCase> $cases the open cases, the strongest of each set first
     * @param list<AutoRemovedResult> $removals
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
    ): TemplatedEmail {
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($playerId, $locale);
        $subjectKey = match (true) {
            $cases === [] => 'result_review.subject_removed',
            $first => 'result_review.subject_first',
            default => 'result_review.subject_weekly',
        };

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
            ->subject($this->translator->trans($subjectKey, domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/result_review.html.twig')
            ->context([
                'contactId' => $contactId,
                'first' => $first,
                'playerName' => $playerName,
                'sets' => array_slice($sets, 0, self::LISTED_MAX),
                'moreSets' => max(0, count($sets) - self::LISTED_MAX),
                'removals' => array_slice($removals, 0, self::LISTED_MAX),
                'moreRemovals' => max(0, count($removals) - self::LISTED_MAX),
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
