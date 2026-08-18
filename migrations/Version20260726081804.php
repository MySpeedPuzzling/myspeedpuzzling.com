<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726081804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema-qualify immutable_unaccent so pg_restore (which forces search_path=\'\') can rebuild the puzzle search indexes';
    }

    public function up(Schema $schema): void
    {
        // The original body called unaccent() unqualified, which only resolves via the
        // session search_path. pg_dump-produced restores run with search_path='' (the
        // CVE-2018-1058 hardening), so rebuilding the custom_puzzle_*_unaccent_trgm
        // indexes failed there. Qualifying both the function and the dictionary makes
        // the definition search_path-independent; 'public.unaccent'::regdictionary
        // resolves to the same constant OID as before, so the inlined expression is
        // unchanged — existing indexes keep matching, nothing is rebuilt.
        $this->addSql("
            CREATE OR REPLACE FUNCTION public.immutable_unaccent(text)
            RETURNS text AS \$\$
                SELECT public.unaccent('public.unaccent'::regdictionary, \$1)
            \$\$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            CREATE OR REPLACE FUNCTION public.immutable_unaccent(text)
            RETURNS text AS \$\$
                SELECT unaccent('unaccent', \$1)
            \$\$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        ");
    }
}
