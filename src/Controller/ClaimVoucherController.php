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

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ClaimVoucherController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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

                $error = match (true) {
                    $nested instanceof VoucherNotFound => 'Invalid voucher code. Please check and try again.',
                    $nested instanceof VoucherAlreadyUsed => 'This voucher has already been used.',
                    $nested instanceof VoucherExpired => 'This voucher has expired.',
                    $nested instanceof VoucherUsageLimitReached => 'This voucher has reached its usage limit.',
                    $nested instanceof PlayerAlreadyClaimedVoucher => 'You have already claimed this voucher.',
                    $nested instanceof PlayerAlreadyHasLifetimeMembership => 'You already have a lifetime membership, so this voucher would not add anything. Pass it on to a fellow puzzler!',
                    default => throw $e,
                };

                // A form error makes the form invalid, so render() answers 422 - Turbo Drive
                // silently discards a 200 answer to a form submission, and the error with it
                $form->get('code')->addError(new FormError($error));

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
                VoucherType::PercentageDiscount => $result->redirectToMembership
                    ? sprintf('Voucher claimed! You have a %d%% discount waiting for you.', $result->percentageDiscount)
                    : sprintf('Voucher claimed! Your %d%% discount has been applied to your subscription.', $result->percentageDiscount),
                VoucherType::Lifetime => 'Voucher claimed! You are now a lifetime member. If you had a subscription, it has been cancelled and you will not be charged again.',
                VoucherType::FreeMonths => 'Voucher claimed! Your membership has been extended.',
            });

            return $this->redirectToRoute('membership');
        }

        return $this->render('claim_voucher.html.twig', [
            'form' => $form,
        ]);
    }
}
