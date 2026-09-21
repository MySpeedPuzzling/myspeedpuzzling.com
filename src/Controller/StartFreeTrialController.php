<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\FreeTrialNotAvailable;
use SpeedPuzzling\Web\Exceptions\FreeTrialNotUnlockedYet;
use SpeedPuzzling\Web\Message\StartFreeTrial;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\FreeTrial;
use SpeedPuzzling\Web\Value\FreeTrialSource;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One click starts the free trial (docs/features/free-trial/README.md). Always answers with a
 * redirect - Turbo Drive drops a 200 answer to a form submission.
 */
final class StartFreeTrialController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'start_free_trial';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/clenstvi/vyzkouset-zdarma',
            'en' => '/en/membership/start-free-trial',
            'es' => '/es/membresia/prueba-gratuita',
            'ja' => '/ja/メンバーシップ/無料トライアル',
            'fr' => '/fr/adhesion/essai-gratuit',
            'de' => '/de/mitgliedschaft/kostenlos-testen',
        ],
        name: 'start_free_trial',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('homepage');
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('membership');
        }

        $source = FreeTrialSource::tryFrom((string) $request->request->get('source')) ?? FreeTrialSource::MembershipPage;

        try {
            $this->messageBus->dispatch(new StartFreeTrial($profile->playerId, $source));
        } catch (HandlerFailedException $e) {
            // Too young an account or too few puzzles logged - the membership page spells out what is missing
            if ($this->isCausedBy($e, FreeTrialNotUnlockedYet::class)) {
                return $this->redirectToRoute('membership');
            }

            // Only "this player cannot have a trial" is an answer - anything else is a failure and must look like one.
            // The unique index on membership.player_id is what stops two requests racing each other.
            if ($this->isCausedBy($e, FreeTrialNotAvailable::class, UniqueConstraintViolationException::class) === false) {
                throw $e;
            }

            // A second click, another tab, a membership bought in the meantime - routine, not an incident
            $this->logger->info('Free trial could not be started', [
                'player_id' => $profile->playerId,
                'exception' => $e,
            ]);

            $this->addFlash('warning', $this->translator->trans('free_trial.not_available'));

            return $this->redirectToRoute('membership');
        }

        // From a modal the player goes back to the page they were on - now unlocked
        $return = $request->request->get('return');
        $returnUrl = ReturnUrl::tryFrom(is_string($return) ? $return : null);

        if ($returnUrl !== null) {
            $this->addFlash('success', $this->translator->trans('free_trial.flash.started', [
                '%days%' => FreeTrial::DAYS,
            ]));

            return $this->redirect($returnUrl->path);
        }

        return $this->redirectToRoute('free_trial_started');
    }

    /**
     * @param class-string<\Throwable> ...$causes
     */
    private function isCausedBy(HandlerFailedException $exception, string ...$causes): bool
    {
        foreach ($exception->getWrappedExceptions(recursive: true) as $wrapped) {
            foreach ($causes as $cause) {
                if ($wrapped instanceof $cause) {
                    return true;
                }
            }
        }

        return false;
    }
}
