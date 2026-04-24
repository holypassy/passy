<?php

function get_setting($key, $default = null) {
    static $settings = null;
    
    if ($settings === null) {
        try {
            $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $conn->query("SELECT setting_key, setting_value, setting_type FROM system_settings");
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $settings = [];
            foreach ($results as $result) {
                $value = $result['setting_value'];
                switch ($result['setting_type']) {
                    case 'boolean':
                        $value = (bool)$value;
                        break;
                    case 'number':
                        $value = is_numeric($value) ? (float)$value : 0;
                        break;
                }
                $settings[$result['setting_key']] = $value;
            }
        } catch(PDOException $e) {
            return $default;
        }
    }
    
    return $settings[$key] ?? $default;
}

function update_setting($key, $value, $type = null) {
    try {
        $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        if (!$type) {
            if (is_bool($value)) $type = 'boolean';
            elseif (is_numeric($value)) $type = 'number';
            else $type = 'text';
        }
        
        if ($type === 'boolean') {
            $value = $value ? '1' : '0';
        }
        
        $stmt = $conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_at) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = ?, setting_type = ?, updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $type, $value, $type]);
        
        return true;
    } catch(PDOException $e) {
        return false;
    }
}