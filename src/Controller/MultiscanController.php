<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MultiscanAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Scan a pile of puzzles, apply one action - docs/features/multiscan/README.md.
 * Members only: everybody else gets the same page in teaser mode (the library
 * links here, so the link must explain the feature rather than bounce).
 */
final class MultiscanController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/multiscan',
            'en' => '/en/multiscan',
            'es' => '/es/multiscan',
            'ja' => '/ja/multiscan',
            'fr' => '/fr/multiscan',
            'de' => '/de/multiscan',
        ],
        name: 'multiscan',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        $presetAction = MultiscanAction::tryFrom($request->query->getString('action'))?->value;
        $collection = $request->query->getString('collection');
        $presetCollectionId = $collection === Collection::SYSTEM_ID || Uuid::isValid($collection) ? $collection : null;

        if ($presetAction !== MultiscanAction::AddToLibrary->value) {
            $presetCollectionId = null;
        }

        return $this->render('multiscan/index.html.twig', [
            'is_member' => $profile->activeMembership,
            'preset_action' => $presetAction,
            'preset_collection_id' => $presetCollectionId,
        ]);
    }
}
