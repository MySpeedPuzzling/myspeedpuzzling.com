<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Results\OrganizationDetail;
use SpeedPuzzling\Web\Security\OrganizationEditVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Who reaches the results import page (docs/features/event-results-import/results-import-page.md "Pages"): the
 * organization's team and admins (ORGANIZATION_EDIT - the organization legs of GetCompetitionPermissions). No switch:
 * an organization has the page while an admin registered a results source for it (no source = 404, no link). Every
 * route of the page asks here first.
 */
readonly final class ResultsImportPage
{
    public function __construct(
        private GetOrganization $getOrganization,
        private EventResultsSheetRepository $sourceRepository,
        private Security $security,
    ) {
    }

    /**
     * @throws NotFoundHttpException no such organization
     * @throws AccessDeniedException not on its team, not an admin
     */
    public function organization(string $slug): OrganizationDetail
    {
        $organization = $this->getOrganization->bySlug($slug);

        if ($this->security->isGranted(OrganizationEditVoter::ORGANIZATION_EDIT, $organization->id) === false) {
            throw new AccessDeniedException();
        }

        return $organization;
    }

    /**
     * @throws NotFoundHttpException no such source, or the source is another organization's
     */
    public function sourceOf(OrganizationDetail $organization, string $sourceId): EventResultsSheet
    {
        $source = $this->sourceRepository->get($sourceId);

        if ($source->organization->id->toString() !== strtolower($organization->id)) {
            throw new NotFoundHttpException();
        }

        return $source;
    }
}
