<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PartnerRequestNotFound;
use SpeedPuzzling\Web\Message\SendPartnerRequestEmail;
use SpeedPuzzling\Web\Query\GetPlayerCard;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail to the listed puzzler about an "I'd like to puzzle with you" (docs/features/partner-finder.md D6), on the
 * notifications stream (a hard-bounced address is dropped by MailStreamRouting) with a one-click unsubscribe of
 * `player.partner_finder_emails`. Read fresh: nothing is sent when the request or its listing is gone, the listing
 * closed meanwhile, the receiver switched these e-mails off, or has no account e-mail.
 *
 * It names who asks - a private sender too: sending was their consent - and shows their public numbers (best 500 or
 * 1000, puzzles solved) only when their profile is public. Nobody's e-mail address is in it: the receiver answers in
 * MySpeedPuzzling.
 */
#[AsMessageHandler]
readonly final class SendPartnerRequestEmailHandler
{
    public function __construct(
        private PartnerRequestRepository $partnerRequestRepository,
        private PartnerListingRepository $partnerListingRepository,
        private GetPlayerCard $getPlayerCard,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private PartnerFinderEmailsUnsubscribeUrl $unsubscribeUrl,
        private PartnerFinderUrls $partnerFinderUrls,
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendPartnerRequestEmail $message): void
    {
        try {
            $request = $this->partnerRequestRepository->get($message->requestId);
        } catch (PartnerRequestNotFound) {
            // The listing (and with it the request) or the sender was deleted meanwhile
            return;
        }

        $listing = $request->listing;
        $receiver = $listing->player;
        $sender = $request->fromPlayer;

        if ($listing->isOpen() === false || $receiver->partnerFinderEmails === false) {
            return;
        }

        $receiverEmail = $this->playerAccountEmail->ofPlayer($receiver);

        if ($receiverEmail === null) {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($receiver->locale);
        $competition = $listing->competition;
        $senderName = $sender->name ?? $sender->code;
        $receiverId = $receiver->id->toString();

        $senderCard = $sender->isPrivate ? null : $this->getPlayerCard->byPlayerId($sender->id->toString(), false);
        $senderListing = $this->partnerListingRepository->findOpenFor($competition->id->toString(), $sender->id->toString());

        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($receiverId, $locale);

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($receiverEmail)
            ->locale($locale)
            ->subject($this->translator->trans('partner_request.subject', [
                '%sender%' => $senderName,
                '%event%' => $competition->name,
            ], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/partner_request.html.twig')
            ->context([
                'locale' => $locale,
                'receiverName' => $receiver->name ?? $receiver->code,
                'senderName' => $senderName,
                'senderCountry' => CountryCode::fromCode($sender->country)?->localizedName($locale),
                'senderBest500Seconds' => $senderCard?->best500Seconds,
                'senderBest1000Seconds' => $senderCard?->best1000Seconds,
                'senderSolvedTotal' => $senderCard?->solvedTotal,
                'senderLanguages' => array_map(strtoupper(...), $senderListing?->languages() ?? []),
                'note' => $request->note,
                'kind' => $request->kind,
                'eventName' => $competition->name,
                'replyUrl' => $this->urlGenerator->generate('partner_request_reply', [
                    '_locale' => $locale,
                    'requestId' => $request->id->toString(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'closeListingUrl' => $this->partnerFinderUrls->forCompetition($competition, $locale, absolute: true),
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($receiverId, $receiverEmail, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'notifications');
        // One-click unsubscribe (RFC 8058), like the unread-messages digest
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);
    }
}
