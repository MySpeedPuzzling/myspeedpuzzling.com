<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Restructure;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\Exceptions\SeriesAlreadyInOrganization;
use SpeedPuzzling\Web\FormData\CreateOrganizationFromSeriesFormData;
use SpeedPuzzling\Web\FormType\CreateOrganizationFromSeriesFormType;
use SpeedPuzzling\Web\Message\CreateOrganizationFromSeries;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Turn into an organization" (docs/features/organizations/README.md "Restructuring tools") →
 * CreateOrganizationFromSeries. Prefilled from the series; the organization waits for an admin's approval unless an
 * admin does it. A new series address keeps the old one working (301 to the organization). Every refusal is an error
 * on the form (422), success goes to the organization's page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CreateOrganizationFromSeriesController extends AbstractController
{
    public function __construct(
        readonly private CompetitionSeriesRepository $competitionSeriesRepository,
        readonly private GetOrganization $getOrganization,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CompetitionSlugGenerator $slugGenerator,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/series-to-organization/{seriesId}',
        name: 'create_organization_from_series',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        $series = $this->competitionSeriesRepository->get($seriesId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        $form = $this->createForm(CreateOrganizationFromSeriesFormType::class, CreateOrganizationFromSeriesFormData::fromSeries($series), [
            'action' => $this->generateUrl('create_organization_from_series', ['seriesId' => $seriesId]),
        ]);
        $form->handleRequest($request);

        // A series under an organization already: the page shows where, the form refuses (422, never a 200 to a POST)
        if ($series->organization !== null && $form->isSubmitted()) {
            $form->addError(new FormError($this->translator->trans('restructure.to_organization.already_in_organization')));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $slug = $this->slugOf($form->get('slug'), $data->slug);
            $newSeriesSlug = $this->slugOf($form->get('newSeriesSlug'), $data->newSeriesSlug);
            $newSeriesName = $data->newSeriesName !== null && trim($data->newSeriesName) !== '' && trim($data->newSeriesName) !== $series->name
                ? trim($data->newSeriesName)
                : null;

            // An address typed that cannot be one is an error on its field - nothing is sent then
            if ($form->get('slug')->getErrors()->count() === 0 && $form->get('newSeriesSlug')->getErrors()->count() === 0) {
                $organizationId = Uuid::uuid7();

                try {
                    $this->messageBus->dispatch(new CreateOrganizationFromSeries(
                        seriesId: $series->id->toString(),
                        organizationId: $organizationId,
                        actingPlayerId: $profile->playerId,
                        name: trim((string) $data->name),
                        shortName: $data->shortName !== null && trim($data->shortName) !== '' ? trim($data->shortName) : null,
                        slug: $slug,
                        kind: $data->kind,
                        countryCode: $data->countryCode,
                        region: $data->region !== null && trim($data->region) !== '' ? trim($data->region) : null,
                        // An admin's organization needs nobody else's approval
                        approve: $profile->isAdmin,
                        newSeriesName: $newSeriesName,
                        newSeriesSlug: $newSeriesSlug !== $series->slug ? $newSeriesSlug : null,
                    ));

                    $this->addFlash('success', $this->translator->trans($profile->isAdmin
                        ? 'restructure.to_organization.flash.created'
                        : 'restructure.to_organization.flash.created_waiting'));

                    return $this->redirectToRoute('organization_detail', [
                        'slug' => $this->getOrganization->byId($organizationId->toString())->slug,
                    ]);
                } catch (OrganizationSlugTaken) {
                    $form->get('slug')->addError(new FormError($this->translator->trans('restructure.to_organization.slug_taken')));
                } catch (CompetitionSlugTaken) {
                    $form->get('newSeriesSlug')->addError(new FormError($this->translator->trans('restructure.to_organization.series_slug_taken')));
                } catch (SeriesAlreadyInOrganization) {
                    $form->addError(new FormError($this->translator->trans('restructure.to_organization.already_in_organization')));
                }
            }
        }

        return $this->render('restructure/series_to_organization.html.twig', [
            'form' => $form,
            'series' => $series,
            'organization' => $series->organization,
            'approved_at_once' => $profile->isAdmin,
        ]);
    }

    /**
     * What was typed as an address, made into a slug - null when nothing was typed; an error on the field when it
     * cannot be one.
     *
     * @param FormInterface<mixed> $field
     */
    private function slugOf(FormInterface $field, null|string $typed): null|string
    {
        if ($typed === null || trim($typed) === '') {
            return null;
        }

        $slug = $this->slugGenerator->normalize($typed);

        if (CompetitionSlugGenerator::isValid($slug) === false) {
            $field->addError(new FormError($this->translator->trans('restructure.slug_invalid')));

            return null;
        }

        return $slug;
    }
}
