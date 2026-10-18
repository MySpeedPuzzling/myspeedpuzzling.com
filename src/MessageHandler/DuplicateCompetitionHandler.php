<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEditable;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use SpeedPuzzling\Web\Value\DuplicateDateShift;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * docs/features/competitions-management/README.md "Duplicating an event". The copy is always a draft with a fresh slug
 * and the same name; nothing is public until the organiser publishes it, so nobody is e-mailed now (the admins' e-mail
 * goes out on its first publish, PublishCompetitionHandler - like a new event saved as a draft). A one-time event's copy
 * waits for approval unless OrganizationApprovalPolicy approves it at once; an edition's approval is its series'.
 *
 * The logo path is shared with the source: no code deletes a competition logo, and every file deletion asks
 * GetStoredFileReferences, which sees both rows. Page section pictures are copied to the copy's own prefix - a section
 * only ever points at its own page's files (PageSectionContentSanitizer::uploadPath()).
 */
#[AsMessageHandler]
readonly final class DuplicateCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $roundRepository,
        private CompetitionPageSectionRepository $sectionRepository,
        private PlayerRepository $playerRepository,
        private GetCompetitionPermissions $getCompetitionPermissions,
        private CompetitionSlugGenerator $slugGenerator,
        private OrganizationApprovalPolicy $organizationApprovalPolicy,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws CompetitionNotEditable
     */
    public function __invoke(DuplicateCompetition $message): void
    {
        $source = $this->competitionRepository->get($message->sourceCompetitionId);
        $actor = $this->playerRepository->get($message->actingPlayerId);

        if (
            $actor->isAdmin === false
            && $this->getCompetitionPermissions->forPlayer($actor->id->toString())->canEditCompetition($source->id->toString()) === false
        ) {
            throw new CompetitionNotEditable();
        }

        $now = $this->clock->now();
        $name = $message->name ?? $source->name;
        $shift = DuplicateDateShift::between($source->dateFrom, $message->dateFrom);

        $copy = new Competition(
            id: $message->newCompetitionId,
            name: $name,
            slug: $this->slugGenerator->generateFree($name, $source->series?->id->toString()),
            shortcut: $source->shortcut,
            logo: $source->logo,
            description: $source->description,
            link: $source->link,
            registrationLink: $source->registrationLink,
            // The organiser's results of that event - never the copy's
            resultsLink: null,
            location: $message->location ?? $source->location,
            locationCountryCode: $source->locationCountryCode,
            dateFrom: $message->dateFrom ?? $source->dateFrom,
            dateTo: $message->dateTo ?? $shift->apply($source->dateTo),
            // A tag carries the event's own puzzles - puzzles are never copied
            tag: null,
            isOnline: $source->isOnline,
            series: $source->series,
            addedByPlayer: $actor,
            createdAt: $now,
            registrationManaged: $source->registrationManaged,
            capacity: $source->capacity,
            registrationOpensAt: $shift->apply($source->registrationOpensAt, $source->registrationZone()),
            registrationClosesAt: $shift->apply($source->registrationClosesAt, $source->registrationZone()),
            entryFeeText: $source->entryFeeText,
            paymentInstructions: $source->paymentInstructions,
            organization: $source->organization,
            isDraft: true,
            eligibility: $source->eligibility,
        );

        if ($source->registrationTimezone !== null) {
            $copy->changeRegistrationSettings(
                registrationManaged: $copy->registrationManaged,
                capacity: $copy->capacity,
                registrationOpensAt: $copy->registrationOpensAt,
                registrationClosesAt: $copy->registrationClosesAt,
                timezone: $source->registrationTimezone,
                entryFeeText: $copy->entryFeeText,
                paymentInstructions: $copy->paymentInstructions,
            );
        }

        $this->copyTeam($source, $copy, $actor);

        $this->competitionRepository->save($copy);

        foreach ($this->roundRepository->ofCompetition($source) as $round) {
            $this->roundRepository->save($this->copyRound($round, $copy, $shift));
        }

        $this->copyPageSections($source, $copy, $now);

        $this->organizationApprovalPolicy->approveIfUnderTrustedOrganization($copy, $actor, $now);
    }

    /**
     * The source's maintainers - and its creator, when somebody else makes the copy: the people who ran it keep running
     * the copy
     */
    private function copyTeam(Competition $source, Competition $copy, Player $actor): void
    {
        $team = $source->maintainers->toArray();

        if ($source->addedByPlayer !== null) {
            $team[] = $source->addedByPlayer;
        }

        foreach ($team as $member) {
            if ($member->id->equals($actor->id) || $copy->maintainers->contains($member)) {
                continue;
            }

            $copy->maintainers->add($member);
        }
    }

    /**
     * The round's own settings - never its puzzles, tables, results, results link, stopwatch or online timing
     */
    private function copyRound(CompetitionRound $round, Competition $copy, DuplicateDateShift $shift): CompetitionRound
    {
        return new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $copy,
            name: $round->name,
            minutesLimit: $round->minutesLimit,
            startsAt: $shift->apply($round->startsAt, $round->displayTimezone()) ?? $round->startsAt,
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
            // Unique per competition - the copy has no other round yet
            slug: $round->slug,
            timezone: $round->timezone,
            tableNumbersOff: $round->tableNumbersOff,
            revealDelayMinutes: $round->revealDelayMinutes,
            teamSize: $round->teamSize,
        );
    }

    /**
     * The event's own sections (an edition's inherited series sections stay on the series), hidden ones hidden. Every
     * section type is organiser text; its pictures are copied under the copy's prefix - one that cannot be copied is
     * left out of the copy.
     */
    private function copyPageSections(Competition $source, Competition $copy, DateTimeImmutable $now): void
    {
        $sourceOwner = PageSectionOwner::competition($source->id->toString());
        $copyOwner = PageSectionOwner::competition($copy->id->toString());

        foreach ($this->sectionRepository->allOf($sourceOwner) as $section) {
            $content = $section->content;

            if ($section->type === PageSectionType::Gallery && is_array($content['images'] ?? null)) {
                $images = [];

                foreach ($content['images'] as $image) {
                    if (is_array($image) === false) {
                        continue;
                    }

                    $path = $this->copyPicture($image['path'] ?? null, $sourceOwner, $copyOwner);

                    if ($path !== null) {
                        $image['path'] = $path;
                        $images[] = $image;
                    }
                }

                $content['images'] = $images;
            }

            if ($section->type === PageSectionType::Sponsors && is_array($content['sponsors'] ?? null)) {
                $sponsors = [];

                foreach ($content['sponsors'] as $sponsor) {
                    if (is_array($sponsor)) {
                        $sponsor['logoPath'] = $this->copyPicture($sponsor['logoPath'] ?? null, $sourceOwner, $copyOwner);
                        $sponsors[] = $sponsor;
                    }
                }

                $content['sponsors'] = $sponsors;
            }

            $this->sectionRepository->save(new CompetitionPageSection(
                id: Uuid::uuid7(),
                competition: $copy,
                series: null,
                type: $section->type,
                position: $section->position,
                title: $section->title,
                content: $content,
                createdAt: $now,
                visible: $section->visible,
            ));
        }
    }

    /**
     * @return null|string the picture's path under the copy's prefix, null when there is none or it could not be copied
     */
    private function copyPicture(mixed $path, PageSectionOwner $sourceOwner, PageSectionOwner $copyOwner): null|string
    {
        if (is_string($path) === false || str_starts_with($path, $sourceOwner->uploadDirectory()) === false) {
            return null;
        }

        $copyPath = $copyOwner->uploadDirectory() . substr($path, strlen($sourceOwner->uploadDirectory()));

        try {
            $this->filesystem->copy($path, $copyPath);
        } catch (FilesystemException $exception) {
            // A picture the section points at is gone from the storage - the copy goes without it
            $this->logger->warning('A page section picture could not be copied for a duplicated event.', [
                'exception' => $exception,
                'path' => $path,
            ]);

            return null;
        }

        return $copyPath;
    }
}
