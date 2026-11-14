<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;

/**
 * A workbook written part by part, for the shapes PhpSpreadsheet's writer can not produce (a formula without a saved
 * value, an error value, several tables on a sheet, broken or hostile parts). Every fixture is made up - no real
 * spreadsheet is ever in the repository.
 *
 * Cell styles: index 0 is "General", index N is the Nth format handed to styles() (1-based).
 */
final class RawXlsx
{
    /** @var list<array{name: string, xml: string, state: null|string, tables: list<string>, links: array<string, string>}> */
    private array $sheets = [];
    /** @var list<string> */
    private array $sharedStrings = [];
    /** @var list<int|string> */
    private array $formats = [];
    private bool $date1904 = false;
    /** @var array<string, null|string> */
    private array $overrides = [];

    /**
     * @param list<string> $tables each a whole table part, e.g. RawXlsx::table('Results', 'A6:D40')
     * @param array<string, string> $links cell reference => address
     */
    public function sheet(string $name, string $worksheetXml, null|string $state = null, array $tables = [], array $links = []): self
    {
        $this->sheets[] = ['name' => $name, 'xml' => $worksheetXml, 'state' => $state, 'tables' => $tables, 'links' => $links];

        return $this;
    }

    /**
     * @param list<string> $strings plain texts, or whole <si>…</si> elements (rich text)
     */
    public function sharedStrings(array $strings): self
    {
        $this->sharedStrings = $strings;

        return $this;
    }

    /**
     * @param list<int|string> $formats a built-in format number (14, 46 …) or a format code ('mmmm yyyy')
     */
    public function styles(array $formats): self
    {
        $this->formats = $formats;

        return $this;
    }

    public function date1904(): self
    {
        $this->date1904 = true;

        return $this;
    }

    /**
     * Replaces or adds a part; null leaves a part out.
     */
    public function part(string $entry, null|string $content): self
    {
        $this->overrides[$entry] = $content;

        return $this;
    }

    public function save(string $path): void
    {
        $parts = $this->parts();

        foreach ($this->overrides as $entry => $content) {
            if ($content === null) {
                unset($parts[$entry]);
            } else {
                $parts[$entry] = $content;
            }
        }

        @unlink($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($parts as $entry => $content) {
            $zip->addFromString($entry, $content);
        }

        $zip->close();
    }

    /**
     * <worksheet> around rows: row number => column letters => a cell made by n(), s(), inline(), b(), e(), f().
     *
     * @param array<int, array<string, string>> $rows
     * @param string $before XML before <sheetData> (a <dimension>, <sheetViews>)
     * @param string $after XML after it (<mergeCells>)
     */
    public static function worksheet(array $rows, string $before = '', string $after = ''): string
    {
        $data = '';

        foreach ($rows as $number => $cells) {
            $data .= sprintf('<row r="%d">', $number);

            foreach ($cells as $column => $cell) {
                $data .= str_replace('<c ', sprintf('<c r="%s%d" ', $column, $number), $cell);
            }

            $data .= '</row>';
        }

        return self::worksheetAround('<sheetData>' . $data . '</sheetData>', $before, $after);
    }

    public static function worksheetAround(string $sheetData, string $before = '', string $after = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $before . $sheetData . $after . '{links}{tables}</worksheet>';
    }

    public static function n(string $value, int $style = 0): string
    {
        return sprintf('<c s="%d"><v>%s</v></c>', $style, $value);
    }

    public static function s(int $sharedStringIndex, int $style = 0): string
    {
        return sprintf('<c s="%d" t="s"><v>%d</v></c>', $style, $sharedStringIndex);
    }

    public static function inline(string $text): string
    {
        return sprintf('<c t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', htmlspecialchars($text, ENT_XML1));
    }

    public static function b(bool $value): string
    {
        return sprintf('<c t="b"><v>%d</v></c>', $value ? 1 : 0);
    }

    public static function e(string $code): string
    {
        return sprintf('<c t="e"><v>%s</v></c>', $code);
    }

    /**
     * A formula with the value the spreadsheet saved for it (null = it saved none).
     *
     * @param null|string $type the cell type of the saved value: null = a number, 'str' = text, 'b', 'e'
     */
    public static function f(string $formula, null|string $value, null|string $type = null, int $style = 0): string
    {
        return sprintf(
            '<c s="%d"%s><f>%s</f>%s</c>',
            $style,
            $type === null ? '' : sprintf(' t="%s"', $type),
            htmlspecialchars($formula, ENT_XML1),
            $value === null ? '' : sprintf('<v>%s</v>', htmlspecialchars($value, ENT_XML1)),
        );
    }

    public static function table(string $name, string $range, int $headerRows = 1, int $totalsRows = 0): string
    {
        $count = CellRange::fromA1($range)?->columns() ?? 1;
        $columns = '';

        for ($column = 1; $column <= $count; $column++) {
            $columns .= sprintf('<tableColumn id="%1$d" name="Column%1$d"/>', $column);
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="%1$s" displayName="%1$s" ref="%2$s"%3$s%4$s>'
            . '<tableColumns count="%5$d">%6$s</tableColumns></table>',
            $name,
            $range,
            $headerRows === 1 ? '' : sprintf(' headerRowCount="%d"', $headerRows),
            $totalsRows === 0 ? '' : sprintf(' totalsRowCount="%d"', $totalsRows),
            $count,
            $columns,
        );
    }

    /**
     * A workbook of one sheet written straight to disk (a million cells do not fit a test's memory as one string).
     * Per row, repeated up to $columns: a name of its own · a brand (one of 50) · pieces · a day shown as m/d · a
     * duration · a checkbox · a note typed in place · a formula with its saved number · a month · a percentage.
     */
    public static function generate(string $path, int $rows, int $columns): void
    {
        (new self())->styles(['m/d', '[h]:mm:ss', 'mmmm\ yyyy', '0%'])->sheet('Results', self::worksheet([]))->save($path);
        $sheetPath = $path . '.sheet.xml';
        $stringsPath = $path . '.strings.xml';
        $sheet = fopen($sheetPath, 'wb');
        $strings = fopen($stringsPath, 'wb');

        if ($sheet === false || $strings === false) {
            throw new \RuntimeException('The generated workbook can not be written.');
        }

        fwrite($sheet, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        fwrite($strings, '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');

        for ($brand = 0; $brand < 50; $brand++) {
            fwrite($strings, sprintf('<si><t>Invented brand %d</t></si>', $brand));
        }

        $stringCount = 50;

        for ($row = 1; $row <= $rows; $row++) {
            $cells = '';

            for ($column = 1; $column <= $columns; $column++) {
                $cells .= match (($column - 1) % 10) {
                    0 => sprintf('<c t="s"><v>%d</v></c>', $stringCount++),
                    1 => sprintf('<c t="s"><v>%d</v></c>', $row % 50),
                    2 => sprintf('<c><v>%d</v></c>', 500 + $row % 7 * 500),
                    3 => sprintf('<c s="1"><v>%d</v></c>', 44_000 + $row % 1_500),
                    4 => sprintf('<c s="2"><v>%.10F</v></c>', (1_800 + $row % 9_000) / 86_400),
                    5 => sprintf('<c t="b"><v>%d</v></c>', $row % 2),
                    6 => sprintf('<c t="inlineStr"><is><t>note %d</t></is></c>', $row),
                    7 => sprintf('<c><f>C%1$d/E%1$d</f><v>%2$.4F</v></c>', $row, $row / 7),
                    8 => sprintf('<c s="3"><v>%d</v></c>', 44_000 + $row % 1_500),
                    default => sprintf('<c s="4"><v>%.2F</v></c>', $row % 100 / 100),
                };

                if (($column - 1) % 10 === 0) {
                    fwrite($strings, sprintf('<si><t>Made-up puzzle %d-%d</t></si>', $row, $column));
                }
            }

            fwrite($sheet, sprintf('<row r="%d">%s</row>', $row, $cells));
        }

        fwrite($sheet, '</sheetData></worksheet>');
        fwrite($strings, '</sst>');
        fclose($sheet);
        fclose($strings);

        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml', 0, 0, \ZipArchive::FL_OVERWRITE);
        $zip->addFile($stringsPath, 'xl/sharedStrings.xml', 0, 0, \ZipArchive::FL_OVERWRITE);
        $zip->close();
        unlink($sheetPath);
        unlink($stringsPath);
    }

    /**
     * @return array<string, string>
     */
    private function parts(): array
    {
        $contentTypes = '';
        $workbookSheets = '';
        $workbookRelationships = '';
        $parts = [];
        $tableNumber = 0;

        foreach ($this->sheets as $index => $sheet) {
            $number = $index + 1;
            $entry = sprintf('xl/worksheets/sheet%d.xml', $number);
            $relationships = '';
            $tableParts = '';
            $hyperlinks = '';

            foreach ($sheet['tables'] as $table) {
                $tableNumber++;
                $parts[sprintf('xl/tables/table%d.xml', $tableNumber)] = $table;
                $relationships .= sprintf('<Relationship Id="rIdT%1$d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table%1$d.xml"/>', $tableNumber);
                $tableParts .= sprintf('<tablePart r:id="rIdT%d"/>', $tableNumber);
                $contentTypes .= sprintf('<Override PartName="/xl/tables/table%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>', $tableNumber);
            }

            $linkNumber = 0;

            foreach ($sheet['links'] as $reference => $address) {
                $linkNumber++;
                $relationships .= sprintf('<Relationship Id="rIdL%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="%s" TargetMode="External"/>', $linkNumber, htmlspecialchars($address, ENT_XML1 | ENT_QUOTES));
                $hyperlinks .= sprintf('<hyperlink ref="%s" r:id="rIdL%d"/>', $reference, $linkNumber);
            }

            $parts[$entry] = strtr($sheet['xml'], [
                '{links}' => $hyperlinks === '' ? '' : '<hyperlinks>' . $hyperlinks . '</hyperlinks>',
                '{tables}' => $tableParts === '' ? '' : sprintf('<tableParts count="%d">%s</tableParts>', count($sheet['tables']), $tableParts),
            ]);

            if ($relationships !== '') {
                $parts[sprintf('xl/worksheets/_rels/sheet%d.xml.rels', $number)] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
            }

            $contentTypes .= sprintf('<Override PartName="/%s" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', $entry);
            $workbookSheets .= sprintf(
                '<sheet name="%s" sheetId="%d"%s r:id="rId%d"/>',
                htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES),
                $number,
                $sheet['state'] === null ? '' : sprintf(' state="%s"', $sheet['state']),
                $number,
            );
            $workbookRelationships .= sprintf('<Relationship Id="rId%1$d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%1$d.xml"/>', $number);
        }

        $strings = '';

        foreach ($this->sharedStrings as $string) {
            $strings .= str_starts_with($string, '<si>') ? $string : '<si><t xml:space="preserve">' . htmlspecialchars($string, ENT_XML1) . '</t></si>';
        }

        $numberFormats = '';
        $cellStyles = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';

        foreach ($this->formats as $index => $format) {
            $formatId = is_int($format) ? $format : 164 + $index;

            if (is_string($format)) {
                $numberFormats .= sprintf('<numFmt numFmtId="%d" formatCode="%s"/>', $formatId, htmlspecialchars($format, ENT_XML1 | ENT_QUOTES));
            }

            $cellStyles .= sprintf('<xf numFmtId="%d" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>', $formatId);
        }

        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                . $contentTypes . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . ($this->date1904 ? '<workbookPr date1904="1"/>' : '<workbookPr/>')
                . '<sheets>' . $workbookSheets . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . $workbookRelationships
                . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '<Relationship Id="rIdStrings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . ($numberFormats === '' ? '' : '<numFmts>' . $numberFormats . '</numFmts>')
                . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs>' . $cellStyles . '</cellXfs></styleSheet>',
            'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . sprintf('<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="%1$d" uniqueCount="%1$d">', count($this->sharedStrings))
                . $strings . '</sst>',
            ...$parts,
        ];
    }
}
