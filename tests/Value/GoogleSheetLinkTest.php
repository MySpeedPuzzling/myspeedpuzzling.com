<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\GoogleSheetLink;

/**
 * docs/features/data-import/google-sheets.md "The link". Every id here is made up.
 */
final class GoogleSheetLinkTest extends TestCase
{
    private const string ID = '1MadeUpDocumentId_0123456789-abcdefghijklmnop';

    #[DataProvider('provideSheetLinks')]
    public function testTheDocumentIdIsCutOutOfEveryFormOfASheetsLink(string $pasted): void
    {
        $link = GoogleSheetLink::parse($pasted);

        self::assertSame(self::ID, $link->spreadsheetId);
        self::assertNull($link->problem);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSheetLinks(): iterable
    {
        $base = 'https://docs.google.com/spreadsheets/d/' . self::ID;

        yield 'edit with a tab' => [$base . '/edit#gid=0'];
        yield 'edit, the new tab form' => [$base . '/edit?gid=1234567#gid=1234567'];
        yield 'share link' => [$base . '/edit?usp=sharing'];
        yield 'the phone app\'s link' => [$base . '/edit?usp=drivesdk'];
        yield 'phone browser' => [$base . '/htmlview?usp=sheets_home'];
        yield 'view' => [$base . '/view'];
        yield 'preview' => [$base . '/preview'];
        yield 'pubhtml' => [$base . '/pubhtml?gid=0&single=true'];
        yield 'copy' => [$base . '/copy'];
        yield 'an export link' => [$base . '/export?format=csv&gid=5'];
        yield 'nothing after the id' => [$base];
        yield 'a slash after the id' => [$base . '/'];
        yield 'with the account' => ['https://docs.google.com/spreadsheets/u/1/d/' . self::ID . '/edit'];
        yield 'without the scheme' => ['docs.google.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'http' => ['http://docs.google.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'upper case host' => ['HTTPS://Docs.Google.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'spaces around' => ["  \n" . $base . "/edit#gid=0 \n"];
    }

    #[DataProvider('provideOtherLinks')]
    public function testAnythingThatIsNoSheetsDocumentLinkIsRefused(string $pasted, string $problem = GoogleSheetLink::NOT_A_SHEET): void
    {
        $link = GoogleSheetLink::parse($pasted);

        self::assertNull($link->spreadsheetId);
        self::assertSame($problem, $link->problem);
    }

    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function provideOtherLinks(): iterable
    {
        yield 'nothing' => [''];
        yield 'words' => ['my results'];
        yield 'a Google document' => ['https://docs.google.com/document/d/' . self::ID . '/edit'];
        yield 'slides' => ['https://docs.google.com/presentation/d/' . self::ID . '/edit'];
        yield 'a form' => ['https://docs.google.com/forms/d/' . self::ID . '/viewform'];
        yield 'a Drive folder' => ['https://drive.google.com/drive/folders/' . self::ID];
        yield 'a Drive file' => ['https://drive.google.com/file/d/' . self::ID . '/view'];
        yield 'Drive open' => ['https://drive.google.com/open?id=' . self::ID];
        yield 'the sheets home' => ['https://docs.google.com/spreadsheets/u/0/'];
        yield 'an id too short' => ['https://docs.google.com/spreadsheets/d/short/edit'];
        yield 'an id with other characters' => ['https://docs.google.com/spreadsheets/d/' . substr(self::ID, 0, 30) . '%2F..%2F' . '/edit'];
        yield 'another host' => ['https://example.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'a host that only starts like Google\'s' => ['https://docs.google.com.example.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'a host that only ends like Google\'s' => ['https://evildocs.google.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'a user in the address' => ['https://docs.google.com@example.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'credentials' => ['https://user:pass@docs.google.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'another port' => ['https://docs.google.com:8443/spreadsheets/d/' . self::ID . '/edit'];
        yield 'another scheme' => ['ftp://docs.google.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'javascript' => ['javascript:alert(1)//docs.google.com/spreadsheets/d/' . self::ID];
        yield 'a line break inside' => ["https://docs.google.com/spreadsheets/d/\n" . self::ID];
        yield 'the link in the query of another' => ['https://example.com/?u=https://docs.google.com/spreadsheets/d/' . self::ID . '/edit'];
        yield 'far too long' => ['https://docs.google.com/spreadsheets/d/' . self::ID . '/edit?x=' . str_repeat('a', 2000)];
        yield 'published to the web' => ['https://docs.google.com/spreadsheets/d/e/2PACX-1vMadeUpPublishedId_0123456789abcdefghij/pubhtml', GoogleSheetLink::PUBLISHED];
        yield 'published, as a file' => ['https://docs.google.com/spreadsheets/d/e/2PACX-1vMadeUpPublishedId_0123456789abcdefghij/pub?output=xlsx', GoogleSheetLink::PUBLISHED];
    }
}
