<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Documents;

use Adx\MarketingMcp\Documents\DocumentService;
use Adx\MarketingMcp\Documents\TextExtractor;
use Adx\MarketingMcp\Documents\UnsupportedDocumentException;
use Adx\MarketingMcp\SharePoint\DriveItem;
use PHPUnit\Framework\TestCase;

final class TextExtractorTest extends TestCase
{
    private TextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TextExtractor();
    }

    /** @param array<string, string> $files */
    private static function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    public function testDocx(): void
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $bytes = self::zip(['word/document.xml' => "<w:document {$w}><w:body>"
            . '<w:p><w:r><w:t>Brand voice</w:t></w:r><w:r><w:tab/><w:t>is practical.</w:t></w:r></w:p>'
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Cell A</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . '</w:body></w:document>']);

        self::assertSame("Brand voice\tis practical.\n| Cell A", $this->extractor->extract($bytes, 'docx'));
    }

    public function testPptxIncludesSlidesInOrderAndNotes(): void
    {
        $a = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
        $bytes = self::zip([
            'ppt/slides/slide10.xml' => "<p:sld xmlns:p=\"p\" {$a}><a:p><a:r><a:t>Tenth</a:t></a:r></a:p></p:sld>",
            'ppt/slides/slide2.xml' => "<p:sld xmlns:p=\"p\" {$a}><a:p><a:r><a:t>Second</a:t></a:r></a:p></p:sld>",
            'ppt/notesSlides/notesSlide2.xml' => "<p:notes xmlns:p=\"p\" {$a}><a:p><a:r><a:t>Say hello</a:t></a:r></a:p><a:p><a:r><a:t>2</a:t></a:r></a:p></p:notes>",
        ]);

        $text = $this->extractor->extract($bytes, 'pptx');

        self::assertSame("--- Slide 2 ---\nSecond\nSpeaker notes:\nSay hello\n--- Slide 10 ---\nTenth", $text);
    }

    public function testXlsxUsesSheetNamesAndSharedStrings(): void
    {
        $m = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $r = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $bytes = self::zip([
            'xl/workbook.xml' => "<workbook {$m} {$r}><sheets><sheet name=\"Prices\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>",
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml' => "<sst {$m}><si><t>SKU</t></si><si><t>Price</t></si><si><t>DECK-01</t></si></sst>",
            'xl/worksheets/sheet1.xml' => "<worksheet {$m}><sheetData>"
                . '<row><c t="s"><v>0</v></c><c t="s"><v>1</v></c></row>'
                . '<row><c t="s"><v>2</v></c><c><v>49.95</v></c></row>'
                . '</sheetData></worksheet>',
        ]);

        self::assertSame("--- Sheet: Prices ---\nSKU\tPrice\nDECK-01\t49.95", $this->extractor->extract($bytes, 'xlsx'));
    }

    public function testRejectsXmlWithDoctype(): void
    {
        $bytes = self::zip(['word/document.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///c:/windows/win.ini">]><x>&e;</x>']);

        $this->expectException(UnsupportedDocumentException::class);
        $this->extractor->extract($bytes, 'docx');
    }

    public function testRejectsDoctypeHiddenInUtf16(): void
    {
        // Every character is followed by a NUL byte, so a plain text search for "<!DOCTYPE" would not see it.
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e "boom">]><x>&e;</x>';
        $bytes = self::zip(['word/document.xml' => "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8')]);

        $this->expectException(UnsupportedDocumentException::class);
        $this->expectExceptionMessage('encoding');
        $this->extractor->extract($bytes, 'docx');
    }

    public function testRejectsDoctypeWithDifferentCase(): void
    {
        $bytes = self::zip(['word/document.xml' => '<?xml version="1.0"?><!doctype x><x/>']);

        $this->expectException(UnsupportedDocumentException::class);
        $this->extractor->extract($bytes, 'docx');
    }

    public function testRejectsAnXmlPartThatDecompressesBeyondTheLimit(): void
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        // Highly compressible, so the zip itself is tiny: only the real decompressed size gives it away.
        $bytes = self::zip(['word/document.xml' => "<w:document {$w}><w:body><w:p><w:r><w:t>" . str_repeat('a', 5000) . '</w:t></w:r></w:p></w:body></w:document>']);

        $this->expectException(UnsupportedDocumentException::class);
        $this->expectExceptionMessage('too large');
        (new TextExtractor(maxEntryBytes: 1000))->extract($bytes, 'docx');
    }

    public function testNormalDocumentsPassTheSizeLimit(): void
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $bytes = self::zip(['word/document.xml' => "<w:document {$w}><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>"]);

        self::assertSame('Hello', (new TextExtractor(maxEntryBytes: 1000))->extract($bytes, 'docx'));
    }

    public function testHtmlStripsScriptsAndTags(): void
    {
        $text = $this->extractor->extract('<p>Hello &amp; welcome</p><script>alert(1)</script><p>Bye</p>', 'html');

        self::assertSame("Hello & welcome\nBye", $text);
    }

    public function testConvertsWindows1252Text(): void
    {
        self::assertSame('Café', $this->extractor->extract("Caf\xE9", 'txt'));
    }

    public function testLegacyFormatsGiveActionableMessage(): void
    {
        $this->expectExceptionMessage('Save the file as .docx');
        $this->extractor->extract('', 'doc');
    }

    public function testCorruptOfficeFile(): void
    {
        $this->expectException(UnsupportedDocumentException::class);
        $this->extractor->extract('not a zip', 'docx');
    }

    public function testChunkingBreaksOnLineBoundaries(): void
    {
        $item = new DriveItem('id', 'a.txt', false, 'a.txt', null);
        $text = str_repeat("line of text\n", 100); // 1300 chars

        $first = DocumentService::chunk($item, $text, 0, 500);
        self::assertStringEndsWith("\n", $first->text);
        self::assertSame(mb_strlen($first->text), $first->nextOffset);

        $offset = 0;
        $joined = '';
        do {
            $c = DocumentService::chunk($item, $text, $offset, 500);
            $joined .= $c->text;
            $offset = $c->nextOffset;
        } while (null !== $offset);

        self::assertSame($text, $joined);
    }
}
