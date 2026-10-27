<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\ListingRequestItem;
use SpeedPuzzling\Web\Entity\ListingRequestOffer;
use SpeedPuzzling\Web\Exceptions\ListingRequestNotFound;
use SpeedPuzzling\Web\Message\SendListingRequestEmail;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\ListingRequest\ListingRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail to the seller about a listing request (docs/features/marketplace/12-listing-requests.md), on the
 * notifications stream (a hard-bounced address is dropped by MailStreamRouting) with a one-click unsubscribe of
 * `player.listing_request_emails`. Read fresh: nothing is sent when the request is gone, nothing in it still waits (the
 * buyer withdrew, the listings were sold meanwhile), the seller switched these e-mails off, or has no account e-mail.
 *
 * It names who asks - asking was their consent - which listings at what price, and what they offer in exchange. Nobody's
 * e-mail address is in it: the seller answers in MySpeedPuzzling.
 */
#[AsMessageHandler]
readonly final class SendListingRequestEmailHandler
{
    public function __construct(
        private ListingRequestRepository $listingRequestRepository,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private ListingRequestEmailsUnsubscribeUrl $unsubscribeUrl,
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendListingRequestEmail $message): void
    {
        try {
            $request = $this->listingRequestRepository->get($message->requestId);
        } catch (ListingRequestNotFound) {
            // The buyer or the seller was deleted meanwhile
            return;
        }

        $seller = $request->seller;
        $buyer = $request->buyer;

        $waiting = array_values(array_filter(
            $this->listingRequestRepository->itemsOf($request),
            static fn (ListingRequestItem $item): bool => $item->status === ListingRequestItemStatus::Waiting,
        ));

        if ($waiting === [] || $seller->listingRequestEmails === false) {
            return;
        }

        $sellerEmail = $this->playerAccountEmail->ofPlayer($seller);

        if ($sellerEmail === null) {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($seller->locale);
        $buyerName = $buyer->name ?? '#' . strtoupper($buyer->code);
        $sellerId = $seller->id->toString();
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($sellerId, $locale);

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($sellerEmail)
            ->locale($locale)
            ->subject($this->translator->trans('listing_request.subject', [
                '%buyer%' => $buyerName,
                '%count%' => count($waiting),
            ], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/listing_request.html.twig')
            ->context([
                'locale' => $locale,
                'sellerName' => $seller->name ?? '#' . strtoupper($seller->code),
                'buyerName' => $buyerName,
                'listings' => array_map(static fn (ListingRequestItem $item): array => [
                    'name' => $item->puzzle->name,
                    'pieces' => $item->puzzle->piecesCount,
                    'brand' => $item->puzzle->manufacturer?->name,
                    'type' => $item->listingType->value,
                    'price' => $item->price,
                    'currency' => $item->currency,
                ], $waiting),
                'offers' => array_map(static fn (ListingRequestOffer $offer): array => [
                    'name' => $offer->puzzle->name,
                    'pieces' => $offer->puzzle->piecesCount,
                    'brand' => $offer->puzzle->manufacturer?->name,
                ], $this->listingRequestRepository->offersOf($request)),
                'note' => $request->note,
                'answerUrl' => $this->urlGenerator->generate('listing_request_detail', [
                    '_locale' => $locale,
                    'requestId' => $request->id->toString(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($sellerId, $sellerEmail, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'notifications');
        // One-click unsubscribe (RFC 8058), like the borrow request e-mails
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);
    }
}
