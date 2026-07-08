<?php

class TextEncoding
{
    private const MOJIBAKE_PATTERN = '/(?:à[¸¹º]|Ã|Â|â)/u';

    private static $windows1252ReverseMap = [
        0x20AC => 0x80,
        0x201A => 0x82,
        0x0192 => 0x83,
        0x201E => 0x84,
        0x2026 => 0x85,
        0x2020 => 0x86,
        0x2021 => 0x87,
        0x02C6 => 0x88,
        0x2030 => 0x89,
        0x0160 => 0x8A,
        0x2039 => 0x8B,
        0x0152 => 0x8C,
        0x017D => 0x8E,
        0x2018 => 0x91,
        0x2019 => 0x92,
        0x201C => 0x93,
        0x201D => 0x94,
        0x2022 => 0x95,
        0x2013 => 0x96,
        0x2014 => 0x97,
        0x02DC => 0x98,
        0x2122 => 0x99,
        0x0161 => 0x9A,
        0x203A => 0x9B,
        0x0153 => 0x9C,
        0x017E => 0x9E,
        0x0178 => 0x9F,
    ];

    public static function normalize($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }
            return $value;
        }

        if (is_string($value)) {
            return self::repairMojibake($value);
        }

        return $value;
    }

    public static function repairMojibake($value)
    {
        if ($value === '' || !preg_match(self::MOJIBAKE_PATTERN, $value)) {
            return $value;
        }

        $bytes = '';
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return $value;
        }

        foreach ($chars as $char) {
            $codepoint = mb_ord($char, 'UTF-8');
            if ($codepoint <= 0xFF) {
                $bytes .= chr($codepoint);
                continue;
            }

            if (isset(self::$windows1252ReverseMap[$codepoint])) {
                $bytes .= chr(self::$windows1252ReverseMap[$codepoint]);
                continue;
            }

            return $value;
        }

        if (!mb_check_encoding($bytes, 'UTF-8')) {
            return $value;
        }

        return self::isBetterRepair($value, $bytes) ? $bytes : $value;
    }

    private static function isBetterRepair($original, $candidate)
    {
        if (strpos($candidate, "\xEF\xBF\xBD") !== false) {
            return false;
        }

        $originalHits = preg_match_all(self::MOJIBAKE_PATTERN, $original);
        $candidateHits = preg_match_all(self::MOJIBAKE_PATTERN, $candidate);

        if ($candidateHits >= $originalHits) {
            return false;
        }

        return preg_match('/\p{Thai}/u', $candidate) || $candidateHits === 0;
    }
}
