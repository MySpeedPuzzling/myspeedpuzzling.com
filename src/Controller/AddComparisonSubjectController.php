<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SpeedPuzzling\Web\Exceptions\ComparisonLineUpFull;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotAvailable;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzlingTeamNotFound;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzlingTeamDetail;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Add to comparison" - the player header's compare button, its ⋯ menu and the pair/team page
 * (docs/features/player-comparison.md, D11). Always answers with a redirect (Turbo Drive drops a 200 answer to a form
 * submission): back to the page it was used on, with a flash that links the comparison. At the cap the comparison
 * opens with the swap prompt (`?swap=<ref>`) instead - never a dead end.
 */
final class AddComparisonSubjectController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the form sits on pages that are cached and shared
    public const string CSRF_TOKEN_ID = 'comparison_add';

    /** A flash of its own: {message: string, kind: null|string} - base.html.twig adds the "Open comparison" link */
    public const string ADDED_FLASH = 'comparison_added';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPuzzlingTeamDetail $getPuzzlingTeamDetail,
    ) {
    }

    #[Route(
        path: '/{_locale}/compare/add',
        name: 'comparison_add',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('homepage');
        }

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('warning', $this->translator->trans('comparison_entry.flash.try_again'));

            return $this->back($returnUrl, null);
        }

        $ref = ComparisonSubjectRef::tryFromString($request->request->getString('subject'));

        if ($ref === null) {
            return $this->notAvailable($returnUrl);
        }

        try {
            $this->messageBus->dispatch(new AddComparisonSubject($viewer->playerId, $ref->toString()));
        } catch (ComparisonLineUpFull $full) {
            // The comparison offers which one to swap out (and, without a membership, one quiet line about it)
            return $this->redirectToRoute('comparison', [
                'kind' => $full->kind->value,
                'swap' => $ref->toString(),
            ]);
        } catch (ComparisonSubjectNotAvailable | ComparisonSubjectNotFound) {
            return $this->notAvailable($returnUrl);
        } catch (UniqueConstraintViolationException) {
            // Adds of one owner wait for each other (SerializedByLock), so a double submit finds it there; only a team
            // merge repointing a row to this pair/team at the same moment can still collide - it is there, the same outcome
        } catch (HandlerFailedException $exception) {
            if ($this->isDuplicate($exception) === false) {
                throw $exception;
            }
        }

        [$message, $kind] = $this->addedMessage($ref, $viewer->playerId);

        $this->addFlash(self::ADDED_FLASH, [
            'message' => $message,
            'kind' => $kind?->value,
        ]);

        return $this->back($returnUrl, $kind);
    }

    /**
     * "Kateřina added to your comparison" and the line-up it went to. An unnamed pair/team is "Pair"/"Team" - its
     * member list would make a clumsy sentence. A subject gone for the viewer in the meantime gets no name.
     *
     * @return array{0: string, 1: null|ComparisonKind}
     */
    private function addedMessage(ComparisonSubjectRef $ref, string $viewerId): array
    {
        $unnamed = [$this->translator->trans('comparison_entry.flash.added_unnamed'), null];

        if ($ref->isPlayer()) {
            try {
                $player = $this->getPlayerProfile->byId($ref->id);
            } catch (PlayerNotFound) {
                return $unnamed;
            }

            if ($player->isPrivate && $player->playerId !== $viewerId) {
                return [$this->translator->trans('comparison_entry.flash.added_unnamed'), ComparisonKind::Solo];
            }

            return [
                $this->translator->trans('comparison_entry.flash.added', [
                    '%name%' => $player->playerName ?? '#' . strtoupper($player->code),
                ]),
                ComparisonKind::Solo,
            ];
        }

        try {
            $team = $this->getPuzzlingTeamDetail->byId($ref->id, $viewerId);
        } catch (PuzzlingTeamNotFound) {
            return $unnamed;
        }

        $kind = ComparisonKind::forTeamSize($team->size);

        if ($team->name !== null) {
            return [$this->translator->trans('comparison_entry.flash.added', ['%name%' => $team->name]), $kind];
        }

        $key = $kind === ComparisonKind::Pairs ? 'comparison_entry.flash.added_pair' : 'comparison_entry.flash.added_team';

        return [$this->translator->trans($key), $kind];
    }

    private function notAvailable(null|ReturnUrl $returnUrl): RedirectResponse
    {
        $this->addFlash('danger', $this->translator->trans('comparison_entry.flash.not_available'));

        return $this->back($returnUrl, null);
    }

    private function back(null|ReturnUrl $returnUrl, null|ComparisonKind $kind): RedirectResponse
    {
        if ($returnUrl !== null) {
            return $this->redirect($returnUrl->path);
        }

        return $this->redirectToRoute('comparison', $kind !== null ? ['kind' => $kind->value] : []);
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
