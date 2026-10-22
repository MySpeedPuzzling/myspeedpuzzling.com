<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendPartnerTeamedUpEmail;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
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
 * The e-mail beside the PartnerTeamedUp notification (docs/features/partner-finder.md "Phase 2 as built"): who named the
 * player, and a way to update their own listing - nobody's listing is ever closed for them; when they close it too,
 * both sides agreed. Read fresh: nothing when the player's listing at the event is closed meanwhile, they switched
 * these e-mails off (`player.partner_finder_emails`) or there is a block either way by now.
 */
#[AsMessageHandler]
readonly final class SendPartnerTeamedUpEmailHandler
{
    public function __construct(
        private PartnerListingRepository $partnerListingRepository,
        private PlayerRepository $playerRepository,
        private GetUserBlocks $getUserBlocks,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private PartnerFinderEmailsUnsubscribeUrl $unsubscribeUrl,
        private PartnerFinderUrls $partnerFinderUrls,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendPartnerTeamedUpEmail $message): void
    {
        try {
            $listing = $this->partnerListingRepository->get($message->listingId);
            $partner = $this->playerRepository->get($message->partnerPlayerId);
        } catch (PartnerListingNotFound | PlayerNotFound) {
            return;
        }

        $closer = $listing->player;
        $competition = $listing->competition;
        $closerId = $closer->id->toString();
        $partnerId = $partner->id->toString();

        if (
            $partner->partnerFinderEmails === false
            || $this->partnerListingRepository->findOpenFor($competition->id->toString(), $partnerId) === null
            || $this->getUserBlocks->isBlocked($partnerId, $closerId)
            || $this->getUserBlocks->isBlocked($closerId, $partnerId)
        ) {
            return;
        }

        $email = $this->playerAccountEmail->ofPlayer($partner);
        $locale = ListmonkNewsletterLists::normalizeLocale($partner->locale);
        $finderUrl = $this->partnerFinderUrls->forCompetition($competition, $locale, absolute: true);

        if ($email === null || $finderUrl === null) {
            return;
        }

        $closerName = $closer->name ?? $closer->code;
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($partnerId, $locale);

        $templatedEmail = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($email)
            ->locale($locale)
            ->subject($this->translator->trans('partner_teamed_up.subject', [
                '%name%' => $closerName,
                '%event%' => $competition->name,
            ], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/partner_teamed_up.html.twig')
            ->context([
                'locale' => $locale,
                'playerName' => $partner->name ?? $partner->code,
                'closerName' => $closerName,
                'eventName' => $competition->name,
                'finderUrl' => $finderUrl,
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($partnerId, $email, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $templatedEmail->getHeaders()->addTextHeader('X-Transport', 'notifications');
        $templatedEmail->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $templatedEmail->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($templatedEmail);
    }
}
