<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ListingRequest;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ListingRequest;
use SpeedPuzzling\Web\Exceptions\ListingRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\ListingRequestTooLarge;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Message\AskForListings;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ListingAskOutcome;
use SpeedPuzzling\Web\Value\ListingAskSkipReason;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Send of the listing request sheet (docs/features/marketplace/12-listing-requests.md): `listings[<sellerId>][]`,
 * `offers[<sellerId>][]` and `notes[<sellerId>]` → AskForListings. In the modal the answer closes it and shows one toast
 * linking to the sent requests; the form carries data-listing-selection-form, so the selection is cleared.
 */
final class SendListingRequestsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'listing_request';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/sell-swap-requests/send',
        name: 'listing_ask_send',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $buyer = $this->retrieveLoggedUserProfile->getProfile();

        if ($buyer === null) {
            return $this->redirectToRoute('login');
        }

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $sendId = $request->request->getString('sendId');

        if (Uuid::isValid($sendId) === false) {
            throw $this->createAccessDeniedException();
        }

        $notes = [];

        foreach ($request->request->all('notes') as $sellerId => $note) {
            if (is_string($note)) {
                $notes[strtolower((string) $sellerId)] = mb_substr($note, 0, ListingRequest::NOTE_MAX_LENGTH);
            }
        }

        try {
            // Too large and the limit are HTTP exceptions, unwrapped by UnwrapHttpExceptionMiddleware
            $envelope = $this->messageBus->dispatch(new AskForListings(
                sendId: $sendId,
                buyerId: $buyer->playerId,
                listingsBySeller: $this->idsBySeller($request, 'listings'),
                offersBySeller: $this->idsBySeller($request, 'offers'),
                notes: $notes,
            ));
        } catch (ListingRequestLimitReached) {
            return $this->refused($request, 'listing_request.flash.limit_reached');
        } catch (ListingRequestTooLarge) {
            return $this->refused($request, 'listing_request.flash.too_large');
        } catch (HandlerFailedException $exception) {
            if ($exception->getWrappedExceptions(MessagingMuted::class, true) === []) {
                throw $exception;
            }

            return $this->refused($request, 'listing_request.flash.muted');
        }

        /** @var ListingAskOutcome $outcome */
        $outcome = $envelope->last(HandledStamp::class)?->getResult();

        $toast = $this->translator->trans('listing_request.flash.sent', [
            '%count%' => $outcome->requestsSent,
            '%listings%' => $outcome->listingsAsked,
        ]);
        $skipped = $outcome->skippedFor(...ListingAskSkipReason::cases());

        if ($skipped > 0) {
            $toast .= ' ' . $this->translator->trans('listing_request.flash.skipped', ['%count%' => $skipped]);
        }

        if ($request->headers->get('Turbo-Frame') !== 'modal-frame') {
            $this->addFlash('success', $toast);

            return $this->redirectToRoute('listing_requests', status: Response::HTTP_SEE_OTHER);
        }

        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return new Response(
            $this->renderView('_modal_close_stream.html.twig') . $this->renderView('_toast_stream.html.twig', [
                'message' => $toast,
                'link_url' => $this->generateUrl('listing_requests'),
                'link_label' => $this->translator->trans('listing_request.flash.open'),
            ]),
            headers: ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function idsBySeller(Request $request, string $field): array
    {
        $bySeller = [];

        foreach ($request->request->all($field) as $sellerId => $ids) {
            if (is_array($ids)) {
                $bySeller[(string) $sellerId] = array_values(array_filter($ids, is_string(...)));
            }
        }

        return $bySeller;
    }

    private function refused(Request $request, string $message): Response
    {
        if ($request->headers->get('Turbo-Frame') !== 'modal-frame') {
            $this->addFlash('warning', $this->translator->trans($message, [
                '%sellers%' => AskForListings::MAX_SELLERS,
                '%listings%' => AskForListings::MAX_LISTINGS,
            ]));

            return $this->redirectToRoute('marketplace', status: Response::HTTP_SEE_OTHER);
        }

        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        // 422: the send failed, so the buyer's selection stays (listing_selection_controller.js clears it on success
        // only) - Turbo renders a stream answer of any status
        return new Response(
            $this->renderView('_modal_close_stream.html.twig') . $this->renderView('_toast_stream.html.twig', [
                'message' => $this->translator->trans($message, [
                    '%sellers%' => AskForListings::MAX_SELLERS,
                    '%listings%' => AskForListings::MAX_LISTINGS,
                ]),
                'type' => 'warning',
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
