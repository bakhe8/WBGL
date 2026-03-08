<?php
declare(strict_types=1);

namespace App\Support;

use ZipArchive;
use Exception;

/**
 * Simple XLSX Reader
 * Lightweight XLSX parser without composer dependencies
 * Adapted from setup/SimpleXlsxReader.php
 */
class SimpleXlsxReader
{
    private const LETTER_A_CODE = 65;

    public static function read($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception('File not found');
        }
        
        // XLSX is a ZIP file
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception('Cannot open XLSX file');
        }
        
        // Read shared strings
        $sharedStrings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            $stringsXml = simplexml_load_string($xml);
            if ($stringsXml) {
                foreach ($stringsXml->si as $si) {
                    $text = self::extractInlineText($si);
                    $sharedStrings[] = $text;
                }
            }
        }
        
        // Read first sheet
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            $zip->close();
            throw new Exception('No worksheet found');
        }
        
        $zip->close();
        
        // Parse sheet
        $xml = simplexml_load_string($sheetXml);
        $rows = [];
        
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];

            foreach ($row->c as $cell) {
                $cellReference = (string)($cell['r'] ?? '');
                $columnIndex = self::columnIndexFromReference($cellReference);
                if ($columnIndex === null) {
                    // Fallback when cell reference is missing.
                    $columnIndex = count($rowData);
                }

                $rowData[$columnIndex] = self::extractCellValue($cell, $sharedStrings);
            }

            if (!empty($rowData)) {
                ksort($rowData, SORT_NUMERIC);
            }

            if (!empty(array_filter($rowData, static function ($value): bool {
                return trim((string)$value) !== '';
            }))) {
                $rows[] = $rowData;
            }
        }
        
        return $rows;
    }

    /**
     * Extract text from <si> or <is> structures.
     */
    private static function extractInlineText(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return self::decodeText((string)$node->t);
        }

        // Rich text format: <r><t>...</t></r>
        $parts = [];
        if (isset($node->r)) {
            foreach ($node->r as $run) {
                if (isset($run->t)) {
                    $parts[] = (string)$run->t;
                }
            }
        }

        return self::decodeText(implode('', $parts));
    }

    /**
     * Resolve a cell value for the supported XLSX cell types.
     *
     * @param array<int,string> $sharedStrings
     */
    private static function extractCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string)($cell['t'] ?? '');

        if ($type === 's') {
            $index = isset($cell->v) ? (int)$cell->v : -1;
            $value = ($index >= 0 && isset($sharedStrings[$index])) ? $sharedStrings[$index] : '';
            return self::decodeText($value);
        }

        if ($type === 'inlineStr' && isset($cell->is)) {
            return self::extractInlineText($cell->is);
        }

        if (isset($cell->v)) {
            return self::decodeText((string)$cell->v);
        }

        // Some generators may keep string value in <is> without setting type explicitly.
        if (isset($cell->is)) {
            return self::extractInlineText($cell->is);
        }

        return '';
    }

    private static function decodeText(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Convert cell reference (e.g. "D8", "AA12") into zero-based column index.
     */
    private static function columnIndexFromReference(string $reference): ?int
    {
        if ($reference === '') {
            return null;
        }

        if (!preg_match('/^([A-Z]+)[0-9]+$/i', $reference, $matches)) {
            return null;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;
        $length = strlen($letters);

        for ($i = 0; $i < $length; $i++) {
            $charCode = ord($letters[$i]);
            if ($charCode < self::LETTER_A_CODE || $charCode > 90) {
                return null;
            }
            $index = ($index * 26) + ($charCode - self::LETTER_A_CODE + 1);
        }

        return $index - 1;
    }
}
