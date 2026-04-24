<?php
class BarcodeHelper {
    
    public static function generateCode128($data) {
        // Simple Code 128 barcode generation
        // This is a placeholder - in production, use a library like php-barcode
        
        $code = '';
        $chars = str_split($data);
        
        foreach ($chars as $char) {
            $code .= '<div class="barcode-char" style="display: inline-block; width: 10px; height: 40px; background: #000;"></div>';
        }
        
        return $code;
    }
    
    public static function generateQRCode($data) {
        // QR code generation placeholder
        // In production, use a library like phpqrcode
        
        return '<div class="qrcode-placeholder" style="width: 100px; height: 100px; background: #f0f0f0; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-qrcode fa-3x"></i>
                </div>';
    }
    
    public static function generateEAN13($productCode) {
        // EAN-13 barcode generation
        // Simple checksum calculation
        
        if (strlen($productCode) != 12) {
            $productCode = str_pad($productCode, 12, '0', STR_PAD_LEFT);
        }
        
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ($i % 2 == 0) ? $productCode[$i] * 1 : $productCode[$i] * 3;
        }
        
        $checksum = (10 - ($sum % 10)) % 10;
        
        return $productCode . $checksum;
    }
    
    public static function renderBarcode($data, $type = 'code128') {
        switch ($type) {
            case 'qrcode':
                return self::generateQRCode($data);
            case 'ean13':
                $code = self::generateEAN13($data);
                return '<div class="barcode">' . $code . '</div>';
            default:
                return self::generateCode128($data);
        }
    }
}
?>