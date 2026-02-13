<?php
// mb_levenshtein helper using standard algorithm for UTF-8 arrays

if (!function_exists('mb_levenshtein')) {
    function mb_levenshtein($str1, $str2) {
        $charMap = [];
        $s1 = mb_str_split($str1);
        $s2 = mb_str_split($str2);
        
        // Simple matrix calculation for Levenshtein
        $len1 = count($s1);
        $len2 = count($s2);
        
        $dp = array_fill(0, $len1 + 1, array_fill(0, $len2 + 1, 0));
        
        for ($i = 0; $i <= $len1; $i++) $dp[$i][0] = $i;
        for ($j = 0; $j <= $len2; $j++) $dp[0][$j] = $j;
        
        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                $cost = ($s1[$i - 1] === $s2[$j - 1]) ? 0 : 1;
                $dp[$i][$j] = min(
                    $dp[$i - 1][$j] + 1,    // deletion
                    $dp[$i][$j - 1] + 1,    // insertion
                    $dp[$i - 1][$j - 1] + $cost // substitution
                );
            }
        }
        
        return $dp[$len1][$len2];
    }
}
