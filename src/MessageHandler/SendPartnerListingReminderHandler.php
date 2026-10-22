<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Message\SendPartnerListingReminder;
use SpeedPuzzling\Web\Query\CountNewPartnerListings;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Still looking for a partner for <event>?" two weeks before it (RemindPartnerListingsHandler, docs/features/
 * partner-finder.md "Phase 2 as built"): how many listed since, a way to the finder and to closing the listing. Nothing
 * when the listing closed meanwhile or the player switched these e-mails off (`player.partner_finder_emails`).
 */
#[AsMessageHandler]
readonly final class SendPartnerListingReminderHandler
{
    public function __construct(
        private PartnerListingRepository $partnerListingRepository,
        private CountNewPartnerListings $countNewPartnerListings,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private PartnerFinderEmailsUnsubscribeUrl $unsubscribeUrl,
        private PartnerFinderUrls $partnerFinderUrls,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendPartnerListingReminder $message): void
    {
        try {
            $listing = $this->partnerListingRepository->get($message->listingId);
        } catch (PartnerListingNotFound) {
            return;
        }

        $player = $listing->player;

        if ($listing->isOpen() === false || $player->partnerFinderEmails === false) {
            return;
        }

        $email = $this->playerAccountEmail->ofPlayer($player);
        $competition = $listing->competition;
        $locale = ListmonkNewsletterLists::normalizeLocale($player->locale);
        $finderUrl = $this->partnerFinderUrls->forCompetition($competition, $locale, absolute: true);

        if ($email === null || $finderUrl === null || $competition->dateFrom === null) {
            return;
        }

        $playerId = $player->id->toString();
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($playerId, $locale);

        $templatedEmail = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($email)
            ->locale($locale)
            ->subject($this->translator->trans('partner_reminder.subject', [
                '%event%' => $competition->name,
            ], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/partner_reminder.html.twig')
            ->context([
                'locale' => $locale,
                'playerName' => $player->name ?? $player->code,
                'eventName' => $competition->name,
                'dateFrom' => $competition->dateFrom,
                'dateTo' => $competition->dateTo,
                'newListings' => $this->countNewPartnerListings->since($listing),
                'finderUrl' => $finderUrl,
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($playerId, $email, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $templatedEmail->getHeaders()->addTextHeader('X-Transport', 'notifications');
        // One-click unsubscribe (RFC 8058), like the request e-mail
        $templatedEmail->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $templatedEmail->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($templatedEmail);
    }
}
