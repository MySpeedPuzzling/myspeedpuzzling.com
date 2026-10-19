<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Digest;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\ContentDigestUnsubscribeUrl;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One player's activity digest or "We miss you" e-mail (docs/features/content-digest/README.md "Content"), ready to
 * send through the notifications stream - the real send (SendPlayerContentDigestHandler) and the preview
 * (SendContentDigestPreviewHandler) build the same e-mail.
 */
readonly final class ContentDigestEmailFactory
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private PlayerPeriodActivityReader $activityReader,
        private TranslatorInterface $translator,
        private ContentDigestUnsubscribeUrl $unsubscribeUrl,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private EventsPageDates $dates,
    ) {
    }

    public function create(
        Player $player,
        string $email,
        ContentDigestKind $kind,
        DigestPeriod $contentPeriod,
        ContentDigestFrequency $frequency,
    ): TemplatedEmail {
        $playerId = $player->id->toString();
        $locale = ListmonkNewsletterLists::normalizeLocale($player->locale);
        $profile = $this->getPlayerProfile->byId($playerId);
        $activity = $this->activityReader->read($playerId, $contentPeriod, $profile->activeMembership);
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($playerId, $locale);

        $templatedEmail = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($email)
            ->locale($locale)
            ->subject($kind === ContentDigestKind::Activity
                ? $this->activitySubject($contentPeriod, $locale)
                : $this->translator->trans('activity_digest.miss_you.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate($kind === ContentDigestKind::Activity ? 'emails/content_digest_activity.html.twig' : 'emails/content_digest_miss_you.html.twig')
            ->context([
                'playerName' => $player->name,
                'activity' => $activity,
                'period' => $contentPeriod,
                'frequency' => $frequency,
                'unsubscribeUrl' => $unsubscribeUrl,
                'preferencesUrl' => $this->emailPreferencesLinkGenerator->forPlayer($playerId, $email, $locale),
                'locale' => $locale,
            ]);

        $headers = $templatedEmail->getHeaders();
        $headers->addTextHeader('X-Transport', 'notifications');
        $headers->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('Precedence', 'bulk');

        return $templatedEmail;
    }

    /**
     * Short, the period and the site only: "Your week 12–18 Oct on MySpeedPuzzling", "Your October on MySpeedPuzzling"
     * (Jan 2026-10-19: no numbers - they made it too long). Dates by ICU skeletons in the e-mail's locale.
     */
    private function activitySubject(DigestPeriod $period, string $locale): string
    {
        $parameters = $period->length === DigestPeriodLength::Month
            ? ['%month%' => $this->dates->format($period->start, 'MMMM', $locale)]
            : ['%dates%' => $this->dates->range($period->start, $period->lastDay(), 'MMMd', $locale)];

        return $this->translator->trans('activity_digest.subject.' . $period->length->value, $parameters, 'emails', $locale);
    }
}
