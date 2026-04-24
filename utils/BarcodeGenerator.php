<?php
namespace Utils;

use App\Models\SettingsModel;

class BarcodeGenerator {
    private $settings;
    
    public function __construct() {
        $settingsModel = new SettingsModel();
        $this->settings = $settingsModel->getGroup('barcode');
    }
    
    public function generate($code, $productName = '', $price = 0) {
        $format = $this->settings['barcode_format'] ?? 'CODE128';
        $width = $this->settings['barcode_width'] ?? 50;
        $height = $this->settings['barcode_height'] ?? 30;
        
        // Return barcode data (in a real implementation, you'd generate an actual barcode)
        return [
            'code' => $code,
            'format' => $format,
            'width' => $width,
            'height' => $height,
            'include_price' => $this->settings['barcode_include_price'],
            'include_name' => $this->settings['barcode_include_name'],
            'product_name' => $productName,
            'price' => $price
        ];
    }
    
    public function validateScan($scannedCode) {
        $prefix = $this->settings['scanner_prefix'] ?? '';
        $suffix = $this->settings['scanner_suffix'] ?? '';
        
        // Remove prefix and suffix
        if ($prefix && strpos($scannedCode, $prefix) === 0) {
            $scannedCode = substr($scannedCode, strlen($prefix));
        }
        if ($suffix && substr($scannedCode, -strlen($suffix)) === $suffix) {
            $scannedCode = substr($scannedCode, 0, -strlen($suffix));
        }
        
        return $scannedCode;
    }
}