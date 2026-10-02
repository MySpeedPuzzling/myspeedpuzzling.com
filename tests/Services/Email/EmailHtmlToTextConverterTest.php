<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Email;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Email\EmailHtmlToTextConverter;

/**
 * The plain-text part of e-mails (docs/features/transactional-emails.md): links stay usable, paragraphs stay
 * paragraphs, nothing hidden leaks in. The same rules on real rendered e-mails: EmailTextPartTest.
 */
final class EmailHtmlToTextConverterTest extends TestCase
{
    private EmailHtmlToTextConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new EmailHtmlToTextConverter();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLinks(): iterable
    {
        yield 'text and URL' => [
            '<p>See the <a href="https://myspeedpuzzling.com/en/puzzle">puzzle list</a> now.</p>',
            'See the puzzle list (https://myspeedpuzzling.com/en/puzzle) now.',
        ];

        yield 'the query string survives, entities decoded' => [
            '<p><a href="https://myspeedpuzzling.com/login-link/check?user=a%40b.test&amp;expires=1767225600&amp;hash=Ab_c-D%3D">Sign in</a></p>',
            'Sign in (https://myspeedpuzzling.com/login-link/check?user=a%40b.test&expires=1767225600&hash=Ab_c-D%3D)',
        ];

        yield 'text that is the URL is written once' => [
            '<p><a href="https://myspeedpuzzling.com/">https://myspeedpuzzling.com</a></p>',
            'https://myspeedpuzzling.com/',
        ];

        yield 'text that is the URL without its scheme is written once' => [
            '<p><a href="https://myspeedpuzzling.com/en/faq">myspeedpuzzling.com/en/faq</a></p>',
            'https://myspeedpuzzling.com/en/faq',
        ];

        yield 'a link without text is its URL' => [
            '<p><a href="https://myspeedpuzzling.com/en/hub"></a></p>',
            'https://myspeedpuzzling.com/en/hub',
        ];

        yield 'mailto with the address as text' => [
            '<p>Write to <a href="mailto:simona@speedpuzzling.cz">simona@speedpuzzling.cz</a>.</p>',
            'Write to simona@speedpuzzling.cz.',
        ];

        yield 'mailto with other text' => [
            '<p><a href="mailto:jan@myspeedpuzzling.com?subject=Hello">Write us</a></p>',
            'Write us (jan@myspeedpuzzling.com)',
        ];

        yield 'anchors and javascript are only text' => [
            '<p><a href="#top">Back to top</a> <a href="javascript:void(0)">Nothing</a></p>',
            'Back to top Nothing',
        ];

        yield 'inline tags inside a link keep words together' => [
            '<p><a href="https://x.test/a"><b>Re</b>set <span>it</span></a></p>',
            'Reset it (https://x.test/a)',
        ];
    }

    #[DataProvider('provideLinks')]
    public function testLinks(string $html, string $expected): void
    {
        self::assertSame($expected, $this->convert($html));
    }

    public function testInkyButtonIsAParagraphWithItsUrl(): void
    {
        $html = '<p>Enter the code or:</p>'
            . '<center><table class="float-center button" align="center"><tr><td><table><tr><td>'
            . '<a href="https://myspeedpuzzling.com/login-link/check?a=1&amp;b=2">
                    Sign in
               </a>'
            . '</td></tr></table></td></tr></table></center>'
            . '<p>The link expires in 15 minutes.</p>';

        self::assertSame(
            "Enter the code or:\n\nSign in: https://myspeedpuzzling.com/login-link/check?a=1&b=2\n\nThe link expires in 15 minutes.",
            $this->convert($html),
        );
    }

    public function testButtonWhoseLabelEndsASentenceGetsNoExtraColon(): void
    {
        self::assertSame(
            'Ready? https://x.test/go',
            $this->convert('<table class="button"><tr><td><a href="https://x.test/go">Ready?</a></td></tr></table>'),
        );
    }

    public function testParagraphsHeadingsAndLineBreaks(): void
    {
        $html = '<h3>Your sign-in code</h3>'
            . '<p>Thank you for helping us – and happy puzzling!<br>Your MySpeedPuzzling team</p>'
            . '<p>Second   paragraph
                  spread over lines.</p>';

        self::assertSame(
            "Your sign-in code\n\nThank you for helping us – and happy puzzling!\nYour MySpeedPuzzling team\n\nSecond paragraph spread over lines.",
            $this->convert($html),
        );
    }

    public function testListsWithMultiLineAndNestedItems(): void
    {
        $html = '<p>Waiting for you:</p>'
            . '<ul>
                   <li>
                       Circle of Colors – 01:10:26
                       <br><span>the same time recorded twice</span>
                   </li>
                   <li>Two<ul><li>Nested</li></ul></li>
               </ul>'
            . '<ol><li>First</li><li>Second</li></ol>'
            . '<p>After.</p>';

        self::assertSame(
            "Waiting for you:\n\n- Circle of Colors – 01:10:26\n  the same time recorded twice\n- Two\n  - Nested\n\n1. First\n2. Second\n\nAfter.",
            $this->convert($html),
        );
    }

    public function testHiddenContentIsNotWritten(): void
    {
        $html = '<!DOCTYPE html><html><head><title>Subject line</title>'
            . '<style>@media only screen { table.container { width: 100% !important; } }</style></head><body>'
            . '<div class="preheader" style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">'
            . 'Your code is 123456 &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp;</div>'
            . '<table class="spacer hide-for-large" style="display: none !important; mso-hide: all;"><tr><td>Spacer text</td></tr></table>'
            . '<div style="DISPLAY:NONE">Shouting hidden</div>'
            . '<p hidden>Hidden attribute</p>'
            . '<script>alert(1)</script>'
            . '<!--[if mso]><p>Outlook only comment</p><![endif]-->'
            . '<p>Visible text</p>'
            . '</body></html>';

        self::assertSame('Visible text', $this->convert($html));
    }

    public function testSpacersNbspAndZeroWidthCharactersLeaveNothing(): void
    {
        $html = '<table><tr><td height="16">&nbsp;</td></tr></table>'
            . '<p>Hello&nbsp;there&zwnj;&#8203;!</p>'
            . '<table><tr><td height="20">&nbsp;</td></tr></table>';

        self::assertSame('Hello there!', $this->convert($html));
    }

    public function testImagesAreTheirAltText(): void
    {
        self::assertSame(
            'Puzzle photo',
            $this->convert('<p><img src="https://x.test/a.png" alt=""><img src="https://x.test/b.png" alt="Puzzle photo"></p>'),
        );
    }

    public function testLinkedLogoBesideTheTextLinkWritesTheUrlOnce(): void
    {
        // The header before 2026-10: the logo and the name as two links to the same page
        $twoLinks = '<table role="presentation"><tr>'
            . '<td width="32"><a href="https://myspeedpuzzling.com/"><img src="https://myspeedpuzzling.com/img/logo.png" alt="" width="32"></a></td>'
            . '<td><a href="https://myspeedpuzzling.com/">MySpeedPuzzling</a></td>'
            . '</tr></table><h3>Title</h3>';

        // ... and as one link holding both
        $oneLink = '<table role="presentation"><tr><td>'
            . '<a href="https://myspeedpuzzling.com/"><img src="https://myspeedpuzzling.com/img/logo.png" alt="" width="32" height="28"> MySpeedPuzzling</a>'
            . '</td></tr></table><h3>Title</h3>';

        foreach ([$twoLinks, $oneLink] as $html) {
            self::assertSame("MySpeedPuzzling (https://myspeedpuzzling.com/)\n\nTitle", $this->convert($html));
        }
    }

    public function testTableCellsAreLines(): void
    {
        self::assertSame(
            "Requester\nJan\n\nNext",
            $this->convert('<table><tr><th>Requester</th></tr><tr><td>Jan</td></tr></table><p>Next</p>'),
        );
    }

    public function testFragmentWithoutDocumentAndPlainText(): void
    {
        self::assertSame(
            "Name: Jan\nMessage: Hi",
            $this->convert("<p>\n    <b>Name:</b> Jan<br>\n    <b>Message:</b> Hi\n</p>"),
        );
        self::assertSame('Just text & more', $this->convert('Just text &amp; more'));
        self::assertSame('', $this->convert(''));
    }

    public function testNeverMoreThanOneBlankLine(): void
    {
        $text = $this->convert('<p>A</p><br><br><br><div><p></p></div><table><tr><td><p>B</p></td></tr></table><hr><p>C</p>');

        self::assertSame("A\n\nB\n\nC", $text);
    }

    private function convert(string $html): string
    {
        return $this->converter->convert($html, 'utf-8');
    }
}
