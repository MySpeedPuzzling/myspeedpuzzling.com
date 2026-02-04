<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerAlreadyClaimedVoucher;
use SpeedPuzzling\Web\Exceptions\VoucherAlreadyUsed;
use SpeedPuzzling\Web\Exceptions\VoucherExpired;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;
use SpeedPuzzling\Web\Exceptions\VoucherUsageLimitReached;
use SpeedPuzzling\Web\FormData\ClaimVoucherFormData;
use SpeedPuzzling\Web\FormType\ClaimVoucherFormType;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Results\ClaimVoucherResult;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
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

        $result = null;

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

                /** @var HandledStamp|null $handledStamp */
                $handledStamp = $envelope->last(HandledStamp::class);

                if ($handledStamp !== null) {
                    $result = $handledStamp->getResult();
                    assert($result instanceof ClaimVoucherResult);

                    if ($result->redirectToMembership) {
                        $this->addFlash(
                            'success',
                            sprintf('Voucher claimed! You have a %d%% discount waiting for you.', $result->percentageDiscount),
                        );

                        return $this->redirectToRoute('membership');
                    }
                }
            } catch (VoucherNotFound) {
                $this->addFlash('danger', 'Invalid voucher code. Please check and try again.');
            } catch (VoucherAlreadyUsed) {
                $this->addFlash('danger', 'This voucher has already been used.');
            } catch (VoucherExpired) {
                $this->addFlash('danger', 'This voucher has expired.');
            } catch (VoucherUsageLimitReached) {
                $this->addFlash('danger', 'This voucher has reached its usage limit.');
            } catch (PlayerAlreadyClaimedVoucher) {
                $this->addFlash('danger', 'You have already claimed this voucher.');
            }
        }

        return $this->render('claim_voucher.html.twig', [
            'form' => $form,
            'result' => $result,
        ]);
    }
}
