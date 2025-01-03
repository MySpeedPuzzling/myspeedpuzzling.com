<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

final class SearchPuzzleFormData
{
    public null|string $brand = null;

    public null|string $search = null;

    public null|string $pieces = null;

    public null|string $tag = null;

    public bool $onlyWithResults = false;

    public bool $onlySolvedByMe = false;

    public bool $onlyAvailable = false;

    public static function fromRequest(Request $request): self
    {
        $self = new self();

        $brand = $request->query->get('brand');
        if (is_string($brand) && $brand !== '') {
            $self->brand = $brand;
        }

        $search = $request->query->get('search');
        if (is_string($search)) {
            $self->search = $search;
        }

        $tag = $request->query->get('tag');
        if (is_string($tag) && Uuid::isValid($tag)) {
            $self->tag = $tag;
        }

        $pieces = $request->query->get('pieces');
        if (is_string($pieces)) {
            $self->pieces = $pieces;
        }

        $onlyWithResults = $request->query->get('only_with_results');
        if ($onlyWithResults !== null) {
            $self->onlyWithResults = (bool) $onlyWithResults;
        }

        $onlySolvedByMe = $request->query->get('only_solved_by_me');
        if ($onlySolvedByMe === null) {
            $self->onlySolvedByMe = (bool) $onlySolvedByMe;
        }

        $onlyAvailable = $request->query->get('only_available');
        if ($onlyAvailable === null) {
            $self->onlyAvailable = (bool) $onlyAvailable;
        }

        return $self;
    }
}
