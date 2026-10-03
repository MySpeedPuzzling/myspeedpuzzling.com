<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetManufacturerSlugRedirect
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The current slug of the brand a merged-away slug now belongs to.
     */
    public function targetSlug(string $slug): null|string
    {
        $query = <<<SQL
SELECT manufacturer.slug
FROM manufacturer_slug_redirect
INNER JOIN manufacturer ON manufacturer.id = manufacturer_slug_redirect.manufacturer_id
WHERE manufacturer_slug_redirect.slug = :slug
SQL;

        $targetSlug = $this->database->executeQuery($query, ['slug' => $slug])->fetchOne();

        return is_string($targetSlug) && $targetSlug !== $slug ? $targetSlug : null;
    }
}
