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

    public string $sortBy = 'most-solved';

    public null|string $tag = null;

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

        $sortBy = $request->query->get('sortBy');
        if (is_string($sortBy)) {
            $self->sortBy = $sortBy;
        }

        return $self;
    }
}
