<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\ChangeDefaultCollection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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
 * "Make default" on the collections overview and the collection pages (docs/features/collections/default-collection.md):
 * the collection every collection picker preselects. The system collection's marker = back to "My puzzles".
 * Members only - custom collections are.
 */
final class MakeDefaultCollectionController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'default_collection';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vychozi-kolekce/{collectionId}',
            'en' => '/en/default-collection/{collectionId}',
            'es' => '/es/coleccion-predeterminada/{collectionId}',
            'ja' => '/ja/default-collection/{collectionId}',
            'fr' => '/fr/collection-par-defaut/{collectionId}',
            'de' => '/de/standard-sammlung/{collectionId}',
        ],
        name: 'make_default_collection',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $collectionId): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        $returnUrl = ReturnUrl::tryFrom($request->query->get('return'));
        $redirect = $returnUrl !== null
            ? $this->redirect($returnUrl->path)
            : $this->redirectToRoute('puzzle_library', ['playerId' => $profile->playerId]);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            return $redirect;
        }

        if ($profile->activeMembership === false) {
            $this->addFlash('info', $this->translator->trans('collections.flash.membership_required'));

            return $redirect;
        }

        try {
            $this->messageBus->dispatch(new ChangeDefaultCollection(
                playerId: $profile->playerId,
                collectionId: $collectionId === Collection::SYSTEM_ID ? null : $collectionId,
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof CollectionNotFound) {
                throw $exception->getPrevious();
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans('collections.flash.default_changed'));

        return $redirect;
    }
}
