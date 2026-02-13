<?php
/**
 * Bank Name Normalization Utility
 */

namespace App\Support;

class BankNormalizer {
    
    public static function normalize($name) {
        if (empty($name)) {
            return '';
        }
        
        // Remove Arabic diacritics
        $name = preg_replace('/[\x{064B}-\x{065F}]/u', '', $name);
        
        // Lowercase
        $name = mb_strtolower($name, 'UTF-8');
        
        // Remove whitespace and punctuation
        $name = preg_replace('/[\s\-_.,;:()]+/u', '', $name);
        
        // Remove common words
        $remove = ['bank', 'بنك', 'مصرف', 'the', 'al', 'ال'];
        foreach ($remove as $word) {
            $name = str_replace($word, '', $name);
        }
        
        // Character normalization
        $map = [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        $name = strtr($name, $map);
        
        return $name;
    }
}
