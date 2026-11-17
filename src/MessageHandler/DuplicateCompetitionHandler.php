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
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEditable;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Repository\CompetitionAdvancementStepRepository;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayImages;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use SpeedPuzzling\Web\Services\EventDateShift;
use SpeedPuzzling\Web\Value\DuplicateDateShift;
use SpeedPuzzling\Web\Value\OnlineToken;
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
 * only ever points at its own page's files (PageSectionContentSanitizer::uploadPath()). Standings are copied unpublished
 * with their rounds remapped (copyStandings()), advancement steps too (copyAdvancementSteps()). Displays get new
 * links, their rounds remapped and their pictures copied to the copy's own prefix (copyDisplays()); the competitor-page
 * settings are event settings and copied as they are.
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
        private CompetitionStandingRepository $standingRepository,
        private CompetitionDisplayRepository $displayRepository,
        private CompetitionAdvancementStepRepository $advancementStepRepository,
        private EventDateShift $eventDateShift,
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
        // From the source's first day - its date_from, else its first round's day (EventDateShift)
        $shift = $this->eventDateShift->shiftTo($source, $message->dateFrom);

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
        $copy->changeEventTypes($source->eventTypes());
        // Competitor pages & QR (competition toolkit B1) - event settings like the registration ones
        $copy->changeCompetitorPageSettings($source->competitorPageSettings());

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

        $roundIdMap = [];
        foreach ($this->roundRepository->ofCompetition($source) as $round) {
            $roundCopy = $this->copyRound($round, $copy, $shift);
            $roundIdMap[$round->id->toString()] = $roundCopy->id->toString();
            $this->roundRepository->save($roundCopy);
        }

        $this->copyPageSections($source, $copy, $now);
        $this->copyStandings($source, $copy, $roundIdMap, $now);
        $this->copyDisplays($source, $copy, $roundIdMap, $actor, $now);
        $this->copyAdvancementSteps($source, $copy, $roundIdMap, $now);

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
        $roundCopy = new CompetitionRound(
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
            startTimeKnown: $round->startTimeKnown,
        );

        // "Unboxed" (world-series-rules.md §7) - the copy has no puzzles and no results yet, so nothing to re-sync
        if ($round->unboxed) {
            $roundCopy->changeUnboxed(true);
        }

        return $roundCopy;
    }

    /**
     * The event's standings (competition toolkit A6, A-round-formats.md §3.6) - unpublished, the same slug (the copy has
     * none yet), their rounds remapped to the copies; an id that is not a round of the source any more is left out.
     *
     * @param array<string, string> $roundIdMap source round id => its copy's id
     */
    private function copyStandings(Competition $source, Competition $copy, array $roundIdMap, DateTimeImmutable $now): void
    {
        foreach ($this->standingRepository->ofCompetition($source) as $standing) {
            $this->standingRepository->save(new CompetitionStanding(
                id: Uuid::uuid7(),
                competition: $copy,
                category: $standing->category,
                name: $standing->name,
                slug: $standing->slug,
                roundIds: array_values(array_filter(array_map(
                    static fn (string $roundId): null|string => $roundIdMap[$roundId] ?? null,
                    $standing->roundIds(),
                ))),
                createdAt: $now,
                position: $standing->position,
                mode: $standing->mode,
                unfinishedPenaltySecondsPerPiece: $standing->unfinishedPenaltySecondsPerPiece,
                orderRule: $standing->orderRule,
                missingRule: $standing->missingRule,
                penaliseFinishedMissing: $standing->penaliseFinishedMissing,
            ));
        }
    }

    /**
     * The event's displays (competition toolkit D8, D-displays-overlays.md §3.3): each with a new link (token), its
     * design, language and round to follow - a pinned round and the followed rounds remapped onto the copied rounds (an id
     * that is not a round of the source any more is left out; no followed round left = every round, as the display
     * treats it). Pictures are copied to the copy's own prefix - the sanitizer accepts only the event's own uploads -
     * and a picture missing from the storage is left out of the copy.
     *
     * @param array<string, string> $roundIdMap source round id => its copy's id
     */
    private function copyDisplays(Competition $source, Competition $copy, array $roundIdMap, Player $actor, DateTimeImmutable $now): void
    {
        $displays = $this->displayRepository->ofCompetition($source);

        if ($displays === []) {
            return;
        }

        $sourcePrefix = DisplayImages::PREFIX . strtolower($source->id->toString()) . '/';
        $copyPrefix = DisplayImages::PREFIX . strtolower($copy->id->toString()) . '/';
        /** @var array<string, null|string> $copiedPictures source path => the copy's path (null = could not be copied) */
        $copiedPictures = [];

        $copyPicture = function (mixed $path) use ($sourcePrefix, $copyPrefix, &$copiedPictures): null|string {
            if (!is_string($path) || !str_starts_with($path, $sourcePrefix)) {
                return null;
            }

            if (!array_key_exists($path, $copiedPictures)) {
                $copyPath = $copyPrefix . substr($path, strlen($sourcePrefix));

                try {
                    $this->filesystem->copy($path, $copyPath);
                    $copiedPictures[$path] = $copyPath;
                } catch (FilesystemException $exception) {
                    $this->logger->warning('A display picture could not be copied for a duplicated event.', [
                        'exception' => $exception,
                        'path' => $path,
                    ]);
                    $copiedPictures[$path] = null;
                }
            }

            return $copiedPictures[$path];
        };

        foreach ($displays as $display) {
            $pinnedRoundId = $display->pinnedRound !== null ? ($roundIdMap[$display->pinnedRound->id->toString()] ?? null) : null;

            $followRoundIds = null;
            if ($display->followRoundIds !== null) {
                $followRoundIds = array_values(array_filter(array_map(
                    static fn (string $roundId): null|string => $roundIdMap[strtolower($roundId)] ?? null,
                    $display->followRoundIds,
                )));
                $followRoundIds = $followRoundIds !== [] ? $followRoundIds : null;
            }

            $this->displayRepository->save(new CompetitionDisplay(
                id: Uuid::uuid7(),
                competition: $copy,
                kind: $display->kind,
                name: $display->name,
                token: OnlineToken::generate(),
                locale: $display->locale,
                design: self::designWithPictures($display->design, $copyPicture),
                createdAt: $now,
                createdBy: $actor,
                updatedAt: $now,
                followMode: $display->followMode,
                // The copied round is persisted, not flushed yet - get() finds it in the unit of work
                pinnedRound: $pinnedRoundId !== null ? $this->roundRepository->get($pinnedRoundId) : null,
                followRoundIds: $followRoundIds,
                usesLiveResults: $display->usesLiveResults,
                usesResults: $display->usesResults,
            ));
        }
    }

    /**
     * The design with every picture (background, image elements, rotator images - DisplayDesignSanitizer::imagePaths())
     * pointing at its copy; a picture without a copy is dropped the way the sanitizer drops a missing one.
     *
     * @param array<string, mixed> $design a normalised design
     * @param callable(mixed): (null|string) $copyPicture
     * @return array<string, mixed>
     */
    private static function designWithPictures(array $design, callable $copyPicture): array
    {
        if (is_array($design['canvas'] ?? null) && is_array($design['canvas']['background'] ?? null) && is_array($design['canvas']['background']['image'] ?? null)) {
            $path = $copyPicture($design['canvas']['background']['image']['path'] ?? null);
            $image = $design['canvas']['background']['image'];
            $image['path'] = $path;
            $design['canvas']['background']['image'] = $path !== null ? $image : null;
        }

        if (is_array($design['elements'] ?? null)) {
            foreach ($design['elements'] as $index => $element) {
                if (!is_array($element) || !is_array($element['props'] ?? null)) {
                    continue;
                }

                $props = $element['props'];

                if (($element['type'] ?? null) === 'image' && ($props['path'] ?? null) !== null) {
                    $props['path'] = $copyPicture($props['path']);
                }

                if (($element['type'] ?? null) === 'rotator' && is_array($props['images'] ?? null)) {
                    $images = [];

                    foreach ($props['images'] as $image) {
                        $path = is_array($image) ? $copyPicture($image['path'] ?? null) : null;

                        if ($path !== null) {
                            $images[] = ['path' => $path];
                        }
                    }

                    $props['images'] = $images;
                }

                $element['props'] = $props;
                $design['elements'][$index] = $element;
            }
        }

        return $design;
    }

    /**
     * The event's advancement steps (competition toolkit A8, A-round-formats.md §3.6) with their rounds - and the
     * by-source map - remapped to the copies. An id that is not a round of the source any more is left out (pruned like
     * a deleted round); a step left without sources or targets is not copied.
     *
     * @param array<string, string> $roundIdMap source round id => its copy's id
     */
    private function copyAdvancementSteps(Competition $source, Competition $copy, array $roundIdMap, DateTimeImmutable $now): void
    {
        $remap = static fn (array $roundIds): array => array_values(array_filter(array_map(
            static fn (string $roundId): null|string => $roundIdMap[$roundId] ?? null,
            $roundIds,
        )));

        foreach ($this->advancementStepRepository->ofCompetition($source) as $step) {
            $settings = $step->settings();
            $map = [];

            foreach ($settings->targetBySource as $sourceRoundId => $targetRoundId) {
                $from = $roundIdMap[strtolower((string) $sourceRoundId)] ?? null;
                $to = $roundIdMap[strtolower($targetRoundId)] ?? null;

                if ($from !== null && $to !== null) {
                    $map[$from] = $to;
                }
            }

            $stepCopy = new CompetitionAdvancementStep(
                id: Uuid::uuid7(),
                competition: $copy,
                name: $step->name,
                sourceRoundIds: $remap($settings->sourceRoundIds),
                targetRoundIds: $remap($settings->targetRoundIds),
                distribution: $step->distribution,
                createdAt: $now,
                targetBySource: $map,
                qualifyCount: $step->qualifyCount,
                qualifyScope: $step->qualifyScope,
                bestOfEachCountry: $step->bestOfEachCountry,
                position: $step->position,
            );

            if (!$stepCopy->isEmpty()) {
                $this->advancementStepRepository->save($stepCopy);
            }
        }
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
