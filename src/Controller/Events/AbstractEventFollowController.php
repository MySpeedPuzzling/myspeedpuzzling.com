<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\FollowTargetNotAvailable;
use SpeedPuzzling\Web\Message\FollowCompetition;
use SpeedPuzzling\Web\Message\UnfollowCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The star of the events page (docs/features/events-page/README.md, "Follow"): a stateless-CSRF POST form. With
 * JavaScript it is sent with `Accept: application/json` and answered `{"following": bool, "target": "series:…"}`;
 * without, it redirects back (303) to `return` (or the events page) with a flash - never a 200 to a form submission.
 */
abstract class AbstractEventFollowController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the star sits on a page every player opens
    public const string CSRF_TOKEN_ID = 'event_follow';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private CompetitionRepository $competitionRepository,
        readonly private CompetitionSeriesRepository $competitionSeriesRepository,
    ) {
    }

    protected function handle(Request $request, bool $follow): Response
    {
        $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json');
        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            return $this->failure($wantsJson, $returnUrl, 'events_organizer.follow.try_again', Response::HTTP_UNPROCESSABLE_ENTITY, 'warning');
        }

        $target = FollowTarget::tryFromString($request->request->getString('target'));

        if ($target === null) {
            return $this->failure($wantsJson, $returnUrl, 'events_organizer.follow.not_available', Response::HTTP_NOT_FOUND, 'danger');
        }

        try {
            $this->messageBus->dispatch($follow
                ? new FollowCompetition($viewer->playerId, $target->toString())
                : new UnfollowCompetition($viewer->playerId, $target->toString()));
        } catch (FollowTargetNotAvailable) {
            return $this->failure($wantsJson, $returnUrl, 'events_organizer.follow.not_available', Response::HTTP_NOT_FOUND, 'danger');
        } catch (UniqueConstraintViolationException) {
            // The SerializedByLock lock normally keeps a double tap from inserting twice; when the lock fails open
            // the unique index catches the second insert - it is followed either way, the same outcome
        } catch (HandlerFailedException $exception) {
            if ($this->isDuplicate($exception) === false) {
                throw $exception;
            }
        }

        if ($wantsJson) {
            return $this->privateJson(['following' => $follow, 'target' => $target->toString()]);
        }

        $this->addFlash('success', $this->translator->trans(
            $follow ? 'events_organizer.follow.flash_followed' : 'events_organizer.follow.flash_unfollowed',
            ['%name%' => $this->nameOf($target)],
        ));

        return $this->back($returnUrl);
    }

    private function failure(bool $wantsJson, null|ReturnUrl $returnUrl, string $message, int $status, string $flashType): Response
    {
        if ($wantsJson) {
            return $this->privateJson(['error' => $this->translator->trans($message)], $status);
        }

        $this->addFlash($flashType, $this->translator->trans($message));

        return $this->back($returnUrl);
    }

    private function back(null|ReturnUrl $returnUrl): RedirectResponse
    {
        if ($returnUrl !== null) {
            return $this->redirect($returnUrl->path, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('events', status: Response::HTTP_SEE_OTHER);
    }

    private function nameOf(FollowTarget $target): string
    {
        try {
            return $target->isSeries()
                ? $this->competitionSeriesRepository->get($target->id)->name
                : $this->competitionRepository->get($target->id)->name;
        } catch (CompetitionNotFound | CompetitionSeriesNotFound) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function isDuplicate(HandlerFailedException $exception): bool
    {
        foreach ($exception->getWrappedExceptions(recursive: true) as $wrapped) {
            if ($wrapped instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }
}
