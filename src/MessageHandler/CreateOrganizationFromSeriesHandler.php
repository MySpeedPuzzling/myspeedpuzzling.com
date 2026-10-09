<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\FollowedCompetition;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\Exceptions\SeriesAlreadyInOrganization;
use SpeedPuzzling\Web\Message\CreateOrganizationFromSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\FollowedCompetitionRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use SpeedPuzzling\Web\Services\Restructuring\EventUrlRedirects;
use SpeedPuzzling\Web\Value\SocialLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * docs/features/organizations/README.md "Restructuring tools": the organization is made from the series - name and the
 * rest from the message (country and region as given - the callers prefill them from the series), logo (the same
 * stored file), about = the series' description, website = its link, team = its maintainers, creator = its creator (else
 * the actor). Approved at once when asked (internal API, an admin), else waiting for approval with the admin e-mail.
 *
 * Followers: when the organization is publicly visible at the end (approved, not a draft), the series' followers
 * follow the organization from now on (their rows move - one row per player). While it waits for approval nobody could
 * follow it on its page, so the series follows stay and every follower gets an organization follow too - "Your events"
 * reads them once the organization is public.
 *
 * The series is attached to it, so a pending series under an approved organization made by its team or an admin is
 * approved at once (OrganizationApprovalPolicy, D2); an approved series stays approved. With a new slug, the series' old
 * address leads to the organization and its editions' old addresses to where they are now.
 */
#[AsMessageHandler]
readonly final class CreateOrganizationFromSeriesHandler
{
    public function __construct(
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private OrganizationRepository $organizationRepository,
        private FollowedCompetitionRepository $followedCompetitionRepository,
        private PlayerRepository $playerRepository,
        private CompetitionSlugGenerator $slugGenerator,
        private OrganizationApprovalPolicy $organizationApprovalPolicy,
        private CompetitionSubmittedMailer $competitionSubmittedMailer,
        private EventUrlRedirects $eventUrlRedirects,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SeriesAlreadyInOrganization
     * @throws InvalidCompetitionSlug
     * @throws OrganizationSlugTaken
     * @throws CompetitionSlugTaken
     */
    public function __invoke(CreateOrganizationFromSeries $message): void
    {
        $series = $this->competitionSeriesRepository->get($message->seriesId);
        $actor = $this->playerRepository->get($message->actingPlayerId);
        $now = $this->clock->now();

        if ($series->organization !== null) {
            throw new SeriesAlreadyInOrganization();
        }

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isOrganizationSlugTaken($message->slug)) {
                throw new OrganizationSlugTaken($message->slug);
            }
        }

        $oldSeriesSlug = $series->slug;
        $newSeriesSlug = $message->newSeriesSlug !== null && $message->newSeriesSlug !== $oldSeriesSlug ? $message->newSeriesSlug : null;

        if ($newSeriesSlug !== null) {
            if (CompetitionSlugGenerator::isValid($newSeriesSlug) === false) {
                throw new InvalidCompetitionSlug($newSeriesSlug);
            }

            if ($this->slugGenerator->isSeriesSlugTaken($newSeriesSlug, $series->id->toString())) {
                throw new CompetitionSlugTaken($newSeriesSlug);
            }
        }

        $creator = $series->addedByPlayer ?? $actor;

        $organization = new Organization(
            id: $message->organizationId,
            name: $message->name,
            slug: $message->slug ?? $this->slugGenerator->generateOrganizationSlug($message->name),
            createdAt: $now,
            shortName: $message->shortName,
            logo: $series->logo,
            // Within the organization's limits, so a later edit never fails on what was copied
            about: Organization::fittedAbout($series->description),
            website: Organization::fittedWebsite($series->link),
            links: new SocialLinks([]),
            countryCode: $message->countryCode,
            region: $message->region,
            kind: $message->kind,
            addedByPlayer: $creator,
        );

        foreach ($series->maintainers as $maintainer) {
            // The creator is on the team as its creator - never a maintainer row too
            if ($maintainer->id->equals($creator->id) === false) {
                $organization->maintainers->add($maintainer);
            }
        }

        if ($message->approve) {
            $organization->approve($actor, $now);
        }

        // Created published: in the queue now (or past it)
        $organization->markSubmitted($now);

        $this->organizationRepository->save($organization);

        $this->carryFollowsOver($series, $organization, $now);

        if ($message->newSeriesName !== null || $newSeriesSlug !== null) {
            $series->edit(
                name: $message->newSeriesName ?? $series->name,
                slug: $newSeriesSlug ?? $oldSeriesSlug,
                logo: $series->logo,
                description: $series->description,
                link: $series->link,
                isOnline: $series->isOnline,
                location: $series->location,
                locationCountryCode: $series->locationCountryCode,
                shortcut: $series->shortcut,
            );
        }

        $series->assignOrganization($organization);
        $this->organizationApprovalPolicy->approveIfUnderTrustedOrganization($series, $actor, $now);

        if ($newSeriesSlug !== null && $oldSeriesSlug !== null) {
            $this->eventUrlRedirects->rememberSeriesOfOrganization($series, $oldSeriesSlug, $organization);
        }

        if ($message->approve === false) {
            $this->competitionSubmittedMailer->notifyAdminOfOrganization($organization->name, $actor->name ?? 'Unknown', $organization->region);
        }
    }

    private function carryFollowsOver(CompetitionSeries $series, Organization $organization, DateTimeImmutable $now): void
    {
        $follows = $this->followedCompetitionRepository->listForSeries($series);

        if ($organization->isPubliclyVisible()) {
            foreach ($follows as $follow) {
                $follow->moveToOrganization($organization);
            }

            return;
        }

        // The organization was made a moment ago - nobody follows it yet, so one new row per follower (a player follows
        // a series once)
        foreach ($follows as $follow) {
            $this->followedCompetitionRepository->save(FollowedCompetition::ofOrganization(Uuid::uuid7(), $follow->player, $organization, $now));
        }
    }
}
