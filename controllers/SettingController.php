<?php
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/EfrisApi.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class SettingController {
    private $settingModel;
    private $efrisApi;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->settingModel = new Setting();
        $this->efrisApi = new EfrisApi();
    }
    
    public function getAll() {
        AuthMiddleware::requireRole(['admin']);
        
        $settings = $this->settingModel->getGrouped();
        
        Response::json([
            'success' => true,
            'data' => $settings
        ]);
    }
    
    public function getByGroup($group) {
        AuthMiddleware::requireRole(['admin']);
        
        $groups = ['efris', 'royalty', 'discount', 'barcode'];
        if (!in_array($group, $groups)) {
            Response::json(['success' => false, 'message' => 'Invalid settings group'], 400);
        }
        
        $settings = $this->settingModel->getGrouped();
        
        Response::json([
            'success' => true,
            'data' => $settings[$group] ?? []
        ]);
    }
    
    public function updateEfris() {
        AuthMiddleware::requireRole(['admin']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $settings = [
            'efris_enabled' => isset($input['efris_enabled']) ? '1' : '0',
            'efris_url' => $input['efris_url'] ?? '',
            'efris_device_no' => $input['efris_device_no'] ?? '',
            'efris_tin' => $input['efris_tin'] ?? '',
            'efris_api_key' => $input['efris_api_key'] ?? '',
            'efris_client_id' => $input['efris_client_id'] ?? '',
            'efris_client_secret' => $input['efris_client_secret'] ?? '',
            'efris_auto_sync' => isset($input['efris_auto_sync']) ? '1' : '0',
            'efris_test_mode' => isset($input['efris_test_mode']) ? '1' : '0'
        ];
        
        $result = $this->settingModel->setMultiple($settings);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'EFRIS settings saved successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to save settings'], 500);
        }
    }
    
    public function updateRoyalty() {
        AuthMiddleware::requireRole(['admin']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $settings = [
            'royalty_enabled' => isset($input['royalty_enabled']) ? '1' : '0',
            'royalty_points_per_amount' => $input['royalty_points_per_amount'] ?? '1000',
            'royalty_amount_per_point' => $input['royalty_amount_per_point'] ?? '10',
            'royalty_expiry_days' => $input['royalty_expiry_days'] ?? '365',
            'royalty_min_redemption' => $input['royalty_min_redemption'] ?? '5000',
            'royalty_max_redemption' => $input['royalty_max_redemption'] ?? '100000',
            'royalty_birthday_bonus' => $input['royalty_birthday_bonus'] ?? '500',
            'royalty_new_member_bonus' => $input['royalty_new_member_bonus'] ?? '200',
            'customer_tier_bronze_min' => $input['customer_tier_bronze_min'] ?? '0',
            'customer_tier_silver_min' => $input['customer_tier_silver_min'] ?? '100000',
            'customer_tier_gold_min' => $input['customer_tier_gold_min'] ?? '500000',
            'customer_tier_platinum_min' => $input['customer_tier_platinum_min'] ?? '1000000'
        ];
        
        $result = $this->settingModel->setMultiple($settings);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Royalty settings saved successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to save settings'], 500);
        }
    }
    
    public function updateDiscount() {
        AuthMiddleware::requireRole(['admin']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $settings = [
            'discount_approval_required' => isset($input['discount_approval_required']) ? '1' : '0',
            'discount_max_amount' => $input['discount_max_amount'] ?? '50000',
            'discount_max_percentage' => $input['discount_max_percentage'] ?? '20',
            'discount_auto_approve_role' => $input['discount_auto_approve_role'] ?? 'manager',
            'refund_days_limit' => $input['refund_days_limit'] ?? '30',
            'refund_requires_approval' => isset($input['refund_requires_approval']) ? '1' : '0',
            'refund_restock' => isset($input['refund_restock']) ? '1' : '0',
            'refund_method' => $input['refund_method'] ?? 'original',
            'bulk_discount_enabled' => isset($input['bulk_discount_enabled']) ? '1' : '0',
            'bulk_discount_min_items' => $input['bulk_discount_min_items'] ?? '5',
            'bulk_discount_percentage' => $input['bulk_discount_percentage'] ?? '10'
        ];
        
        $result = $this->settingModel->setMultiple($settings);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Discount settings saved successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to save settings'], 500);
        }
    }
    
    public function updateBarcode() {
        AuthMiddleware::requireRole(['admin']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $settings = [
            'barcode_enabled' => isset($input['barcode_enabled']) ? '1' : '0',
            'barcode_format' => $input['barcode_format'] ?? 'CODE128',
            'barcode_width' => $input['barcode_width'] ?? '2',
            'barcode_height' => $input['barcode_height'] ?? '50',
            'barcode_include_price' => isset($input['barcode_include_price']) ? '1' : '0',
            'barcode_include_name' => isset($input['barcode_include_name']) ? '1' : '0',
            'scanner_auto_enter' => isset($input['scanner_auto_enter']) ? '1' : '0',
            'scanner_prefix' => $input['scanner_prefix'] ?? '',
            'scanner_suffix' => $input['scanner_suffix'] ?? '\n',
            'scanner_timeout' => $input['scanner_timeout'] ?? '100',
            'scanner_beep_on_scan' => isset($input['scanner_beep_on_scan']) ? '1' : '0',
            'scanner_validation' => isset($input['scanner_validation']) ? '1' : '0'
        ];
        
        $result = $this->settingModel->setMultiple($settings);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Barcode settings saved successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to save settings'], 500);
        }
    }
    
    public function testEfris() {
        AuthMiddleware::requireRole(['admin']);
        
        $result = $this->efrisApi->testConnection();
        
        Response::json($result);
    }
    
    public function getDefault() {
        AuthMiddleware::requireRole(['admin']);
        
        $defaults = $this->settingModel->getDefaultSettings();
        
        Response::json([
            'success' => true,
            'data' => $defaults
        ]);
    }
    
    public function reset($group) {
        AuthMiddleware::requireRole(['admin']);
        
        $groups = ['efris', 'royalty', 'discount', 'barcode'];
        if (!in_array($group, $groups)) {
            Response::json(['success' => false, 'message' => 'Invalid settings group'], 400);
        }
        
        $defaults = $this->settingModel->getDefaultSettings();
        $groupDefaults = [];
        
        foreach ($defaults as $key => $value) {
            if (strpos($key, $group) === 0) {
                $groupDefaults[$key] = $value;
            }
        }
        
        $result = $this->settingModel->setMultiple($groupDefaults);
        
        if ($result) {
            Response::json(['success' => true, 'message' => "{$group} settings reset to default"]);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to reset settings'], 500);
        }
    }
}
?>