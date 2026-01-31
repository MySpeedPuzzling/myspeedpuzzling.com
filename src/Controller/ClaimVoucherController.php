<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\VoucherAlreadyUsed;
use SpeedPuzzling\Web\Exceptions\VoucherExpired;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;
use SpeedPuzzling\Web\FormData\ClaimVoucherFormData;
use SpeedPuzzling\Web\FormType\ClaimVoucherFormType;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
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

        $form = $this->createForm(ClaimVoucherFormType::class, $data);
        $form->handleRequest($request);

        $success = false;

        if ($form->isSubmitted() && $form->isValid()) {
            $profile = $this->retrieveLoggedUserProfile->getProfile();

            if ($profile === null) {
                return $this->redirectToRoute('my_profile');
            }

            try {
                $this->messageBus->dispatch(
                    new ClaimVoucher(
                        playerId: $profile->playerId,
                        voucherCode: $data->code,
                    ),
                );

                $success = true;
            } catch (VoucherNotFound) {
                $this->addFlash('danger', 'Invalid voucher code. Please check and try again.');
            } catch (VoucherAlreadyUsed) {
                $this->addFlash('danger', 'This voucher has already been used.');
            } catch (VoucherExpired) {
                $this->addFlash('danger', 'This voucher has expired.');
            }
        }

        return $this->render('claim_voucher.html.twig', [
            'form' => $form,
            'success' => $success,
        ]);
    }
}
