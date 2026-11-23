<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The owner's list stream that removes the last item swaps in the empty state. These streams only ever answer the
 * list's owner, so the empty state is the owner's one - it used to render the "someone else's list" branch with no
 * player and crash with a TypeError after the change was already saved (Sentry WEB-CM).
 */
final class EmptyListStreamTemplatesTest extends KernelTestCase
{
    private const string PUZZLE_ID = '018d0000-0000-0000-0000-000000000001';

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('provideStreamsEmptyingTheList')]
    public function testLastItemGoneRendersTheOwnersEmptyState(string $template, array $parameters, string $ownEmptyTitle): void
    {
        $html = $this->twig->render($template, $parameters + [
            'puzzle_id' => self::PUZZLE_ID,
            'item_id' => self::PUZZLE_ID,
            'remaining_count' => 0,
            'message' => 'Done',
            'context' => 'list',
        ]);

        self::assertStringContainsString($ownEmptyTitle, $html);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function provideStreamsEmptyingTheList(): iterable
    {
        yield 'sell/swap: marked as sold' => ['sell-swap/_mark_sold_stream.html.twig', [], 'Your sell/swap list is empty'];
        yield 'sell/swap: removed' => ['sell-swap/_remove_from_list_stream.html.twig', [], 'Your sell/swap list is empty'];
        yield 'wish list: removed' => ['wishlist/_remove_from_list_stream.html.twig', [], 'Your wish list is empty'];
        yield 'wish list: borrowed' => ['lend-borrow/_stream.html.twig', [], 'Your wish list is empty'];
        yield 'wish list: added to a collection' => [
            'collections/_stream.html.twig',
            ['should_remove_from_wishlist' => true],
            'Your wish list is empty',
        ];
    }

    public function testEmptyStatesWithoutAnyContextDoNotCrash(): void
    {
        $sellSwap = $this->twig->render('sell-swap/_empty_state.html.twig');
        $wishList = $this->twig->render('wishlist/_empty_state.html.twig');

        self::assertStringContainsString('bi-shop', $sellSwap);
        self::assertStringContainsString('bi-heart', $wishList);
    }
}
