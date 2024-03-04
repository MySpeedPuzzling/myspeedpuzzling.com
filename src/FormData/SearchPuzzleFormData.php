<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\HttpFoundation\Request;

final class SearchPuzzleFormData
{
    public null|string $brand = null;

    public null|string $puzzle = null;

    public null|string $piecesCount = null;
    public null|string $tags = null;
    public bool $onlyWithResults = false;
    public bool $onlySolvedByMe = false;

    public static function fromRequest(Request $request): self
    {
        $self = new self();

        $brand = $request->query->get('brand');
        if (is_string($brand)) {
            $self->brand = $brand;
        }

        $puzzle = $request->query->get('puzzle');
        if (is_string($puzzle)) {
            $self->puzzle = $puzzle;
        }

        $piecesCount = $request->query->get('pieces_count');
        if (is_string($piecesCount)) {
            $self->piecesCount = $piecesCount;
        }

        $tags = $request->query->get('tags');
        if (is_string($tags)) {
            $self->tags = $tags;
        }

        $onlyWithResults = $request->query->get('only_with_results');
        if ($onlyWithResults !== null) {
            $self->onlyWithResults = (bool) $onlyWithResults;
        }

        $onlySolvedByMe = $request->query->get('only_solved_by_me');
        if ($onlySolvedByMe === null) {
            $self->onlySolvedByMe = (bool) $onlySolvedByMe;
        }

        return $self;
    }
}
