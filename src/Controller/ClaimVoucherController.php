<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerAlreadyClaimedVoucher;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHasLifetimeMembership;
use SpeedPuzzling\Web\Exceptions\VoucherAlreadyUsed;
use SpeedPuzzling\Web\Exceptions\VoucherExpired;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;
use SpeedPuzzling\Web\Exceptions\VoucherUsageLimitReached;
use SpeedPuzzling\Web\FormData\ClaimVoucherFormData;
use SpeedPuzzling\Web\FormType\ClaimVoucherFormType;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Results\ClaimVoucherResult;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\VoucherType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ClaimVoucherController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uplatnit-voucher',
            'en' => '/en/claim-voucher',
            'es' => '/es/canjear-voucher',
            'ja' => '/ja/バウチャー引換',
            'fr' => '/fr/utiliser-voucher',
            'de' => '/de/gutschein-einloesen',
        ],
        name: 'claim_voucher',
    )]
    public function __invoke(Request $request): Response
    {
        $data = new ClaimVoucherFormData();

        // Pre-populate code from query parameter (e.g., from QR code scan)
        $codeFromQuery = $request->query->getString('code');
        if ($codeFromQuery !== '') {
            $data->code = strtoupper($codeFromQuery);
        }

        $form = $this->createForm(ClaimVoucherFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile = $this->retrieveLoggedUserProfile->getProfile();

            if ($profile === null) {
                return $this->redirectToRoute('my_profile');
            }

            try {
                $envelope = $this->messageBus->dispatch(
                    new ClaimVoucher(
                        playerId: $profile->playerId,
                        voucherCode: $data->code,
                    ),
                );
            } catch (HandlerFailedException $e) {
                $nested = $e->getPrevious() ?? $e;

                // Re-entering a code you already redeemed is not an error - say it is in place and show what it gives
                if ($nested instanceof PlayerAlreadyClaimedVoucher) {
                    $this->addFlash('success', $this->translator->trans('claim_voucher.claimed.already_claimed', [
                        '%code%' => strtoupper(trim($data->code)),
                    ]));

                    return $this->redirectToRoute('membership');
                }

                $error = match (true) {
                    $nested instanceof VoucherNotFound => 'claim_voucher.errors.not_found',
                    $nested instanceof VoucherAlreadyUsed => 'claim_voucher.errors.already_used',
                    $nested instanceof VoucherExpired => 'claim_voucher.errors.expired',
                    $nested instanceof VoucherUsageLimitReached => 'claim_voucher.errors.usage_limit_reached',
                    $nested instanceof PlayerAlreadyHasLifetimeMembership => 'claim_voucher.errors.lifetime_member',
                    default => throw $e,
                };

                // A form error makes the form invalid, so render() answers 422 - Turbo Drive
                // silently discards a 200 answer to a form submission, and the error with it
                $form->get('code')->addError(new FormError($this->translator->trans($error)));

                return $this->render('claim_voucher.html.twig', [
                    'form' => $form,
                ]);
            }

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            $result = $handledStamp->getResult();
            assert($result instanceof ClaimVoucherResult);

            // Always redirect: Turbo Drive discards a 200 answer to a form submission, so a success
            // page rendered here would never reach the browser - the voucher gets claimed while the
            // visitor sees nothing happen
            $this->addFlash('success', match ($result->voucherType) {
                VoucherType::PercentageDiscount => $this->translator->trans(
                    $result->redirectToMembership ? 'claim_voucher.claimed.discount_waiting' : 'claim_voucher.claimed.discount_applied',
                    ['%discount%' => $result->percentageDiscount],
                ),
                VoucherType::Lifetime => $this->translator->trans('claim_voucher.claimed.lifetime'),
                VoucherType::FreeMonths => $this->translator->trans('claim_voucher.claimed.free_months', [
                    '%count%' => $result->freeMonths,
                ]),
            });

            return $this->redirectToRoute('membership');
        }

        return $this->render('claim_voucher.html.twig', [
            'form' => $form,
        ]);
    }
}
