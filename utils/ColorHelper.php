<?php
namespace Utils;

class ColorHelper {
    
    public static function validateHex($color) {
        return preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color);
    }
    
    public static function rgbToHex($r, $g, $b) {
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }
    
    public static function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) == 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            'r' => hexdec($hex[0] . $hex[1]),
            'g' => hexdec($hex[2] . $hex[3]),
            'b' => hexdec($hex[4] . $hex[5])
        ];
    }
    
    public static function getContrastColor($hex) {
        $rgb = self::hexToRgb($hex);
        $luminance = (0.299 * $rgb['r'] + 0.587 * $rgb['g'] + 0.114 * $rgb['b']) / 255;
        return $luminance > 0.5 ? '#000000' : '#ffffff';
    }
}