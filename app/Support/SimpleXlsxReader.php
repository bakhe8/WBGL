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
                    $text = (string)$si->t;
                    // Decode HTML entities (&amp; -> &, &lt; -> <, etc.)
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
            // Simple logic: assume sequential cells but they might be sparse.
            // Improve: use 'r' attribute like 'A1' to place in correct index if needed.
            // For BGL context, sequential is usually fine. 
            // Better: loop through all 'c' children.
            
            foreach ($row->c as $cell) {
                $value = '';
                
                if (isset($cell->v)) {
                    // Check if it's a shared string
                    if (isset($cell['t']) && (string)$cell['t'] === 's') {
                        $index = (int)$cell->v;
                        $value = isset($sharedStrings[$index]) ? $sharedStrings[$index] : '';
                    } else {
                        $value = (string)$cell->v;
                        // Decode HTML entities for inline values too
                        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                
                $rowData[] = $value;
            }
            
            if (!empty(array_filter($rowData))) {
                $rows[] = $rowData;
            }
        }
        
        return $rows;
    }
}
