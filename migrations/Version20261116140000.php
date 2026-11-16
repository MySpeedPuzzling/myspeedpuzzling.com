<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * Hand-written data migration (no schema change): hashes every OAuth2 client secret still stored in plaintext.
 *
 * league/oauth2-server-bundle 2.0 verifies client secrets only as hashes, so a plaintext secret would refuse its
 * client. Each client keeps its plain secret - only the stored form changes. The same logic as the bundle's
 * `league:oauth2-server:rehash-client-secrets`: public clients (no secret) are skipped, and so are secrets
 * the bundle 1.2 already re-hashed on a client's first token request since. `new NativePasswordHasher()` is
 * exactly the bundle's `league.oauth2_server.password_hasher` service (bcrypt, cost 13), so the bundle never
 * sees these hashes as needing a rehash.
 *
 * Safe across the blue-green deploy: the new container runs this before it serves, and the old container
 * (bundle 1.2 with its plaintext-migrating hasher) verifies hashed secrets as well. The plaintext never goes
 * into a query parameter (logged at -vvv) - only its md5, which guards against overwriting a secret that
 * changed between this read and the update.
 */
final class Version20261116140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hash plaintext OAuth2 client secrets (oauth2-server-bundle 2.0 accepts only hashed secrets)';
    }

    public function up(Schema $schema): void
    {
        $hasher = new NativePasswordHasher();

        /** @var list<array{identifier: string, secret: string}> $clients */
        $clients = $this->connection->fetchAllAssociative(
            "SELECT identifier, secret FROM oauth2_client WHERE secret IS NOT NULL AND secret <> ''",
        );

        $hashed = 0;

        foreach ($clients as $client) {
            if ($hasher->needsRehash($client['secret']) === false) {
                continue;
            }

            $this->addSql(
                'UPDATE oauth2_client SET secret = :hash WHERE identifier = :identifier AND md5(secret) = :fingerprint',
                [
                    'hash' => $hasher->hash($client['secret']),
                    'identifier' => $client['identifier'],
                    'fingerprint' => md5($client['secret']),
                ],
            );
            $hashed++;
        }

        $this->write(sprintf('OAuth2 client secrets: %d to hash, %d already hashed.', $hashed, count($clients) - $hashed));

        // A migration without SQL is reported as a warning - fine when every secret is hashed already
        if ($hashed === 0) {
            $this->addSql('SELECT 1');
        }
    }

    public function down(Schema $schema): void
    {
        // A hash cannot be turned back into the secret - and bundle 1.2 verifies hashed secrets too
    }
}
