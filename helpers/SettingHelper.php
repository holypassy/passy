<?php
class SettingHelper {
    
    public static function getEfrisConfig() {
        $settingModel = new Setting();
        $settings = $settingModel->getGrouped()['efris'];
        
        return [
            'enabled' => $settings['efris_enabled'] == '1',
            'url' => $settings['efris_url'],
            'device_no' => $settings['efris_device_no'],
            'tin' => $settings['efris_tin'],
            'api_key' => $settings['efris_api_key'],
            'test_mode' => $settings['efris_test_mode'] == '1',
            'auto_sync' => $settings['efris_auto_sync'] == '1'
        ];
    }
    
    public static function getRoyaltyConfig() {
        $settingModel = new Setting();
        $settings = $settingModel->getGrouped()['royalty'];
        
        return [
            'enabled' => $settings['royalty_enabled'] == '1',
            'points_per_amount' => (int)$settings['royalty_points_per_amount'],
            'amount_per_point' => (int)$settings['royalty_amount_per_point'],
            'expiry_days' => (int)$settings['royalty_expiry_days'],
            'min_redemption' => (int)$settings['royalty_min_redemption'],
            'max_redemption' => (int)$settings['royalty_max_redemption'],
            'birthday_bonus' => (int)$settings['royalty_birthday_bonus'],
            'new_member_bonus' => (int)$settings['royalty_new_member_bonus'],
            'tiers' => [
                'bronze' => (int)$settings['customer_tier_bronze_min'],
                'silver' => (int)$settings['customer_tier_silver_min'],
                'gold' => (int)$settings['customer_tier_gold_min'],
                'platinum' => (int)$settings['customer_tier_platinum_min']
            ]
        ];
    }
    
    public static function getDiscountConfig() {
        $settingModel = new Setting();
        $settings = $settingModel->getGrouped()['discount'];
        
        return [
            'approval_required' => $settings['discount_approval_required'] == '1',
            'max_amount' => (float)$settings['discount_max_amount'],
            'max_percentage' => (float)$settings['discount_max_percentage'],
            'auto_approve_role' => $settings['discount_auto_approve_role'],
            'refund' => [
                'days_limit' => (int)$settings['refund_days_limit'],
                'requires_approval' => $settings['refund_requires_approval'] == '1',
                'restock' => $settings['refund_restock'] == '1',
                'method' => $settings['refund_method']
            ],
            'bulk_discount' => [
                'enabled' => $settings['bulk_discount_enabled'] == '1',
                'min_items' => (int)$settings['bulk_discount_min_items'],
                'percentage' => (float)$settings['bulk_discount_percentage']
            ]
        ];
    }
    
    public static function getBarcodeConfig() {
        $settingModel = new Setting();
        $settings = $settingModel->getGrouped()['barcode'];
        
        return [
            'enabled' => $settings['barcode_enabled'] == '1',
            'format' => $settings['barcode_format'],
            'width' => (int)$settings['barcode_width'],
            'height' => (int)$settings['barcode_height'],
            'include_price' => $settings['barcode_include_price'] == '1',
            'include_name' => $settings['barcode_include_name'] == '1',
            'scanner' => [
                'auto_enter' => $settings['scanner_auto_enter'] == '1',
                'prefix' => $settings['scanner_prefix'],
                'suffix' => $settings['scanner_suffix'],
                'timeout' => (int)$settings['scanner_timeout'],
                'beep_on_scan' => $settings['scanner_beep_on_scan'] == '1',
                'validation' => $settings['scanner_validation'] == '1'
            ]
        ];
    }
    
    public static function calculatePoints($amount, $config = null) {
        if (!$config) {
            $config = self::getRoyaltyConfig();
        }
        
        if (!$config['enabled']) {
            return 0;
        }
        
        return floor($amount / $config['points_per_amount']);
    }
    
    public static function calculateTier($totalSpent, $config = null) {
        if (!$config) {
            $config = self::getRoyaltyConfig();
        }
        
        if ($totalSpent >= $config['tiers']['platinum']) {
            return 'platinum';
        } elseif ($totalSpent >= $config['tiers']['gold']) {
            return 'gold';
        } elseif ($totalSpent >= $config['tiers']['silver']) {
            return 'silver';
        } else {
            return 'bronze';
        }
    }
    
    public static function validateDiscount($amount, $percentage, $config = null) {
        if (!$config) {
            $config = self::getDiscountConfig();
        }
        
        $errors = [];
        
        if ($amount > $config['max_amount']) {
            $errors[] = "Discount amount exceeds maximum allowed (UGX " . number_format($config['max_amount']) . ")";
        }
        
        if ($percentage > $config['max_percentage']) {
            $errors[] = "Discount percentage exceeds maximum allowed ({$config['max_percentage']}%)";
        }
        
        return $errors;
    }
    
    public static function getTierBadge($tier) {
        $badges = [
            'bronze' => '<span class="badge-bronze"><i class="fas fa-medal"></i> Bronze</span>',
            'silver' => '<span class="badge-silver"><i class="fas fa-medal"></i> Silver</span>',
            'gold' => '<span class="badge-gold"><i class="fas fa-crown"></i> Gold</span>',
            'platinum' => '<span class="badge-platinum"><i class="fas fa-gem"></i> Platinum</span>'
        ];
        
        return $badges[$tier] ?? '<span class="badge">' . ucfirst($tier) . '</span>';
    }
    
    public static function generateBarcode($data, $config = null) {
        if (!$config) {
            $config = self::getBarcodeConfig();
        }
        
        if (!$config['enabled']) {
            return null;
        }
        
        // This would integrate with a barcode generation library
        // For now, return a placeholder
        return [
            'data' => $data,
            'format' => $config['format'],
            'width' => $config['width'],
            'height' => $config['height'],
            'url' => "https://barcode.tec-it.com/barcode.ashx?data=" . urlencode($data) . "&code=" . $config['format']
        ];
    }
}
?>