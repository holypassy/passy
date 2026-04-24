<?php
require_once __DIR__ . '/../config/database.php';

class Setting {
    private $conn;
    private $table = "settings";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAll() {
        $query = "SELECT setting_key, setting_value, setting_type, description FROM {$this->table}";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }
    
    public function get($key) {
        $query = "SELECT setting_value FROM {$this->table} WHERE setting_key = :key";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : null;
    }
    
    public function set($key, $value, $type = 'text', $description = null) {
        $query = "INSERT INTO {$this->table} (setting_key, setting_value, setting_type, description) 
                  VALUES (:key, :value, :type, :description) 
                  ON DUPLICATE KEY UPDATE setting_value = :value, setting_type = :type, description = :description";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':type' => $type,
            ':description' => $description
        ]);
    }
    
    public function setMultiple($settings) {
        $this->conn->beginTransaction();
        
        try {
            foreach ($settings as $key => $data) {
                $value = is_array($data) ? $data['value'] : $data;
                $type = is_array($data) ? ($data['type'] ?? 'text') : 'text';
                $description = is_array($data) ? ($data['description'] ?? null) : null;
                
                $this->set($key, $value, $type, $description);
            }
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }
    
    public function delete($key) {
        $query = "DELETE FROM {$this->table} WHERE setting_key = :key";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':key' => $key]);
    }
    
    public function getGrouped() {
        $all = $this->getAll();
        
        return [
            'efris' => [
                'efris_enabled' => $all['efris_enabled'] ?? '0',
                'efris_url' => $all['efris_url'] ?? 'https://efris.ura.go.ug/efris/ws/',
                'efris_device_no' => $all['efris_device_no'] ?? '',
                'efris_tin' => $all['efris_tin'] ?? '',
                'efris_api_key' => $all['efris_api_key'] ?? '',
                'efris_client_id' => $all['efris_client_id'] ?? '',
                'efris_client_secret' => $all['efris_client_secret'] ?? '',
                'efris_auto_sync' => $all['efris_auto_sync'] ?? '0',
                'efris_test_mode' => $all['efris_test_mode'] ?? '1'
            ],
            'royalty' => [
                'royalty_enabled' => $all['royalty_enabled'] ?? '1',
                'royalty_points_per_amount' => $all['royalty_points_per_amount'] ?? '1000',
                'royalty_amount_per_point' => $all['royalty_amount_per_point'] ?? '10',
                'royalty_expiry_days' => $all['royalty_expiry_days'] ?? '365',
                'royalty_min_redemption' => $all['royalty_min_redemption'] ?? '5000',
                'royalty_max_redemption' => $all['royalty_max_redemption'] ?? '100000',
                'royalty_birthday_bonus' => $all['royalty_birthday_bonus'] ?? '500',
                'royalty_new_member_bonus' => $all['royalty_new_member_bonus'] ?? '200',
                'customer_tier_bronze_min' => $all['customer_tier_bronze_min'] ?? '0',
                'customer_tier_silver_min' => $all['customer_tier_silver_min'] ?? '100000',
                'customer_tier_gold_min' => $all['customer_tier_gold_min'] ?? '500000',
                'customer_tier_platinum_min' => $all['customer_tier_platinum_min'] ?? '1000000'
            ],
            'discount' => [
                'discount_approval_required' => $all['discount_approval_required'] ?? '1',
                'discount_max_amount' => $all['discount_max_amount'] ?? '50000',
                'discount_max_percentage' => $all['discount_max_percentage'] ?? '20',
                'discount_auto_approve_role' => $all['discount_auto_approve_role'] ?? 'manager',
                'refund_days_limit' => $all['refund_days_limit'] ?? '30',
                'refund_requires_approval' => $all['refund_requires_approval'] ?? '1',
                'refund_restock' => $all['refund_restock'] ?? '1',
                'refund_method' => $all['refund_method'] ?? 'original',
                'bulk_discount_enabled' => $all['bulk_discount_enabled'] ?? '1',
                'bulk_discount_min_items' => $all['bulk_discount_min_items'] ?? '5',
                'bulk_discount_percentage' => $all['bulk_discount_percentage'] ?? '10'
            ],
            'barcode' => [
                'barcode_enabled' => $all['barcode_enabled'] ?? '1',
                'barcode_format' => $all['barcode_format'] ?? 'CODE128',
                'barcode_width' => $all['barcode_width'] ?? '2',
                'barcode_height' => $all['barcode_height'] ?? '50',
                'barcode_include_price' => $all['barcode_include_price'] ?? '1',
                'barcode_include_name' => $all['barcode_include_name'] ?? '0',
                'scanner_auto_enter' => $all['scanner_auto_enter'] ?? '1',
                'scanner_prefix' => $all['scanner_prefix'] ?? '',
                'scanner_suffix' => $all['scanner_suffix'] ?? '\n',
                'scanner_timeout' => $all['scanner_timeout'] ?? '100',
                'scanner_beep_on_scan' => $all['scanner_beep_on_scan'] ?? '1',
                'scanner_validation' => $all['scanner_validation'] ?? '1'
            ]
        ];
    }
    
    public function getDefaultSettings() {
        return [
            'efris_enabled' => '0',
            'efris_url' => 'https://efris.ura.go.ug/efris/ws/',
            'efris_device_no' => '',
            'efris_tin' => '',
            'efris_api_key' => '',
            'efris_client_id' => '',
            'efris_client_secret' => '',
            'efris_auto_sync' => '0',
            'efris_test_mode' => '1',
            'royalty_enabled' => '1',
            'royalty_points_per_amount' => '1000',
            'royalty_amount_per_point' => '10',
            'royalty_expiry_days' => '365',
            'royalty_min_redemption' => '5000',
            'royalty_max_redemption' => '100000',
            'royalty_birthday_bonus' => '500',
            'royalty_new_member_bonus' => '200',
            'customer_tier_bronze_min' => '0',
            'customer_tier_silver_min' => '100000',
            'customer_tier_gold_min' => '500000',
            'customer_tier_platinum_min' => '1000000',
            'discount_approval_required' => '1',
            'discount_max_amount' => '50000',
            'discount_max_percentage' => '20',
            'discount_auto_approve_role' => 'manager',
            'refund_days_limit' => '30',
            'refund_requires_approval' => '1',
            'refund_restock' => '1',
            'refund_method' => 'original',
            'bulk_discount_enabled' => '1',
            'bulk_discount_min_items' => '5',
            'bulk_discount_percentage' => '10',
            'barcode_enabled' => '1',
            'barcode_format' => 'CODE128',
            'barcode_width' => '2',
            'barcode_height' => '50',
            'barcode_include_price' => '1',
            'barcode_include_name' => '0',
            'scanner_auto_enter' => '1',
            'scanner_prefix' => '',
            'scanner_suffix' => '\n',
            'scanner_timeout' => '100',
            'scanner_beep_on_scan' => '1',
            'scanner_validation' => '1'
        ];
    }
}
?>