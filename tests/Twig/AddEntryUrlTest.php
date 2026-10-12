<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Twig\AddEntryTwigExtension;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

/**
 * add_entry_url() - where every "Add" of the navigation leads (docs/features/navigation README D7, ADD-03): guests to the
 * Add explainer, a puzzle page to the add page with that puzzle, everything else to the add page. Without a statement.
 */
final class AddEntryUrlTest extends KernelTestCase
{
    public function testAGuestGetsTheAddExplainer(): void
    {
        $this->pushRequest('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 'puzzle_detail', ['puzzleId' => PuzzleFixture::PUZZLE_500_01]);

        self::assertSame('/en/add', $this->addEntryUrlWithoutStatements());
    }

    public function testAPuzzlePageAddsThatPuzzle(): void
    {
        $this->signIn();
        $this->pushRequest('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 'puzzle_detail', ['puzzleId' => PuzzleFixture::PUZZLE_500_01]);

        self::assertSame('/en/puzzle-add/' . PuzzleFixture::PUZZLE_500_01, $this->addEntryUrlWithoutStatements());
    }

    public function testAnyOtherPageAddsWithoutAPuzzle(): void
    {
        $this->signIn();
        $this->pushRequest('/en/faq', 'faq');

        self::assertSame('/en/puzzle-add', $this->addEntryUrlWithoutStatements());
    }

    public function testOtherPagesOfAPuzzleAddWithoutIt(): void
    {
        // Only the puzzle page itself: the stopwatch of a puzzle carries a puzzleId too, but Add is not about it there
        $this->signIn();
        $this->pushRequest('/en/puzzle-stopwatch/' . PuzzleFixture::PUZZLE_500_01, 'stopwatch_puzzle', ['puzzleId' => PuzzleFixture::PUZZLE_500_01]);

        self::assertSame('/en/puzzle-add', $this->addEntryUrlWithoutStatements());
    }

    public function testTheLocaleOfThePage(): void
    {
        $this->signIn();
        $this->pushRequest('/de/puzzle/' . PuzzleFixture::PUZZLE_500_01, 'puzzle_detail', ['puzzleId' => PuzzleFixture::PUZZLE_500_01], 'de');

        self::assertSame('/de/puzzle-hinzufuegen/' . PuzzleFixture::PUZZLE_500_01, $this->addEntryUrlWithoutStatements());
    }

    public function testTwigFunction(): void
    {
        $this->pushRequest('/en/faq', 'faq');

        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);

        self::assertSame('/en/add', $twig->createTemplate('{{ add_entry_url() }}')->render());
    }

    private function addEntryUrlWithoutStatements(): string
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $url = self::getContainer()->get(AddEntryTwigExtension::class)->addEntryUrl();

        foreach ($debugDataHolder->getData() as $connection => $statements) {
            self::assertSame([], $statements, 'add_entry_url() runs no statement on ' . $connection);
        }

        return $url;
    }

    /**
     * @param array<string, string> $routeParams
     */
    private function pushRequest(string $uri, string $route, array $routeParams = [], string $locale = 'en'): void
    {
        $request = Request::create('http://localhost' . $uri);
        $request->attributes->set('_route', $route . '.' . $locale);
        $request->attributes->set('_canonical_route', $route);
        $request->attributes->set('_locale', $locale);
        $request->attributes->set('_route_params', [...$routeParams, '_locale' => $locale]);

        foreach ($routeParams as $name => $value) {
            $request->attributes->set($name, $value);
        }

        $requestStack = self::getContainer()->get(RequestStack::class);

        while ($requestStack->pop() !== null) {
            // Each case starts with its own main request
        }

        $requestStack->push($request);
        self::getContainer()->get(RouterInterface::class)->getContext()->setParameter('_locale', $locale);
    }

    private function signIn(): void
    {
        $userAccount = self::getContainer()->get(UserAccountRepository::class)->findByUserId(PlayerFixture::PLAYER_REGULAR_USER_ID);
        self::assertNotNull($userAccount);

        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($userAccount, 'main', $userAccount->getRoles()));
    }
}
