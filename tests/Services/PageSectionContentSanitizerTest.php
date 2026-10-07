<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Services\PageSectionContentSanitizer;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The security boundary of organiser-written page sections (config/packages/html_sanitizer.php): what survives a save.
 */
final class PageSectionContentSanitizerTest extends KernelTestCase
{
    private PageSectionContentSanitizer $sanitizer;
    private PageSectionOwner $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->sanitizer = self::getContainer()->get(PageSectionContentSanitizer::class);
        $this->owner = PageSectionOwner::competition(CompetitionFixture::COMPETITION_WJPC_2024);
    }

    public function testScriptsStylesFramesAndHandlersAreStripped(): void
    {
        $html = $this->richText(
            '<h3>Rules</h3><p onclick="alert(1)" onmouseover="alert(2)">Be <strong>fair</strong></p>'
            . '<script>alert("xss")</script><style>body{display:none}</style>'
            . '<iframe src="https://evil.example"></iframe><object data="x"></object>'
            . '<img src="x" onerror="alert(3)"><svg onload="alert(4)"></svg>',
        );

        self::assertStringContainsString('<h3>Rules</h3>', $html);
        self::assertStringContainsString('<strong>fair</strong>', $html);

        foreach (['<script', 'alert', '<style', 'display:none', '<iframe', '<object', 'onerror', 'onclick', 'onmouseover', '<svg'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    public function testIdClassAndStyleAreStrippedEverywhere(): void
    {
        // An organiser's id could clobber an element of the page - e.g. Turbo's modal frame
        $html = $this->richText(
            '<h2 id="modal-frame" class="d-none" style="color:red">Heading</h2>'
            . '<p id="main-content" class="x">Text</p><a href="https://example.com" id="a" class="btn" style="x">Link</a>',
        );

        self::assertStringContainsString('<h2>Heading</h2>', $html);
        self::assertStringContainsString('<p>Text</p>', $html);
        self::assertStringNotContainsString('id=', $html);
        self::assertStringNotContainsString('class=', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testBulletAndNumberedListsSurvive(): void
    {
        // What the editor saves: Quill 2's getSemanticHTML() (not its root.innerHTML, where a bullet list is an
        // <ol><li data-list="bullet"> that would come out numbered)
        $html = $this->richText(
            '<ul><li>First bullet</li><li>Second bullet<ul><li>Nested</li></ul></li></ul>'
            . '<ol><li>First step</li><li>Second step</li></ol>',
        );

        self::assertSame(
            '<ul><li>First bullet</li><li>Second bullet<ul><li>Nested</li></ul></li></ul><ol><li>First step</li><li>Second step</li></ol>',
            $html,
        );

        // Quill's own attributes never survive
        self::assertSame('<ol><li>Item</li></ol>', $this->richText('<ol><li data-list="bullet">Item</li></ol>'));
    }

    public function testLinksAreHttpOrMailtoAndOpenLikeTheOtherExternalLinks(): void
    {
        $html = $this->richText(
            '<p><a href="https://example.com/rules">Rules</a> <a href="mailto:org@example.com">Mail</a>'
            . ' <a href="javascript:alert(1)">Bad</a> <a href="data:text/html,x">Data</a> <a href="/relative">Rel</a></p>',
        );

        self::assertStringContainsString('href="https://example.com/rules"', $html);
        // The sanitizer encodes the @ - the browser reads it the same
        self::assertStringContainsString('href="mailto:org&#64;example.com"', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('data:text', $html);
        self::assertStringNotContainsString('href="/relative"', $html);
        self::assertSame(5, substr_count($html, 'rel="noopener noreferrer nofollow ugc"'));
        self::assertSame(5, substr_count($html, 'target="_blank"'));
    }

    public function testPicturesOnlyFromOurOwnImageHosts(): void
    {
        $ours = 'http://localhost:19100/preset:puzzle_medium/plain/competition-pages/x.jpg';
        $html = $this->richText('<p><img src="' . $ours . '" alt="Ours"><img src="https://evil.example/tracker.png" alt="Theirs"><img src="data:image/png;base64,AAAA"></p>');

        self::assertStringContainsString('src="' . $ours . '"', $html);
        self::assertStringNotContainsString('evil.example', $html);
        self::assertStringNotContainsString('data:image', $html);
    }

    public function testNonBreakingSpacesBecomeOrdinarySpaces(): void
    {
        // Quill 2's getSemanticHTML() separates every word with &nbsp; - the text would never wrap on a phone
        self::assertSame('<p>Bring your own mat</p>', $this->richText('<p>Bring&nbsp;your&nbsp;own' . "\u{00A0}" . 'mat</p>'));
    }

    public function testAnEmptyEditorIsNoText(): void
    {
        self::assertSame('', $this->richText('<p></p>'));
        self::assertSame('', $this->richText('<p><br></p><p> </p>'));
        self::assertSame('', $this->richText('<script>alert(1)</script>'));
    }

    public function testGalleryAndSponsorPicturesMustBeUploadsOfTheSamePage(): void
    {
        $own = $this->owner->uploadDirectory() . '0199a1b2-0000-7000-8000-000000000001.jpg';
        $otherPage = PageSectionOwner::competition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024)->uploadDirectory() . 'photo.jpg';

        $gallery = $this->sanitizer->sanitize(PageSectionType::Gallery, ['images' => [
            ['path' => $own, 'caption' => 'Ours'],
            ['path' => 'https://evil.example/photo.jpg', 'caption' => 'Remote'],
            ['path' => $otherPage, 'caption' => 'Another event'],
            ['path' => $this->owner->uploadDirectory() . '../../player-avatar.jpg', 'caption' => 'Traversal'],
            ['path' => '//evil.example/x.jpg', 'caption' => 'Protocol relative'],
        ]], $this->owner);

        self::assertSame([['path' => $own, 'caption' => 'Ours']], $gallery['images']);

        $sponsors = $this->sanitizer->sanitize(PageSectionType::Sponsors, ['sponsors' => [
            ['name' => 'Local shop', 'url' => 'https://shop.example', 'logoPath' => $otherPage],
            ['name' => '', 'url' => 'https://nameless.example', 'logoPath' => $own],
        ]], $this->owner);

        self::assertSame([['name' => 'Local shop', 'url' => 'https://shop.example', 'logoPath' => null]], $sponsors['sponsors']);
    }

    public function testLinksEmailAndRowLimits(): void
    {
        $links = $this->sanitizer->sanitize(PageSectionType::Links, ['links' => [
            ['label' => 'Facebook', 'url' => 'https://facebook.com/groups/puzzle'],
            ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['label' => 'No scheme', 'url' => 'facebook.com'],
        ]], $this->owner);

        self::assertSame([['label' => 'Facebook', 'url' => 'https://facebook.com/groups/puzzle']], $links['links']);

        $contact = $this->sanitizer->sanitize(PageSectionType::Contact, ['email' => 'not an e-mail', 'phone' => " +420 123\u{0007} ", 'note' => 'Hi'], $this->owner);
        self::assertSame(['email' => '', 'phone' => '+420 123', 'note' => 'Hi'], $contact);

        $faq = $this->sanitizer->sanitize(PageSectionType::Faq, ['items' => array_fill(0, 80, ['question' => 'Q?', 'answer' => 'A'])], $this->owner);
        self::assertIsArray($faq['items']);
        self::assertCount(PageSectionContentSanitizer::MAX_ROWS, $faq['items']);
    }

    public function testPlainTextIsKeptAsTypedForTwigToEscape(): void
    {
        $faq = $this->sanitizer->sanitize(PageSectionType::Faq, ['items' => [
            ['question' => 'Is <b>this</b> allowed?', 'answer' => "Yes <3\nsee you"],
        ]], $this->owner);

        self::assertSame([['question' => 'Is <b>this</b> allowed?', 'answer' => "Yes <3\nsee you"]], $faq['items']);
    }

    private function richText(string $html): string
    {
        $content = $this->sanitizer->sanitize(PageSectionType::RichText, ['html' => $html], $this->owner);
        self::assertIsString($content['html']);

        return $content['html'];
    }
}
