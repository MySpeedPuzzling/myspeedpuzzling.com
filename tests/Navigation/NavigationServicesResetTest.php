<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * FrankenPHP runs in worker mode: a service that keeps per-request data in a property leaks it into the next request
 * unless it implements ResetInterface. The delivery web server is php -S, where such a leak cannot show - so every
 * class under src/Navigation/ and src/Twig/Navigation* with a mutable instance property must implement it (plan §3.1).
 */
final class NavigationServicesResetTest extends TestCase
{
    /**
     * Memos created outside src/Navigation/ by navigation items - each item appends the classes it creates or gives a
     * memo (plan §3.1, decision 103).
     *
     * @var list<class-string>
     */
    private const array EXTRA_CLASSES = [
        // HELP-01a
        \SpeedPuzzling\Web\Twig\HelpTwigExtension::class,
        // ADD-03
        \SpeedPuzzling\Web\Twig\AddEntryTwigExtension::class,
        // INB-02 (the memo itself is src/Navigation/InboxBadge.php)
        \SpeedPuzzling\Web\Twig\InboxTwigExtension::class,
        // INB-04b (the memo itself is src/Navigation/FavoritesFeed.php)
        \SpeedPuzzling\Web\Twig\FavoritesFeedTwigExtension::class,
        // MENU-01: the per-page queue counts memo shared by the Menu's Your roles and the ☰ dot
        \SpeedPuzzling\Web\Twig\AdminQueueCountsTwigExtension::class,
    ];

    public function testMutableNavigationServicesResetBetweenRequests(): void
    {
        $classes = [...$this->classesIn('Navigation', '*.php'), ...$this->classesIn('Twig', 'Navigation*.php'), ...self::EXTRA_CLASSES];
        self::assertContains('SpeedPuzzling\\Web\\Navigation\\NavigationRegistry', $classes);
        self::assertContains('SpeedPuzzling\\Web\\Twig\\NavigationTwigExtension', $classes);

        $offenders = [];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);

            if ($reflection->isEnum() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            foreach ($reflection->getProperties() as $property) {
                if (!$property->isStatic() && !$property->isReadOnly() && !$reflection->implementsInterface(ResetInterface::class)) {
                    $offenders[] = $class . '::$' . $property->getName();
                }
            }
        }

        self::assertSame([], $offenders, 'Mutable properties in navigation services without ResetInterface (reset them between requests, or keep the memo on the request)');
    }

    /**
     * @return list<class-string>
     */
    private function classesIn(string $directory, string $pattern): array
    {
        $classes = [];

        foreach ((new Finder())->files()->in(__DIR__ . '/../../src/' . $directory)->name($pattern)->sortByName() as $file) {
            $class = 'SpeedPuzzling\\Web\\' . $directory . '\\' . str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            self::assertTrue(class_exists($class) || enum_exists($class) || interface_exists($class), $class);
            /** @var class-string $class */
            $classes[] = $class;
        }

        return $classes;
    }
}
