<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;

/**
 * Whose page a section is on: a competition (standalone event or edition) or a series - exactly one of them. Every write
 * is authorised against the owner of the section it touches, never against an id the request names separately.
 */
readonly final class PageSectionOwner
{
    private function __construct(
        public null|string $competitionId,
        public null|string $seriesId,
    ) {
    }

    public static function competition(string $competitionId): self
    {
        return new self(competitionId: self::uuid($competitionId), seriesId: null);
    }

    public static function series(string $seriesId): self
    {
        return new self(competitionId: null, seriesId: self::uuid($seriesId));
    }

    /**
     * @throws InvalidArgumentException when not exactly one of the two is given
     */
    public static function fromIds(null|string $competitionId, null|string $seriesId): self
    {
        $competitionId = $competitionId === '' ? null : $competitionId;
        $seriesId = $seriesId === '' ? null : $seriesId;

        if (($competitionId === null) === ($seriesId === null)) {
            throw new InvalidArgumentException('A page section belongs to exactly one of a competition or a series.');
        }

        return $competitionId !== null ? self::competition($competitionId) : self::series((string) $seriesId);
    }

    public static function of(CompetitionPageSection $section): self
    {
        if ($section->competition !== null) {
            return self::competition($section->competition->id->toString());
        }

        assert($section->series !== null);

        return self::series($section->series->id->toString());
    }

    public function id(): string
    {
        return $this->competitionId ?? (string) $this->seriesId;
    }

    public function isSeries(): bool
    {
        return $this->seriesId !== null;
    }

    public function owns(CompetitionPageSection $section): bool
    {
        if ($this->competitionId !== null) {
            return $section->competition?->id->toString() === $this->competitionId;
        }

        return $section->series?->id->toString() === $this->seriesId;
    }

    /**
     * The voter attribute that decides who may change this owner's page.
     */
    public function editAttribute(): string
    {
        return $this->isSeries()
            ? CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT
            : CompetitionEditVoter::COMPETITION_EDIT;
    }

    /**
     * One token per page: every form and request of the page editor carries it.
     */
    public function csrfTokenId(): string
    {
        return 'page_sections_' . $this->id();
    }

    /**
     * Uploaded section pictures live under the owner's own prefix - a section only ever points at its owner's files,
     * so removing it can never delete a picture of another page.
     */
    public function uploadDirectory(): string
    {
        return 'competition-pages/' . $this->id() . '/';
    }

    /**
     * @return array{string, array<string, string>} route name and parameters of the owner's page editor
     */
    public function editorRoute(): array
    {
        return $this->isSeries()
            ? ['manage_series_page', ['seriesId' => $this->id()]]
            : ['manage_competition_page', ['competitionId' => $this->id()]];
    }

    /**
     * @return array<string, string> the query parameter naming the owner in the add-section URL
     */
    public function queryParameter(): array
    {
        return $this->isSeries() ? ['series' => $this->id()] : ['competition' => $this->id()];
    }

    private static function uuid(string $id): string
    {
        if (Uuid::isValid($id) === false) {
            throw new InvalidArgumentException('Not a page owner id: ' . $id);
        }

        return strtolower($id);
    }
}
