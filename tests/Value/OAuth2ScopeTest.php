<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Security\OAuth2User;
use SpeedPuzzling\Web\Value\OAuth2Scope;

final class OAuth2ScopeTest extends TestCase
{
    /**
     * role() must produce exactly the role the bundle grants for the scope -
     * pinned against the bundle's own token class rather than a copy of its
     * formula, so a bundle change shows up here.
     */
    public function testRoleMatchesWhatTheBundleGrants(): void
    {
        foreach (OAuth2Scope::cases() as $scope) {
            $token = new OAuth2Token(null, 'token-id', 'client-id', [$scope->value], OAuth2Scope::ROLE_PREFIX);

            self::assertSame([$scope->role()], $token->getRoleNames(), $scope->value);
        }
    }

    /**
     * Every ROLE_OAUTH2_* literal in the API resources has to be a role somebody
     * can actually hold. Attribute strings cannot call role(), so this is the
     * guard: the write check on /me/solving-times spelled the hyphenated scope
     * with an underscore for months and silently matched nothing.
     */
    public function testEveryOAuth2RoleReferencedByApiResourcesIsGrantable(): void
    {
        $grantable = array_map(static fn (OAuth2Scope $scope): string => $scope->role(), OAuth2Scope::cases());
        $grantable[] = OAuth2User::ROLE;

        $files = glob(__DIR__ . '/../../src/Api/V1/*.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        $referenced = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            preg_match_all('/ROLE_OAUTH2_[A-Z0-9:_-]+/', $source, $matches);

            foreach ($matches[0] as $role) {
                $referenced[$role] = basename($file);
            }
        }

        self::assertNotEmpty($referenced, 'Expected the API resources to reference OAuth2 roles');

        foreach ($referenced as $role => $file) {
            self::assertContains($role, $grantable, sprintf('%s references %s, which no scope grants', $file, $role));
        }
    }

    public function testWriteScopesRequireUserContext(): void
    {
        $userOnly = array_values(array_filter(
            OAuth2Scope::cases(),
            static fn (OAuth2Scope $scope): bool => $scope->requiresUserContext(),
        ));

        self::assertSame([OAuth2Scope::SolvingTimesWrite, OAuth2Scope::CollectionsWrite], $userOnly);
    }
}
