<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Documents;

use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Converts document bytes into plain text Claude can read.
 *
 * Office Open XML formats are parsed directly from the ZIP container with
 * network access and entity loading disabled (no XXE). Total decompressed size
 * is capped to defend against zip bombs.
 */
final class TextExtractor
{
    public const SUPPORTED_EXTENSIONS = ['docx', 'pptx', 'xlsx', 'pdf', 'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'html', 'htm', 'log', 'yaml', 'yml'];

    private const PLAIN_TEXT = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'log', 'yaml', 'yml'];
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;
    private const DEFAULT_MAX_ENTRY_BYTES = 20 * 1024 * 1024;
    private const MAX_SHEET_ROWS = 5000;

    /** @var array<int, string> temp file per open ZipArchive */
    private array $tempFiles = [];

    /**
     * @param int $maxEntryBytes largest single XML part (decompressed) that will be read; the zip headers are
     *                           not trusted, the real decompressed bytes are counted
     */
    public function __construct(private readonly int $maxEntryBytes = self::DEFAULT_MAX_ENTRY_BYTES)
    {
    }

    public function supports(string $extension): bool
    {
        return \in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * @throws UnsupportedDocumentException
     */
    public function extract(string $bytes, string $extension): string
    {
        $extension = strtolower($extension);

        $text = match (true) {
            'docx' === $extension => $this->docx($bytes),
            'pptx' === $extension => $this->pptx($bytes),
            'xlsx' === $extension => $this->xlsx($bytes),
            'pdf' === $extension => $this->pdf($bytes),
            'html' === $extension, 'htm' === $extension => $this->html($bytes),
            \in_array($extension, self::PLAIN_TEXT, true) => $this->toUtf8($bytes),
            \in_array($extension, ['doc', 'xls', 'ppt'], true) => throw new UnsupportedDocumentException(sprintf('Legacy .%s files are not supported. Save the file as .%sx in SharePoint to make it readable.', $extension, $extension)),
            default => throw new UnsupportedDocumentException(sprintf('Files of type ".%s" cannot be converted to text. Supported: %s.', $extension, implode(', ', self::SUPPORTED_EXTENSIONS))),
        };

        return $this->normalise($text);
    }

    private function docx(string $bytes): string
    {
        $zip = $this->openZip($bytes);
        try {
            $parts = ['word/document.xml'];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^word/(footnotes|endnotes)\.xml$#', $name)) {
                    $parts[] = $name;
                }
            }

            $out = [];
            foreach ($parts as $part) {
                $xml = $this->readEntry($zip, $part);
                if (null === $xml) {
                    continue;
                }
                $dom = $this->loadXml($xml);
                $xpath = new \DOMXPath($dom);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

                foreach ($xpath->query('//w:body//w:p | //w:footnote//w:p | //w:endnote//w:p') ?: [] as $p) {
                    $line = '';
                    foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $p) ?: [] as $node) {
                        $line .= match ($node->localName) {
                            'tab' => "\t",
                            'br' => "\n",
                            default => $node->textContent,
                        };
                    }
                    // Mark table cells so tabular content stays readable.
                    $inCell = null !== $xpath->query('ancestor::w:tc', $p)?->item(0);
                    $out[] = $inCell && '' !== trim($line) ? '| ' . $line : $line;
                }
            }

            if ([] === $out) {
                throw new UnsupportedDocumentException('The Word document has no readable body.');
            }

            return implode("\n", $out);
        } finally {
            $this->closeZip($zip);
        }
    }

    private function pptx(string $bytes): string
    {
        $zip = $this->openZip($bytes);
        try {
            $slides = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $slides[(int) $m[1]] = $name;
                }
            }
            ksort($slides);

            $out = [];
            foreach ($slides as $number => $name) {
                $out[] = "--- Slide {$number} ---";
                $out = [...$out, ...$this->drawingParagraphs($this->readEntry($zip, $name))];

                $notes = $this->readEntry($zip, "ppt/notesSlides/notesSlide{$number}.xml");
                if (null !== $notes) {
                    $noteLines = array_filter($this->drawingParagraphs($notes), static fn (string $l): bool => !preg_match('/^\d+$/', trim($l)));
                    if ([] !== $noteLines) {
                        $out[] = 'Speaker notes:';
                        $out = [...$out, ...$noteLines];
                    }
                }
            }

            return implode("\n", $out);
        } finally {
            $this->closeZip($zip);
        }
    }

    /** @return list<string> */
    private function drawingParagraphs(?string $xml): array
    {
        if (null === $xml) {
            return [];
        }
        $dom = $this->loadXml($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $lines = [];
        foreach ($xpath->query('//a:p') ?: [] as $p) {
            $line = '';
            foreach ($xpath->query('.//a:t', $p) ?: [] as $t) {
                $line .= $t->textContent;
            }
            if ('' !== trim($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function xlsx(string $bytes): string
    {
        $zip = $this->openZip($bytes);
        try {
            $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

            $shared = [];
            if (null !== ($xml = $this->readEntry($zip, 'xl/sharedStrings.xml'))) {
                $dom = $this->loadXml($xml);
                foreach ($dom->getElementsByTagNameNS($main, 'si') as $si) {
                    $text = '';
                    foreach ($si->getElementsByTagNameNS($main, 't') as $t) {
                        $text .= $t->textContent;
                    }
                    $shared[] = $text;
                }
            }

            // Map sheet names to their XML parts via the workbook relationships.
            $targets = [];
            if (null !== ($rels = $this->readEntry($zip, 'xl/_rels/workbook.xml.rels'))) {
                foreach ($this->loadXml($rels)->getElementsByTagName('Relationship') as $rel) {
                    $targets[$rel->getAttribute('Id')] = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $rel->getAttribute('Target')) ?? '', '/');
                }
            }

            $sheets = [];
            if (null !== ($wb = $this->readEntry($zip, 'xl/workbook.xml'))) {
                foreach ($this->loadXml($wb)->getElementsByTagNameNS($main, 'sheet') as $sheet) {
                    $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                    if (isset($targets[$rid])) {
                        $sheets[$sheet->getAttribute('name')] = $targets[$rid];
                    }
                }
            }

            $out = [];
            foreach ($sheets as $sheetName => $part) {
                $xml = $this->readEntry($zip, $part);
                if (null === $xml) {
                    continue;
                }
                $out[] = "--- Sheet: {$sheetName} ---";
                $rows = 0;
                foreach ($this->loadXml($xml)->getElementsByTagNameNS($main, 'row') as $row) {
                    if (++$rows > self::MAX_SHEET_ROWS) {
                        $out[] = sprintf('[... sheet truncated after %d rows ...]', self::MAX_SHEET_ROWS);
                        break;
                    }
                    $cells = [];
                    foreach ($row->getElementsByTagNameNS($main, 'c') as $c) {
                        $type = $c->getAttribute('t');
                        $v = $c->getElementsByTagNameNS($main, 'v')->item(0)?->textContent;
                        $cells[] = match ($type) {
                            's' => $shared[(int) $v] ?? '',
                            'inlineStr' => $c->getElementsByTagNameNS($main, 't')->item(0)?->textContent ?? '',
                            default => (string) $v,
                        };
                    }
                    if ('' !== trim(implode('', $cells))) {
                        $out[] = implode("\t", $cells);
                    }
                }
            }

            return implode("\n", $out);
        } finally {
            $this->closeZip($zip);
        }
    }

    private function pdf(string $bytes): string
    {
        try {
            $config = new PdfConfig();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(64 * 1024 * 1024);
            $text = (new PdfParser([], $config))->parseContent($bytes)->getText();
        } catch (\Throwable) {
            throw new UnsupportedDocumentException('The PDF could not be read (it may be encrypted or corrupt).');
        }

        if ('' === trim($text)) {
            throw new UnsupportedDocumentException('The PDF contains no extractable text (it may be a scanned image).');
        }

        return $text;
    }

    private function html(string $bytes): string
    {
        $html = $this->toUtf8($bytes);
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
    }

    private function openZip(string $bytes): \ZipArchive
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adxmcp');
        if (false === $tmp || false === file_put_contents($tmp, $bytes)) {
            throw new UnsupportedDocumentException('Could not buffer the document for reading.');
        }

        $zip = new \ZipArchive();
        $ok = $zip->open($tmp, \ZipArchive::RDONLY);
        if (true !== $ok) {
            @unlink($tmp);
            throw new UnsupportedDocumentException('The document is not a valid Office file.');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
            $zip->close();
            @unlink($tmp);
            throw new UnsupportedDocumentException('The document expands to an unsafe size and was not processed.');
        }

        $this->tempFiles[spl_object_id($zip)] = $tmp;

        return $zip;
    }

    private function closeZip(\ZipArchive $zip): void
    {
        $id = spl_object_id($zip);
        $zip->close();
        if (isset($this->tempFiles[$id])) {
            @unlink($this->tempFiles[$id]);
            unset($this->tempFiles[$id]);
        }
    }

    private function readEntry(\ZipArchive $zip, string $name): ?string
    {
        $stream = $zip->getStream($name);
        if (false === $stream) {
            return null;
        }

        // Read at most one byte more than the limit: a part that lies about its size in the
        // zip header is still stopped by what it actually decompresses to.
        $content = stream_get_contents($stream, $this->maxEntryBytes + 1);
        fclose($stream);

        if (false === $content) {
            return null;
        }
        if (\strlen($content) > $this->maxEntryBytes) {
            throw new UnsupportedDocumentException('A part of the document is too large to process safely.');
        }

        return $content;
    }

    private function loadXml(string $xml): \DOMDocument
    {
        // Office files are UTF-8. A UTF-16 part would hide "<!DOCTYPE" from the check below
        // (every character is followed by a NUL byte), so refuse anything that is not 8-bit text.
        if (str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF") || str_contains(substr($xml, 0, 64), "\0")) {
            throw new UnsupportedDocumentException('The document uses an unsupported text encoding and was rejected for safety.');
        }

        // Reject DTDs outright: Office XML never needs them, and they are the vector for XXE / billion-laughs.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new UnsupportedDocumentException('The document contains a DTD and was rejected for safety.');
        }

        // No LIBXML_PARSEHUGE: libxml's own limits on node size, depth and entity expansion stay active.
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            throw new UnsupportedDocumentException('The document contains malformed XML.');
        }

        // Second line of defence, independent of how the bytes were encoded.
        if ($dom->doctype instanceof \DOMDocumentType) {
            throw new UnsupportedDocumentException('The document contains a DTD and was rejected for safety.');
        }

        return $dom;
    }

    private function toUtf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text; // strip control chars except newline/tab
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
