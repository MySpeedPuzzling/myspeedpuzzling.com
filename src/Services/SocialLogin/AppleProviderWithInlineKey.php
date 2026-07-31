<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use League\OAuth2\Client\Provider\Apple;

/**
 * The upstream Apple provider insists on reading the ES256 signing key from a
 * file (`keyFilePath`), but our secrets pipeline (Infisical -> env) delivers
 * the .p8 CONTENT in APPLE_PRIVATE_KEY. This subclass feeds the key from
 * memory; the `keyFilePath` option is set to a dummy value only to satisfy the
 * parent constructor's required-option check and is never read.
 */
final class AppleProviderWithInlineKey extends Apple
{
    private string $keyContents;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $collaborators
     */
    public function __construct(array $options, array $collaborators = [])
    {
        $keyContents = $options['keyContents'] ?? null;
        assert(is_string($keyContents));

        $this->keyContents = $keyContents;
        unset($options['keyContents']);
        $options['keyFilePath'] = '/dev/null';

        parent::__construct($options, $collaborators);
    }

    public function getLocalKey(): Key
    {
        // Infisical/env transport turns real newlines into literal \n sequences
        $keyContents = str_replace('\\n', "\n", $this->keyContents);
        assert($keyContents !== '');

        return InMemory::plainText($keyContents);
    }
}
