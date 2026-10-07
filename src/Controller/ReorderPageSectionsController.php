<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use InvalidArgumentException;
use SpeedPuzzling\Web\Message\ReorderPageSections;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page editor's drag and "Move up / Move down" (page_sections_list_controller.js): a form-encoded POST of the
 * page's section ids in their new order (`sections[]`), the page (`competitionId` or `seriesId`) and the page's token.
 * A section of any other page refuses the whole request (ReorderPageSectionsHandler, 404).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ReorderPageSectionsController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/seradit-sekce-stranky',
            'en' => '/en/reorder-page-sections',
            'es' => '/es/reorder-page-sections',
            'ja' => '/ja/reorder-page-sections',
            'fr' => '/fr/reorder-page-sections',
            'de' => '/de/reorder-page-sections',
        ],
        name: 'reorder_page_sections',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $owner = PageSectionOwner::fromIds($request->request->getString('competitionId'), $request->request->getString('seriesId'));
        } catch (InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        if ($this->isCsrfTokenValid($owner->csrfTokenId(), $request->request->getString('_token')) === false) {
            return new JsonResponse(['error' => $this->translator->trans('page_sections.error.expired')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sectionIds = $request->request->all()['sections'] ?? [];

        if (!is_array($sectionIds)) {
            throw $this->createNotFoundException();
        }

        $this->messageBus->dispatch(new ReorderPageSections(
            competitionId: $owner->competitionId,
            seriesId: $owner->seriesId,
            sectionIds: array_values(array_filter($sectionIds, is_string(...))),
        ));

        return new JsonResponse(['status' => 'ok']);
    }
}
