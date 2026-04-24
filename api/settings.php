<?php
header('Content-Type: application/json');
session_start();

// Only admins can access settings
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Helper: get all settings as nested array
function getAllSettings($conn) {
    $stmt = $conn->query("SELECT section, setting_key, setting_value FROM system_settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['section']][$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

// Helper: update a single setting
function updateSetting($conn, $section, $key, $value) {
    $stmt = $conn->prepare("
        INSERT INTO system_settings (section, setting_key, setting_value) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    return $stmt->execute([$section, $key, $value]);
}

// Helper: update multiple settings for a section
function updateSectionSettings($conn, $section, $data) {
    $success = true;
    foreach ($data as $key => $value) {
        // Convert boolean/checkbox to '1'/'0' if needed
        if (is_bool($value)) $value = $value ? '1' : '0';
        if (!updateSetting($conn, $section, $key, (string)$value)) {
            $success = false;
        }
    }
    return $success;
}

// Helper: get default settings for a section
function getDefaults($section) {
    $defaults = [
        'efris' => [
            'efris_enabled' => '0',
            'efris_test_mode' => '1',
            'efris_auto_sync' => '0',
            'efris_url' => 'https://efris.ura.go.ug/api/v1',
            'efris_device_no' => '',
            'efris_tin' => '',
            'efris_api_key' => '',
            'efris_client_id' => '',
            'efris_client_secret' => ''
        ],
        'royalty' => [
            'royalty_enabled' => '1',
            'royalty_points_per_amount' => '1000',
            'royalty_amount_per_point' => '100',
            'royalty_expiry_days' => '365',
            'royalty_min_redemption' => '100',
            'royalty_max_redemption' => '10000',
            'royalty_birthday_bonus' => '500',
            'royalty_new_member_bonus' => '200',
            'customer_tier_bronze_min' => '0',
            'customer_tier_silver_min' => '1000',
            'customer_tier_gold_min' => '5000',
            'customer_tier_platinum_min' => '20000'
        ],
        'discount' => [
            'discount_approval_required' => '1',
            'discount_max_amount' => '500000',
            'discount_max_percentage' => '20',
            'discount_auto_approve_role' => 'admin',
            'refund_days_limit' => '7',
            'refund_requires_approval' => '1',
            'refund_restock' => '1',
            'refund_method' => 'original',
            'bulk_discount_enabled' => '0',
            'bulk_discount_min_items' => '5',
            'bulk_discount_percentage' => '10'
        ],
        'barcode' => [
            'barcode_enabled' => '1',
            'barcode_format' => 'CODE128',
            'barcode_width' => '25',
            'barcode_height' => '10',
            'barcode_include_price' => '0',
            'barcode_include_name' => '0',
            'scanner_auto_enter' => '1',
            'scanner_beep_on_scan' => '1',
            'scanner_prefix' => '',
            'scanner_suffix' => '',
            'scanner_timeout' => '500'
        ]
    ];
    return $defaults[$section] ?? [];
}

// Get current settings, merging with defaults for missing keys
function getMergedSettings($conn, $section) {
    $all = getAllSettings($conn);
    $existing = $all[$section] ?? [];
    $defaults = getDefaults($section);
    return array_merge($defaults, $existing);
}

// --- Route based on action ---
$action = isset($_GET['action']) ? $_GET['action'] : '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {
    case 'all':
        // Return all settings (merged with defaults so frontend always has values)
        $sections = ['efris', 'royalty', 'discount', 'barcode'];
        $result = [];
        foreach ($sections as $section) {
            $result[$section] = getMergedSettings($conn, $section);
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'update-efris':
        $success = updateSectionSettings($conn, 'efris', $input);
        echo json_encode(['success' => $success, 'message' => $success ? 'EFRIS settings saved' : 'Error saving settings']);
        break;

    case 'update-royalty':
        $success = updateSectionSettings($conn, 'royalty', $input);
        echo json_encode(['success' => $success, 'message' => $success ? 'Royalty settings saved' : 'Error saving settings']);
        break;

    case 'update-discount':
        $success = updateSectionSettings($conn, 'discount', $input);
        echo json_encode(['success' => $success, 'message' => $success ? 'Discount settings saved' : 'Error saving settings']);
        break;

    case 'update-barcode':
        $success = updateSectionSettings($conn, 'barcode', $input);
        echo json_encode(['success' => $success, 'message' => $success ? 'Barcode settings saved' : 'Error saving settings']);
        break;

    case 'test-efris':
        // Retrieve current EFRIS settings
        $efris = getMergedSettings($conn, 'efris');
        if (!$efris['efris_enabled']) {
            echo json_encode(['success' => false, 'message' => 'EFRIS is disabled']);
            break;
        }
        // Simulate a connection test (replace with actual API call)
        $url = $efris['efris_url'] . '/test';
        $tin = $efris['efris_tin'];
        $apiKey = $efris['efris_api_key'];
        // If no credentials, fail
        if (empty($tin) || empty($apiKey)) {
            echo json_encode(['success' => false, 'message' => 'Missing TIN or API Key']);
            break;
        }
        // Perform a simple GET request (adjust as needed)
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode == 200) {
            echo json_encode(['success' => true, 'message' => 'EFRIS connection successful']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Connection failed (HTTP ' . $httpCode . ')']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}