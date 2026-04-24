<?php
// views/settings/index.php - Complete Settings Page with Business/Markup (Cost Price Based)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard_erp.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create settings table if not exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type ENUM('text', 'number', 'boolean', 'color', 'json') DEFAULT 'text',
            setting_group VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // Insert default settings if empty
    $checkStmt = $conn->query("SELECT COUNT(*) as count FROM system_settings");
    $count = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($count['count'] == 0) {
        $defaultSettings = [
            // BUSINESS & MARKUP SETTINGS (Cost Price Based)
            ['markup_enabled', '1', 'boolean', 'business'],
            ['markup_type', 'percentage', 'text', 'business'],
            ['markup_value', '30', 'number', 'business'],
            ['min_selling_price', '0', 'number', 'business'],
            ['max_markup_percentage', '200', 'number', 'business'],
            ['round_to_nearest', '1000', 'number', 'business'],
            
            // Category-Specific Markups (stored as JSON)
            ['category_markups', '{}', 'json', 'business'],
            
            // EFRIS Settings
            ['efris_enabled', '0', 'boolean', 'efris'],
            ['efris_test_mode', '1', 'boolean', 'efris'],
            ['efris_auto_sync', '0', 'boolean', 'efris'],
            ['efris_url', 'https://efris.ura.go.ug/api/v1', 'text', 'efris'],
            ['efris_device_no', '', 'text', 'efris'],
            ['efris_tin', '', 'text', 'efris'],
            ['efris_api_key', '', 'text', 'efris'],
            ['efris_client_id', '', 'text', 'efris'],
            ['efris_client_secret', '', 'text', 'efris'],
            
            // Royalty Settings
            ['royalty_enabled', '1', 'boolean', 'royalty'],
            ['royalty_points_per_amount', '1000', 'number', 'royalty'],
            ['royalty_amount_per_point', '500', 'number', 'royalty'],
            ['royalty_expiry_days', '365', 'number', 'royalty'],
            ['royalty_min_redemption', '100', 'number', 'royalty'],
            ['royalty_max_redemption', '10000', 'number', 'royalty'],
            ['royalty_birthday_bonus', '500', 'number', 'royalty'],
            ['royalty_new_member_bonus', '500', 'number', 'royalty'],
            ['customer_tier_bronze_min', '0', 'number', 'royalty'],
            ['customer_tier_silver_min', '1000000', 'number', 'royalty'],
            ['customer_tier_gold_min', '5000000', 'number', 'royalty'],
            ['customer_tier_platinum_min', '10000000', 'number', 'royalty'],
            
            // Discount Settings
            ['discount_approval_required', '1', 'boolean', 'discount'],
            ['discount_max_amount', '500000', 'number', 'discount'],
            ['discount_max_percentage', '20', 'number', 'discount'],
            ['discount_auto_approve_role', 'admin', 'text', 'discount'],
            ['refund_days_limit', '30', 'number', 'discount'],
            ['refund_requires_approval', '1', 'boolean', 'discount'],
            ['refund_restock', '1', 'boolean', 'discount'],
            ['refund_method', 'original', 'text', 'discount'],
            ['bulk_discount_enabled', '0', 'boolean', 'discount'],
            ['bulk_discount_min_items', '5', 'number', 'discount'],
            ['bulk_discount_percentage', '10', 'number', 'discount'],
            
            // Barcode Settings
            ['barcode_enabled', '1', 'boolean', 'barcode'],
            ['barcode_format', 'CODE128', 'text', 'barcode'],
            ['barcode_width', '50', 'number', 'barcode'],
            ['barcode_height', '30', 'number', 'barcode'],
            ['barcode_include_price', '0', 'boolean', 'barcode'],
            ['barcode_include_name', '0', 'boolean', 'barcode'],
            ['scanner_auto_enter', '1', 'boolean', 'barcode'],
            ['scanner_beep_on_scan', '1', 'boolean', 'barcode'],
            ['scanner_prefix', '', 'text', 'barcode'],
            ['scanner_suffix', '', 'text', 'barcode'],
            ['scanner_timeout', '500', 'number', 'barcode'],
            
            // Appearance Settings
            ['header_bg_color', '#1e3c72', 'color', 'appearance'],
            ['header_text_color', '#ffffff', 'color', 'appearance'],
            ['header_gradient_start', '#1e3c72', 'color', 'appearance'],
            ['header_gradient_end', '#2a5298', 'color', 'appearance'],
            ['header_use_gradient', '1', 'boolean', 'appearance'],
            ['header_show_logo', '1', 'boolean', 'appearance'],
            ['print_font', 'Times New Roman', 'text', 'appearance'],
            ['print_font_size', '10', 'number', 'appearance'],
            ['print_line_height', '1.2', 'number', 'appearance'],
            ['print_margin_top', '10', 'number', 'appearance'],
            ['print_margin_right', '10', 'number', 'appearance'],
            ['print_margin_bottom', '10', 'number', 'appearance'],
            ['print_margin_left', '10', 'number', 'appearance'],
            ['company_logo_url', '', 'text', 'appearance'],
            ['company_footer_text', 'Savant Motors - Quality Service You Can Trust', 'text', 'appearance'],
            ['print_watermark', '0', 'boolean', 'appearance'],
            ['watermark_text', 'SAVANT MOTORS', 'text', 'appearance'],
            
            // VOICE ANNOUNCEMENT SETTINGS (enhanced)
            ['voice_enabled', '1', 'boolean', 'voice'],
            ['voice_rate', '0.9', 'number', 'voice'],
            ['voice_pitch', '1.1', 'number', 'voice'],
            ['voice_volume', '1', 'number', 'voice'],
            ['voice_announce_overdue_tools', '1', 'boolean', 'voice'],
            ['voice_announce_low_stock', '1', 'boolean', 'voice'],
            ['voice_announce_new_jobs', '1', 'boolean', 'voice'],
            ['voice_announce_pending_requests', '1', 'boolean', 'voice'],
            ['voice_announce_unpaid_invoices', '1', 'boolean', 'voice'],
            ['voice_announce_overdue_jobs', '1', 'boolean', 'voice'],
            ['voice_announce_reminders', '1', 'boolean', 'voice'],
            ['voice_interval', '60', 'number', 'voice'],
            ['voice_custom_message', 'Welcome to Savant Motors ERP System. Please check the dashboard for updates.', 'text', 'voice'],
            ['voice_greeting', 'Good morning! Welcome to Savant Motors.', 'text', 'voice'],
            ['voice_language', 'en-US', 'text', 'voice'],
            ['voice_voice_name', 'Google UK English Female', 'text', 'voice'],
            // NEW: Schedule and custom announcements
            ['voice_announcement_start_time', '08:00', 'text', 'voice'],
            ['voice_announcement_end_time', '22:00', 'text', 'voice'],
            ['voice_custom_announcements', "Special offer: 10% off on all batteries!\nDon't forget to check tool returns.\nWelcome our new customers!", 'text', 'voice']
        ];
        
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, setting_group) VALUES (?, ?, ?, ?)");
        foreach ($defaultSettings as $setting) {
            $stmt->execute([$setting[0], $setting[1], $setting[2], $setting[3]]);
        }
    }
    
    // Get categories for markup settings
    $categories = [];
    try {
        $catStmt = $conn->query("SELECT id, name FROM categories ORDER BY name");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $categories = [];
    }
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $section = $_POST['section'] ?? '';
        $response = ['success' => false, 'message' => 'Invalid request'];
        
        if ($section === 'business') {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$_POST['markup_enabled'] ?? '0', 'markup_enabled']);
            $stmt->execute([$_POST['markup_type'] ?? 'percentage', 'markup_type']);
            $stmt->execute([$_POST['markup_value'] ?? '30', 'markup_value']);
            $stmt->execute([$_POST['min_selling_price'] ?? '0', 'min_selling_price']);
            $stmt->execute([$_POST['max_markup_percentage'] ?? '200', 'max_markup_percentage']);
            $stmt->execute([$_POST['round_to_nearest'] ?? '1000', 'round_to_nearest']);
            
            // Handle category markups
            $categoryMarkups = [];
            if (isset($_POST['cat_markup_type']) && is_array($_POST['cat_markup_type'])) {
                foreach ($_POST['cat_markup_type'] as $catId => $type) {
                    if (isset($_POST['cat_markup_value'][$catId]) && !empty($_POST['cat_markup_value'][$catId])) {
                        $categoryMarkups[$catId] = [
                            'type' => $type,
                            'value' => (float)$_POST['cat_markup_value'][$catId]
                        ];
                    }
                }
            }
            $stmt->execute([json_encode($categoryMarkups), 'category_markups']);
            
            $response = ['success' => true, 'message' => 'Business & Markup settings saved successfully'];
        }
        elseif ($section === 'efris') {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$_POST['efris_enabled'] ?? '0', 'efris_enabled']);
            $stmt->execute([$_POST['efris_test_mode'] ?? '0', 'efris_test_mode']);
            $stmt->execute([$_POST['efris_auto_sync'] ?? '0', 'efris_auto_sync']);
            $stmt->execute([$_POST['efris_url'] ?? '', 'efris_url']);
            $stmt->execute([$_POST['efris_device_no'] ?? '', 'efris_device_no']);
            $stmt->execute([$_POST['efris_tin'] ?? '', 'efris_tin']);
            $stmt->execute([$_POST['efris_api_key'] ?? '', 'efris_api_key']);
            $stmt->execute([$_POST['efris_client_id'] ?? '', 'efris_client_id']);
            $stmt->execute([$_POST['efris_client_secret'] ?? '', 'efris_client_secret']);
            $response = ['success' => true, 'message' => 'EFRIS settings saved successfully'];
        }
        elseif ($section === 'royalty') {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$_POST['royalty_enabled'] ?? '0', 'royalty_enabled']);
            $stmt->execute([$_POST['royalty_points_per_amount'] ?? '1000', 'royalty_points_per_amount']);
            $stmt->execute([$_POST['royalty_amount_per_point'] ?? '500', 'royalty_amount_per_point']);
            $stmt->execute([$_POST['royalty_expiry_days'] ?? '365', 'royalty_expiry_days']);
            $stmt->execute([$_POST['royalty_min_redemption'] ?? '100', 'royalty_min_redemption']);
            $stmt->execute([$_POST['royalty_max_redemption'] ?? '10000', 'royalty_max_redemption']);
            $stmt->execute([$_POST['royalty_birthday_bonus'] ?? '500', 'royalty_birthday_bonus']);
            $stmt->execute([$_POST['royalty_new_member_bonus'] ?? '500', 'royalty_new_member_bonus']);
            $stmt->execute([$_POST['customer_tier_bronze_min'] ?? '0', 'customer_tier_bronze_min']);
            $stmt->execute([$_POST['customer_tier_silver_min'] ?? '1000000', 'customer_tier_silver_min']);
            $stmt->execute([$_POST['customer_tier_gold_min'] ?? '5000000', 'customer_tier_gold_min']);
            $stmt->execute([$_POST['customer_tier_platinum_min'] ?? '10000000', 'customer_tier_platinum_min']);
            $response = ['success' => true, 'message' => 'Royalty settings saved successfully'];
        }
        elseif ($section === 'discount') {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$_POST['discount_approval_required'] ?? '0', 'discount_approval_required']);
            $stmt->execute([$_POST['discount_max_amount'] ?? '500000', 'discount_max_amount']);
            $stmt->execute([$_POST['discount_max_percentage'] ?? '20', 'discount_max_percentage']);
            $stmt->execute([$_POST['discount_auto_approve_role'] ?? 'admin', 'discount_auto_approve_role']);
            $stmt->execute([$_POST['refund_days_limit'] ?? '30', 'refund_days_limit']);
            $stmt->execute([$_POST['refund_requires_approval'] ?? '0', 'refund_requires_approval']);
            $stmt->execute([$_POST['refund_restock'] ?? '0', 'refund_restock']);
            $stmt->execute([$_POST['refund_method'] ?? 'original', 'refund_method']);
            $stmt->execute([$_POST['bulk_discount_enabled'] ?? '0', 'bulk_discount_enabled']);
            $stmt->execute([$_POST['bulk_discount_min_items'] ?? '5', 'bulk_discount_min_items']);
            $stmt->execute([$_POST['bulk_discount_percentage'] ?? '10', 'bulk_discount_percentage']);
            $response = ['success' => true, 'message' => 'Discount settings saved successfully'];
        }
        elseif ($section === 'barcode') {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$_POST['barcode_enabled'] ?? '0', 'barcode_enabled']);
            $stmt->execute([$_POST['barcode_format'] ?? 'CODE128', 'barcode_format']);
            $stmt->execute([$_POST['barcode_width'] ?? '50', 'barcode_width']);
            $stmt->execute([$_POST['barcode_height'] ?? '30', 'barcode_height']);
            $stmt->execute([$_POST['barcode_include_price'] ?? '0', 'barcode_include_price']);
            $stmt->execute([$_POST['barcode_include_name'] ?? '0', 'barcode_include_name']);
            $stmt->execute([$_POST['scanner_auto_enter'] ?? '0', 'scanner_auto_enter']);
            $stmt->execute([$_POST['scanner_beep_on_scan'] ?? '0', 'scanner_beep_on_scan']);
            $stmt->execute([$_POST['scanner_prefix'] ?? '', 'scanner_prefix']);
            $stmt->execute([$_POST['scanner_suffix'] ?? '', 'scanner_suffix']);
            $stmt->execute([$_POST['scanner_timeout'] ?? '500', 'scanner_timeout']);
            $response = ['success' => true, 'message' => 'Barcode settings saved successfully'];
        }
        elseif ($section === 'appearance') {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$_POST['header_bg_color'] ?? '#1e3c72', 'header_bg_color']);
            $stmt->execute([$_POST['header_text_color'] ?? '#ffffff', 'header_text_color']);
            $stmt->execute([$_POST['header_gradient_start'] ?? '#1e3c72', 'header_gradient_start']);
            $stmt->execute([$_POST['header_gradient_end'] ?? '#2a5298', 'header_gradient_end']);
            $stmt->execute([$_POST['header_use_gradient'] ?? '0', 'header_use_gradient']);
            $stmt->execute([$_POST['header_show_logo'] ?? '0', 'header_show_logo']);
            $stmt->execute([$_POST['print_font'] ?? 'Times New Roman', 'print_font']);
            $stmt->execute([$_POST['print_font_size'] ?? '10', 'print_font_size']);
            $stmt->execute([$_POST['print_line_height'] ?? '1.2', 'print_line_height']);
            $stmt->execute([$_POST['print_margin_top'] ?? '10', 'print_margin_top']);
            $stmt->execute([$_POST['print_margin_right'] ?? '10', 'print_margin_right']);
            $stmt->execute([$_POST['print_margin_bottom'] ?? '10', 'print_margin_bottom']);
            $stmt->execute([$_POST['print_margin_left'] ?? '10', 'print_margin_left']);
            $stmt->execute([$_POST['company_logo_url'] ?? '', 'company_logo_url']);
            $stmt->execute([$_POST['company_footer_text'] ?? 'Savant Motors - Quality Service You Can Trust', 'company_footer_text']);
            $stmt->execute([$_POST['print_watermark'] ?? '0', 'print_watermark']);
            $stmt->execute([$_POST['watermark_text'] ?? 'SAVANT MOTORS', 'watermark_text']);
            $response = ['success' => true, 'message' => 'Appearance settings saved successfully'];
        }
        elseif ($section === 'voice') {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$_POST['voice_enabled'] ?? '0', 'voice_enabled']);
            $stmt->execute([$_POST['voice_rate'] ?? '0.9', 'voice_rate']);
            $stmt->execute([$_POST['voice_pitch'] ?? '1.1', 'voice_pitch']);
            $stmt->execute([$_POST['voice_volume'] ?? '1', 'voice_volume']);
            $stmt->execute([$_POST['voice_announce_overdue_tools'] ?? '0', 'voice_announce_overdue_tools']);
            $stmt->execute([$_POST['voice_announce_low_stock'] ?? '0', 'voice_announce_low_stock']);
            $stmt->execute([$_POST['voice_announce_new_jobs'] ?? '0', 'voice_announce_new_jobs']);
            $stmt->execute([$_POST['voice_announce_pending_requests'] ?? '0', 'voice_announce_pending_requests']);
            $stmt->execute([$_POST['voice_announce_unpaid_invoices'] ?? '0', 'voice_announce_unpaid_invoices']);
            $stmt->execute([$_POST['voice_announce_overdue_jobs'] ?? '0', 'voice_announce_overdue_jobs']);
            $stmt->execute([$_POST['voice_announce_reminders'] ?? '0', 'voice_announce_reminders']);
            $stmt->execute([$_POST['voice_interval'] ?? '60', 'voice_interval']);
            $stmt->execute([$_POST['voice_custom_message'] ?? '', 'voice_custom_message']);
            $stmt->execute([$_POST['voice_greeting'] ?? '', 'voice_greeting']);
            $stmt->execute([$_POST['voice_language'] ?? 'en-US', 'voice_language']);
            $stmt->execute([$_POST['voice_voice_name'] ?? 'Google UK English Female', 'voice_voice_name']);
            // New schedule and custom announcements
            $stmt->execute([$_POST['voice_announcement_start_time'] ?? '08:00', 'voice_announcement_start_time']);
            $stmt->execute([$_POST['voice_announcement_end_time'] ?? '22:00', 'voice_announcement_end_time']);
            $stmt->execute([$_POST['voice_custom_announcements'] ?? '', 'voice_custom_announcements']);
            $response = ['success' => true, 'message' => 'Voice announcement settings saved successfully'];
        }
        
        $_SESSION['settings_message'] = $response['message'];
        $_SESSION['settings_success'] = $response['success'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Get all settings
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value, setting_type, setting_group FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $value = $row['setting_value'];
        if ($row['setting_type'] === 'boolean') {
            $value = $value === '1';
        } elseif ($row['setting_type'] === 'number') {
            $value = (float)$value;
        } elseif ($row['setting_type'] === 'json') {
            $value = json_decode($value, true);
        }
        $settings[$row['setting_group']][$row['setting_key']] = $value;
    }
    
    // Set defaults for missing groups
    $groups = ['business', 'efris', 'royalty', 'discount', 'barcode', 'appearance', 'voice'];
    foreach ($groups as $group) {
        if (!isset($settings[$group])) {
            $settings[$group] = [];
        }
    }
    
    // Decode JSON settings
    if (isset($settings['business']['category_markups']) && is_string($settings['business']['category_markups'])) {
        $settings['business']['category_markups'] = json_decode($settings['business']['category_markups'], true);
    }
    
    $success_message = $_SESSION['settings_message'] ?? null;
    $success_status = $_SESSION['settings_success'] ?? false;
    unset($_SESSION['settings_message'], $_SESSION['settings_success']);
    
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --bg-light: #f8fafc;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0c4a6e;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
        .menu-item {
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #0c4a6e;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(14, 165, 233, 0.2); color: #0284c7; border-left-color: #0284c7; }

        /* Main Content */
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }

        /* Settings Tabs */
        .settings-tabs {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            border-bottom: 2px solid var(--border);
            padding-bottom: 0.5rem;
        }
        .tab {
            padding: 0.6rem 1.2rem;
            background: white;
            border: 1px solid var(--border);
            border-radius: 2rem;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--gray);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tab:hover { border-color: var(--primary-light); color: var(--primary-light); }
        .tab.active { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; border-color: transparent; }

        /* Settings Panels */
        .settings-panel {
            display: none;
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
            animation: fadeIn 0.3s ease;
        }
        .settings-panel.active { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .panel-header {
            padding: 1rem 1.5rem;
            background: var(--bg-light);
            border-bottom: 1px solid var(--border);
        }
        .panel-header h2 { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .panel-body { padding: 1.5rem; }

        /* Settings Sections */
        .settings-section {
            background: var(--bg-light);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
        }
        .section-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Forms */
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
        }

        /* Checkbox Group */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .checkbox-group label {
            font-weight: 500;
            cursor: pointer;
            font-size: 0.85rem;
        }

        /* Category Markup Table */
        .category-markup-table {
            width: 100%;
            border-collapse: collapse;
        }
        .category-markup-table th,
        .category-markup-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .category-markup-table th {
            background: #f1f5f9;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .markup-input {
            width: 100px;
            padding: 0.4rem;
            border: 1px solid var(--border);
            border-radius: 0.4rem;
        }
        .markup-type-select {
            width: 80px;
            padding: 0.4rem;
            border: 1px solid var(--border);
            border-radius: 0.4rem;
        }

        /* Price Calculator */
        .price-calculator {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border-radius: 1rem;
            padding: 1.25rem;
            color: white;
        }
        .calculator-result {
            background: rgba(255,255,255,0.2);
            border-radius: 0.75rem;
            padding: 1rem;
            margin-top: 1rem;
            text-align: center;
        }
        .calculator-result .price {
            font-size: 1.8rem;
            font-weight: 800;
        }

        /* Color Picker */
        .color-input-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .color-input-group input[type="color"] {
            width: 50px;
            height: 40px;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            cursor: pointer;
        }

        /* Font Preview */
        .font-preview {
            margin-top: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 0.5rem;
            border: 1px solid var(--border);
            text-align: center;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.7rem; }
        .btn-save {
            background: var(--success);
            color: white;
            padding: 0.6rem 1.5rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }

        .help-text {
            font-size: 0.65rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }
        .text-muted { color: var(--gray); }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>⚙️ SAVANT MOTORS</h2>
            <p>System Settings</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="../users/index.php" class="menu-item">👥 Users</a>
            <a href="../technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="index.php" class="menu-item active">⚙️ Settings</a>
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-cog" style="color: var(--primary-light);"></i> System Settings</h1>
                <p>Configure system preferences, pricing rules, integrations, and appearance</p>
            </div>
            <a href="../dashboard_erp.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-<?php echo $success_status ? 'success' : 'error'; ?>">
            <i class="fas fa-<?php echo $success_status ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- Settings Tabs -->
        <div class="settings-tabs">
            <div class="tab active" data-tab="business">
                <i class="fas fa-chart-line"></i> Business & Markup
            </div>
            <div class="tab" data-tab="efris">
                <i class="fas fa-cloud"></i> EFRIS
            </div>
            <div class="tab" data-tab="royalty">
                <i class="fas fa-crown"></i> Royalty
            </div>
            <div class="tab" data-tab="discount">
                <i class="fas fa-tags"></i> Discount
            </div>
            <div class="tab" data-tab="barcode">
                <i class="fas fa-qrcode"></i> Barcode
            </div>
            <div class="tab" data-tab="appearance">
                <i class="fas fa-palette"></i> Appearance
            </div>
            <div class="tab" data-tab="voice">
                <i class="fas fa-volume-up"></i> Voice
            </div>
        </div>

        <!-- BUSINESS & MARKUP PANEL (Cost Price Based) -->
        <div id="business-panel" class="settings-panel active">
            <div class="panel-header">
                <h2><i class="fas fa-chart-line"></i> Business & Pricing Rules</h2>
            </div>
            <div class="panel-body">
                <form method="POST" id="businessForm">
                    <input type="hidden" name="section" value="business">
                    
                    <!-- Global Markup Settings -->
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-globe"></i> Global Markup Settings</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="markup_enabled" id="markup_enabled" value="1" <?php echo ($settings['business']['markup_enabled'] ?? true) ? 'checked' : ''; ?>>
                            <label for="markup_enabled">Enable automatic markup calculation from Cost Price</label>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Markup Type</label>
                                <select class="form-control" name="markup_type" id="markup_type">
                                    <option value="percentage" <?php echo ($settings['business']['markup_type'] ?? 'percentage') == 'percentage' ? 'selected' : ''; ?>>Percentage (%)</option>
                                    <option value="fixed" <?php echo ($settings['business']['markup_type'] ?? '') == 'fixed' ? 'selected' : ''; ?>>Fixed Amount (UGX)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Markup Value</label>
                                <input type="number" class="form-control" name="markup_value" id="markup_value" value="<?php echo $settings['business']['markup_value'] ?? '30'; ?>" step="any">
                                <div class="help-text">For percentage: e.g., 30 = 30% markup on cost. For fixed: amount in UGX added to cost.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Minimum Selling Price (UGX)</label>
                                <input type="number" class="form-control" name="min_selling_price" value="<?php echo $settings['business']['min_selling_price'] ?? '0'; ?>">
                                <div class="help-text">Minimum allowed selling price (0 = no minimum)</div>
                            </div>
                            <div class="form-group">
                                <label>Maximum Markup Percentage (%)</label>
                                <input type="number" class="form-control" name="max_markup_percentage" value="<?php echo $settings['business']['max_markup_percentage'] ?? '200'; ?>">
                                <div class="help-text">Maximum allowed markup percentage (prevents excessive pricing)</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Round Selling Price to Nearest (UGX)</label>
                            <select class="form-control" name="round_to_nearest">
                                <option value="0" <?php echo ($settings['business']['round_to_nearest'] ?? '1000') == '0' ? 'selected' : ''; ?>>Don't round</option>
                                <option value="100" <?php echo ($settings['business']['round_to_nearest'] ?? '') == '100' ? 'selected' : ''; ?>>100 UGX</option>
                                <option value="500" <?php echo ($settings['business']['round_to_nearest'] ?? '') == '500' ? 'selected' : ''; ?>>500 UGX</option>
                                <option value="1000" <?php echo ($settings['business']['round_to_nearest'] ?? '1000') == '1000' ? 'selected' : ''; ?>>1,000 UGX</option>
                                <option value="5000" <?php echo ($settings['business']['round_to_nearest'] ?? '') == '5000' ? 'selected' : ''; ?>>5,000 UGX</option>
                                <option value="10000" <?php echo ($settings['business']['round_to_nearest'] ?? '') == '10000' ? 'selected' : ''; ?>>10,000 UGX</option>
                            </select>
                            <div class="help-text">Round calculated selling price to the nearest specified amount</div>
                        </div>
                    </div>

                    <!-- Category-Specific Markups -->
                    <?php if (!empty($categories)): ?>
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-tags"></i> Category-Specific Markups</div>
                        <div class="help-text" style="margin-bottom: 1rem;">Set different markups for different product categories. Category-specific markups override global settings.</div>
                        
                        <table class="category-markup-table">
                            <thead>
                                <tr><th>Category</th><th>Markup Type</th><th>Markup Value</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $categoryMarkups = $settings['business']['category_markups'] ?? [];
                                foreach ($categories as $category): 
                                    $catId = $category['id'];
                                    $markup = $categoryMarkups[$catId] ?? null;
                                ?>
                                <tr data-category="<?php echo $catId; ?>">
                                    <td><?php echo htmlspecialchars($category['name']); ?></td>
                                    <td>
                                        <select name="cat_markup_type[<?php echo $catId; ?>]" class="markup-type-select">
                                            <option value="percentage" <?php echo ($markup && $markup['type'] == 'percentage') ? 'selected' : ''; ?>>%</option>
                                            <option value="fixed" <?php echo ($markup && $markup['type'] == 'fixed') ? 'selected' : ''; ?>>Fixed</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="cat_markup_value[<?php echo $catId; ?>]" value="<?php echo htmlspecialchars($markup['value'] ?? ''); ?>" class="markup-input" step="any" placeholder="Use global">
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="clearCategoryMarkup(this)"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- Formula Explanation -->
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-calculator"></i> Pricing Formula</div>
                        <div style="background: white; padding: 1rem; border-radius: 0.5rem;">
                            <p><strong>Selling Price = Cost Price + Markup</strong></p>
                            <ul style="margin-top: 0.5rem; margin-left: 1.5rem; font-size: 0.8rem; color: var(--gray);">
                                <li>If <strong>Percentage</strong> markup: Markup = Cost Price × (Markup Value ÷ 100)</li>
                                <li>If <strong>Fixed</strong> markup: Markup = Markup Value (UGX)</li>
                                <li>Final price is then rounded to the nearest selected amount</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Price Calculator Preview -->
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-calculator"></i> Price Calculator Preview</div>
                        <div class="price-calculator">
                            <div class="form-group">
                                <label style="color: rgba(255,255,255,0.8);">Cost Price (UGX)</label>
                                <input type="number" class="form-control" id="calc_cost_price" placeholder="Enter cost price" oninput="calculateSellingPrice()" style="background: rgba(255,255,255,0.9);">
                            </div>
                            <div class="calculator-result">
                                <h4><i class="fas fa-tag"></i> Calculated Selling Price</h4>
                                <div class="price" id="calc_result">UGX 0</div>
                                <div class="help-text" style="color: rgba(255,255,255,0.7);" id="calc_breakdown"></div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Business Settings</button>
                </form>
            </div>
        </div>

        <!-- EFRIS Panel -->
        <div id="efris-panel" class="settings-panel">
            <div class="panel-header">
                <h2><i class="fas fa-cloud"></i> EFRIS Configuration</h2>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="section" value="efris">
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-power-off"></i> General Settings</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="efris_enabled" id="efris_enabled" value="1" <?php echo ($settings['efris']['efris_enabled'] ?? false) ? 'checked' : ''; ?>>
                            <label for="efris_enabled">Enable EFRIS Integration</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="efris_test_mode" id="efris_test_mode" value="1" <?php echo ($settings['efris']['efris_test_mode'] ?? false) ? 'checked' : ''; ?>>
                            <label for="efris_test_mode">Test Mode (Sandbox Environment)</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="efris_auto_sync" id="efris_auto_sync" value="1" <?php echo ($settings['efris']['efris_auto_sync'] ?? false) ? 'checked' : ''; ?>>
                            <label for="efris_auto_sync">Auto-sync invoices with EFRIS</label>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-server"></i> API Configuration</div>
                        <div class="form-group">
                            <label>EFRIS API URL</label>
                            <input type="url" class="form-control" name="efris_url" value="<?php echo htmlspecialchars($settings['efris']['efris_url'] ?? 'https://efris.ura.go.ug/api/v1'); ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Device Number</label>
                                <input type="text" class="form-control" name="efris_device_no" value="<?php echo htmlspecialchars($settings['efris']['efris_device_no'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>TIN (Tax Identification Number)</label>
                                <input type="text" class="form-control" name="efris_tin" value="<?php echo htmlspecialchars($settings['efris']['efris_tin'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>API Key</label>
                                <input type="password" class="form-control" name="efris_api_key" value="<?php echo htmlspecialchars($settings['efris']['efris_api_key'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Client ID</label>
                                <input type="text" class="form-control" name="efris_client_id" value="<?php echo htmlspecialchars($settings['efris']['efris_client_id'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Client Secret</label>
                            <input type="password" class="form-control" name="efris_client_secret" value="<?php echo htmlspecialchars($settings['efris']['efris_client_secret'] ?? ''); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save EFRIS Settings</button>
                </form>
            </div>
        </div>

        <!-- Royalty Panel -->
        <div id="royalty-panel" class="settings-panel">
            <div class="panel-header">
                <h2><i class="fas fa-crown"></i> Customer Royalty & Loyalty</h2>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="section" value="royalty">
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-gem"></i> Loyalty Points Settings</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="royalty_enabled" id="royalty_enabled" value="1" <?php echo ($settings['royalty']['royalty_enabled'] ?? false) ? 'checked' : ''; ?>>
                            <label for="royalty_enabled">Enable Customer Loyalty Points</label>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Points per Amount (UGX)</label>
                                <input type="number" class="form-control" name="royalty_points_per_amount" value="<?php echo $settings['royalty']['royalty_points_per_amount'] ?? '1000'; ?>">
                            </div>
                            <div class="form-group">
                                <label>Amount per Point (UGX)</label>
                                <input type="number" class="form-control" name="royalty_amount_per_point" value="<?php echo $settings['royalty']['royalty_amount_per_point'] ?? '500'; ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Points Expiry (Days)</label>
                                <input type="number" class="form-control" name="royalty_expiry_days" value="<?php echo $settings['royalty']['royalty_expiry_days'] ?? '365'; ?>">
                            </div>
                            <div class="form-group">
                                <label>Minimum Redemption (Points)</label>
                                <input type="number" class="form-control" name="royalty_min_redemption" value="<?php echo $settings['royalty']['royalty_min_redemption'] ?? '100'; ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Maximum Redemption (Points)</label>
                                <input type="number" class="form-control" name="royalty_max_redemption" value="<?php echo $settings['royalty']['royalty_max_redemption'] ?? '10000'; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-gift"></i> Bonus Points</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Birthday Bonus Points</label>
                                <input type="number" class="form-control" name="royalty_birthday_bonus" value="<?php echo $settings['royalty']['royalty_birthday_bonus'] ?? '500'; ?>">
                            </div>
                            <div class="form-group">
                                <label>New Member Bonus Points</label>
                                <input type="number" class="form-control" name="royalty_new_member_bonus" value="<?php echo $settings['royalty']['royalty_new_member_bonus'] ?? '500'; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-chart-line"></i> Customer Tiers (Minimum Spend UGX)</div>
                        <div class="tier-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
                            <div class="tier-card bronze">
                                <div class="tier-icon">🥉</div>
                                <div class="tier-name">Bronze</div>
                                <input type="number" name="customer_tier_bronze_min" class="form-control" value="<?php echo $settings['royalty']['customer_tier_bronze_min'] ?? '0'; ?>">
                            </div>
                            <div class="tier-card silver">
                                <div class="tier-icon">🥈</div>
                                <div class="tier-name">Silver</div>
                                <input type="number" name="customer_tier_silver_min" class="form-control" value="<?php echo $settings['royalty']['customer_tier_silver_min'] ?? '1000000'; ?>">
                            </div>
                            <div class="tier-card gold">
                                <div class="tier-icon">🥇</div>
                                <div class="tier-name">Gold</div>
                                <input type="number" name="customer_tier_gold_min" class="form-control" value="<?php echo $settings['royalty']['customer_tier_gold_min'] ?? '5000000'; ?>">
                            </div>
                            <div class="tier-card platinum">
                                <div class="tier-icon">💎</div>
                                <div class="tier-name">Platinum</div>
                                <input type="number" name="customer_tier_platinum_min" class="form-control" value="<?php echo $settings['royalty']['customer_tier_platinum_min'] ?? '10000000'; ?>">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Royalty Settings</button>
                </form>
            </div>
        </div>

        <!-- Discount Panel -->
        <div id="discount-panel" class="settings-panel">
            <div class="panel-header">
                <h2><i class="fas fa-tags"></i> Discount & Refund Settings</h2>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="section" value="discount">
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-percent"></i> Discount Rules</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="discount_approval_required" value="1" <?php echo ($settings['discount']['discount_approval_required'] ?? false) ? 'checked' : ''; ?>>
                            <label>Require approval for discounts</label>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Maximum Discount Amount (UGX)</label>
                                <input type="number" class="form-control" name="discount_max_amount" value="<?php echo $settings['discount']['discount_max_amount'] ?? '500000'; ?>">
                            </div>
                            <div class="form-group">
                                <label>Maximum Discount Percentage (%)</label>
                                <input type="number" class="form-control" name="discount_max_percentage" value="<?php echo $settings['discount']['discount_max_percentage'] ?? '20'; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Auto-approve for role</label>
                            <select class="form-control" name="discount_auto_approve_role">
                                <option value="admin" <?php echo ($settings['discount']['discount_auto_approve_role'] ?? 'admin') == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="manager" <?php echo ($settings['discount']['discount_auto_approve_role'] ?? '') == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                <option value="cashier" <?php echo ($settings['discount']['discount_auto_approve_role'] ?? '') == 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                            </select>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-undo-alt"></i> Refund Settings</div>
                        <div class="form-group">
                            <label>Refund Period (Days)</label>
                            <input type="number" class="form-control" name="refund_days_limit" value="<?php echo $settings['discount']['refund_days_limit'] ?? '30'; ?>">
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="refund_requires_approval" value="1" <?php echo ($settings['discount']['refund_requires_approval'] ?? false) ? 'checked' : ''; ?>>
                            <label>Require approval for refunds</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="refund_restock" value="1" <?php echo ($settings['discount']['refund_restock'] ?? false) ? 'checked' : ''; ?>>
                            <label>Restock items on refund</label>
                        </div>
                        <div class="form-group">
                            <label>Default Refund Method</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="refund_method" value="original" <?php echo ($settings['discount']['refund_method'] ?? 'original') == 'original' ? 'checked' : ''; ?>> Original Payment Method
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="refund_method" value="cash" <?php echo ($settings['discount']['refund_method'] ?? '') == 'cash' ? 'checked' : ''; ?>> Cash
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="refund_method" value="credit" <?php echo ($settings['discount']['refund_method'] ?? '') == 'credit' ? 'checked' : ''; ?>> Store Credit
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-layer-group"></i> Bulk Discount</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="bulk_discount_enabled" value="1" <?php echo ($settings['discount']['bulk_discount_enabled'] ?? false) ? 'checked' : ''; ?>>
                            <label>Enable bulk purchase discounts</label>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Minimum Items for Bulk Discount</label>
                                <input type="number" class="form-control" name="bulk_discount_min_items" value="<?php echo $settings['discount']['bulk_discount_min_items'] ?? '5'; ?>">
                            </div>
                            <div class="form-group">
                                <label>Bulk Discount Percentage (%)</label>
                                <input type="number" class="form-control" name="bulk_discount_percentage" value="<?php echo $settings['discount']['bulk_discount_percentage'] ?? '10'; ?>">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Discount Settings</button>
                </form>
            </div>
        </div>

        <!-- Barcode Panel -->
        <div id="barcode-panel" class="settings-panel">
            <div class="panel-header">
                <h2><i class="fas fa-qrcode"></i> Barcode & Scanner Settings</h2>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="section" value="barcode">
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-toggle-on"></i> General Settings</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="barcode_enabled" value="1" <?php echo ($settings['barcode']['barcode_enabled'] ?? false) ? 'checked' : ''; ?>>
                            <label>Enable Barcode System</label>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-barcode"></i> Barcode Format</div>
                        <div class="form-group">
                            <label>Barcode Format</label>
                            <select class="form-control" name="barcode_format">
                                <option value="CODE128" <?php echo ($settings['barcode']['barcode_format'] ?? 'CODE128') == 'CODE128' ? 'selected' : ''; ?>>CODE128</option>
                                <option value="EAN13" <?php echo ($settings['barcode']['barcode_format'] ?? '') == 'EAN13' ? 'selected' : ''; ?>>EAN-13</option>
                                <option value="QR" <?php echo ($settings['barcode']['barcode_format'] ?? '') == 'QR' ? 'selected' : ''; ?>>QR Code</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Barcode Width (mm)</label>
                                <input type="number" class="form-control" name="barcode_width" value="<?php echo $settings['barcode']['barcode_width'] ?? '50'; ?>">
                            </div>
                            <div class="form-group">
                                <label>Barcode Height (mm)</label>
                                <input type="number" class="form-control" name="barcode_height" value="<?php echo $settings['barcode']['barcode_height'] ?? '30'; ?>">
                            </div>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="barcode_include_price" value="1" <?php echo ($settings['barcode']['barcode_include_price'] ?? false) ? 'checked' : ''; ?>>
                            <label>Include price in barcode</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="barcode_include_name" value="1" <?php echo ($settings['barcode']['barcode_include_name'] ?? false) ? 'checked' : ''; ?>>
                            <label>Include product name in barcode</label>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-scanner"></i> Scanner Configuration</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="scanner_auto_enter" value="1" <?php echo ($settings['barcode']['scanner_auto_enter'] ?? false) ? 'checked' : ''; ?>>
                            <label>Auto-enter on scan</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="scanner_beep_on_scan" value="1" <?php echo ($settings['barcode']['scanner_beep_on_scan'] ?? false) ? 'checked' : ''; ?>>
                            <label>Beep on successful scan</label>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Scanner Prefix</label>
                                <input type="text" class="form-control" name="scanner_prefix" value="<?php echo htmlspecialchars($settings['barcode']['scanner_prefix'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Scanner Suffix</label>
                                <input type="text" class="form-control" name="scanner_suffix" value="<?php echo htmlspecialchars($settings['barcode']['scanner_suffix'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Scanner Timeout (ms)</label>
                            <input type="number" class="form-control" name="scanner_timeout" value="<?php echo $settings['barcode']['scanner_timeout'] ?? '500'; ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Barcode Settings</button>
                </form>
            </div>
        </div>

        <!-- Appearance Panel -->
        <div id="appearance-panel" class="settings-panel">
            <div class="panel-header">
                <h2><i class="fas fa-palette"></i> Appearance & Print Settings</h2>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="section" value="appearance">
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-heading"></i> Header Appearance</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Header Background Color</label>
                                <div class="color-input-group">
                                    <input type="color" name="header_bg_color" value="<?php echo htmlspecialchars($settings['appearance']['header_bg_color'] ?? '#1e3c72'); ?>">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($settings['appearance']['header_bg_color'] ?? '#1e3c72'); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Header Text Color</label>
                                <div class="color-input-group">
                                    <input type="color" name="header_text_color" value="<?php echo htmlspecialchars($settings['appearance']['header_text_color'] ?? '#ffffff'); ?>">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($settings['appearance']['header_text_color'] ?? '#ffffff'); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Header Gradient Start</label>
                                <div class="color-input-group">
                                    <input type="color" name="header_gradient_start" value="<?php echo htmlspecialchars($settings['appearance']['header_gradient_start'] ?? '#1e3c72'); ?>">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($settings['appearance']['header_gradient_start'] ?? '#1e3c72'); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Header Gradient End</label>
                                <div class="color-input-group">
                                    <input type="color" name="header_gradient_end" value="<?php echo htmlspecialchars($settings['appearance']['header_gradient_end'] ?? '#2a5298'); ?>">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($settings['appearance']['header_gradient_end'] ?? '#2a5298'); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="header_use_gradient" value="1" <?php echo ($settings['appearance']['header_use_gradient'] ?? false) ? 'checked' : ''; ?>>
                            <label>Use gradient effect on header</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="header_show_logo" value="1" <?php echo ($settings['appearance']['header_show_logo'] ?? false) ? 'checked' : ''; ?>>
                            <label>Show company logo in header</label>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-print"></i> Print Settings</div>
                        <div class="form-group">
                            <label>Default Print Font</label>
                            <select class="form-control" name="print_font" id="print_font">
                                <option value="Times New Roman" <?php echo ($settings['appearance']['print_font'] ?? 'Times New Roman') == 'Times New Roman' ? 'selected' : ''; ?>>Times New Roman</option>
                                <option value="Arial" <?php echo ($settings['appearance']['print_font'] ?? '') == 'Arial' ? 'selected' : ''; ?>>Arial</option>
                                <option value="Calibri" <?php echo ($settings['appearance']['print_font'] ?? '') == 'Calibri' ? 'selected' : ''; ?>>Calibri</option>
                                <option value="Georgia" <?php echo ($settings['appearance']['print_font'] ?? '') == 'Georgia' ? 'selected' : ''; ?>>Georgia</option>
                                <option value="Verdana" <?php echo ($settings['appearance']['print_font'] ?? '') == 'Verdana' ? 'selected' : ''; ?>>Verdana</option>
                                <option value="Courier New" <?php echo ($settings['appearance']['print_font'] ?? '') == 'Courier New' ? 'selected' : ''; ?>>Courier New</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Print Font Size (pts)</label>
                                <input type="number" step="0.5" class="form-control" name="print_font_size" value="<?php echo $settings['appearance']['print_font_size'] ?? '10'; ?>">
                            </div>
                            <div class="form-group">
                                <label>Print Line Height</label>
                                <input type="number" step="0.1" class="form-control" name="print_line_height" value="<?php echo $settings['appearance']['print_line_height'] ?? '1.2'; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Print Page Margin (mm)</label>
                            <div class="form-row">
                                <div class="form-group"><label>Top</label><input type="number" class="form-control" name="print_margin_top" value="<?php echo $settings['appearance']['print_margin_top'] ?? '10'; ?>"></div>
                                <div class="form-group"><label>Right</label><input type="number" class="form-control" name="print_margin_right" value="<?php echo $settings['appearance']['print_margin_right'] ?? '10'; ?>"></div>
                                <div class="form-group"><label>Bottom</label><input type="number" class="form-control" name="print_margin_bottom" value="<?php echo $settings['appearance']['print_margin_bottom'] ?? '10'; ?>"></div>
                                <div class="form-group"><label>Left</label><input type="number" class="form-control" name="print_margin_left" value="<?php echo $settings['appearance']['print_margin_left'] ?? '10'; ?>"></div>
                            </div>
                        </div>
                        <div class="font-preview" id="fontPreview" style="font-family: <?php echo htmlspecialchars($settings['appearance']['print_font'] ?? 'Times New Roman'); ?>; font-size: <?php echo $settings['appearance']['print_font_size'] ?? '10'; ?>pt; line-height: <?php echo $settings['appearance']['print_line_height'] ?? '1.2'; ?>">
                            <strong>Sample Text:</strong><br>
                            SAVANT MOTORS UGANDA<br>
                            Bugolobi, Kampala<br>
                            Invoice #INV-2024-001<br>
                            Total: UGX 1,500,000
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-file-alt"></i> Document Settings</div>
                        <div class="form-group">
                            <label>Company Logo URL (for print)</label>
                            <input type="text" class="form-control" name="company_logo_url" value="<?php echo htmlspecialchars($settings['appearance']['company_logo_url'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Company Footer Text</label>
                            <textarea class="form-control" name="company_footer_text" rows="2"><?php echo htmlspecialchars($settings['appearance']['company_footer_text'] ?? 'Savant Motors - Quality Service You Can Trust'); ?></textarea>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="print_watermark" value="1" <?php echo ($settings['appearance']['print_watermark'] ?? false) ? 'checked' : ''; ?>>
                            <label>Show watermark on printed documents</label>
                        </div>
                        <div class="form-group">
                            <label>Watermark Text</label>
                            <input type="text" class="form-control" name="watermark_text" value="<?php echo htmlspecialchars($settings['appearance']['watermark_text'] ?? 'SAVANT MOTORS'); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Appearance Settings</button>
                </form>
            </div>
        </div>

        <!-- Voice Panel (enhanced with schedule and custom announcements) -->
        <div id="voice-panel" class="settings-panel">
            <div class="panel-header">
                <h2><i class="fas fa-volume-up"></i> Voice Announcement Settings</h2>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="section" value="voice">
                    
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-microphone-alt"></i> General Voice Settings</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_enabled" id="voice_enabled" value="1" <?php echo ($settings['voice']['voice_enabled'] ?? true) ? 'checked' : ''; ?>>
                            <label for="voice_enabled">Enable Voice Announcements</label>
                        </div>
                        <div class="help-text"><i class="fas fa-info-circle"></i> When enabled, the system will speak important notifications aloud.</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Voice Speed (Rate)</label>
                                <input type="range" name="voice_rate" min="0.5" max="2" step="0.1" value="<?php echo $settings['voice']['voice_rate'] ?? '0.9'; ?>" oninput="this.nextElementSibling.value = this.value">
                                <output><?php echo $settings['voice']['voice_rate'] ?? '0.9'; ?></output>
                                <div class="help-text">Slower (0.5) ↔ Faster (2.0)</div>
                            </div>
                            <div class="form-group">
                                <label>Voice Pitch</label>
                                <input type="range" name="voice_pitch" min="0.5" max="2" step="0.1" value="<?php echo $settings['voice']['voice_pitch'] ?? '1.1'; ?>" oninput="this.nextElementSibling.value = this.value">
                                <output><?php echo $settings['voice']['voice_pitch'] ?? '1.1'; ?></output>
                                <div class="help-text">Lower (0.5) ↔ Higher (2.0)</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Voice Volume</label>
                                <input type="range" name="voice_volume" min="0" max="1" step="0.1" value="<?php echo $settings['voice']['voice_volume'] ?? '1'; ?>" oninput="this.nextElementSibling.value = this.value">
                                <output><?php echo $settings['voice']['voice_volume'] ?? '1'; ?></output>
                                <div class="help-text">Mute (0) ↔ Full Volume (1)</div>
                            </div>
                            <div class="form-group">
                                <label>Announcement Interval (Seconds)</label>
                                <input type="number" class="form-control" name="voice_interval" value="<?php echo $settings['voice']['voice_interval'] ?? '60'; ?>" step="10">
                                <div class="help-text">How often to repeat announcements (0 = only once)</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Voice Language</label>
                                <select class="form-control" name="voice_language">
                                    <option value="en-US" <?php echo ($settings['voice']['voice_language'] ?? 'en-US') == 'en-US' ? 'selected' : ''; ?>>English (US)</option>
                                    <option value="en-GB" <?php echo ($settings['voice']['voice_language'] ?? '') == 'en-GB' ? 'selected' : ''; ?>>English (UK)</option>
                                    <option value="en-AU" <?php echo ($settings['voice']['voice_language'] ?? '') == 'en-AU' ? 'selected' : ''; ?>>English (Australia)</option>
                                    <option value="en-IN" <?php echo ($settings['voice']['voice_language'] ?? '') == 'en-IN' ? 'selected' : ''; ?>>English (India)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Preferred Voice</label>
                                <select class="form-control" name="voice_voice_name">
                                    <option value="Google UK English Female" <?php echo ($settings['voice']['voice_voice_name'] ?? 'Google UK English Female') == 'Google UK English Female' ? 'selected' : ''; ?>>Google UK English Female</option>
                                    <option value="Google US English" <?php echo ($settings['voice']['voice_voice_name'] ?? '') == 'Google US English' ? 'selected' : ''; ?>>Google US English</option>
                                    <option value="Samantha" <?php echo ($settings['voice']['voice_voice_name'] ?? '') == 'Samantha' ? 'selected' : ''; ?>>Samantha (Female)</option>
                                    <option value="Alex" <?php echo ($settings['voice']['voice_voice_name'] ?? '') == 'Alex' ? 'selected' : ''; ?>>Alex (Male)</option>
                                    <option value="Victoria" <?php echo ($settings['voice']['voice_voice_name'] ?? '') == 'Victoria' ? 'selected' : ''; ?>>Victoria (Female)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- NEW: Schedule Section -->
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-clock"></i> Announcement Schedule</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Start Time (24h)</label>
                                <input type="time" class="form-control" name="voice_announcement_start_time" value="<?php echo htmlspecialchars($settings['voice']['voice_announcement_start_time'] ?? '08:00'); ?>">
                                <div class="help-text">Voice announcements will only be spoken after this time each day.</div>
                            </div>
                            <div class="form-group">
                                <label>End Time (24h)</label>
                                <input type="time" class="form-control" name="voice_announcement_end_time" value="<?php echo htmlspecialchars($settings['voice']['voice_announcement_end_time'] ?? '22:00'); ?>">
                                <div class="help-text">Voice announcements will stop after this time (leave empty for no end time).</div>
                            </div>
                        </div>
                    </div>

                    <!-- NEW: Custom Announcements Textarea -->
                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-edit"></i> Write Your Own Announcements</div>
                        <div class="form-group">
                            <label>Custom Announcements (one per line)</label>
                            <textarea class="form-control" name="voice_custom_announcements" rows="5" placeholder="Enter each announcement on a new line...&#10;Example:&#10;Special offer: 10% off on all batteries!&#10;Don't forget to check tool returns.&#10;Welcome our new customers!"><?php echo htmlspecialchars($settings['voice']['voice_custom_announcements'] ?? ''); ?></textarea>
                            <div class="help-text">These messages will be spoken in random order along with system alerts.</div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-bullhorn"></i> What to Announce</div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_announce_overdue_tools" value="1" <?php echo ($settings['voice']['voice_announce_overdue_tools'] ?? true) ? 'checked' : ''; ?>>
                            <label>🔧 Overdue Tools</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_announce_low_stock" value="1" <?php echo ($settings['voice']['voice_announce_low_stock'] ?? true) ? 'checked' : ''; ?>>
                            <label>📦 Low Stock Alerts</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_announce_new_jobs" value="1" <?php echo ($settings['voice']['voice_announce_new_jobs'] ?? true) ? 'checked' : ''; ?>>
                            <label>📋 New Job Cards</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_announce_pending_requests" value="1" <?php echo ($settings['voice']['voice_announce_pending_requests'] ?? true) ? 'checked' : ''; ?>>
                            <label>📝 Pending Tool Requests</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_announce_unpaid_invoices" value="1" <?php echo ($settings['voice']['voice_announce_unpaid_invoices'] ?? true) ? 'checked' : ''; ?>>
                            <label>💰 Unpaid Invoices</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_announce_overdue_jobs" value="1" <?php echo ($settings['voice']['voice_announce_overdue_jobs'] ?? true) ? 'checked' : ''; ?>>
                            <label>⏰ Overdue Jobs</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="voice_announce_reminders" value="1" <?php echo ($settings['voice']['voice_announce_reminders'] ?? true) ? 'checked' : ''; ?>>
                            <label>🔔 Pickup Reminders</label>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-comment-dots"></i> Static Voice Messages</div>
                        <div class="form-group">
                            <label>Welcome / Greeting Message</label>
                            <textarea class="form-control" name="voice_greeting" rows="2"><?php echo htmlspecialchars($settings['voice']['voice_greeting'] ?? 'Good morning! Welcome to Savant Motors.'); ?></textarea>
                            <div class="help-text">Spoken when the dashboard loads</div>
                        </div>
                        <div class="form-group">
                            <label>Custom Announcement Message</label>
                            <textarea class="form-control" name="voice_custom_message" rows="2"><?php echo htmlspecialchars($settings['voice']['voice_custom_message'] ?? 'Welcome to Savant Motors ERP System. Please check the dashboard for updates.'); ?></textarea>
                            <div class="help-text">Additional message to be announced periodically</div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-title"><i class="fas fa-play-circle"></i> Test Voice</div>
                        <button type="button" class="btn btn-primary" onclick="testVoice()">
                            <i class="fas fa-play"></i> Test Voice Announcement
                        </button>
                        <div class="help-text">Click to hear how the voice will sound with current settings</div>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Voice Settings</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const tabName = this.getAttribute('data-tab');
                
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                document.querySelectorAll('.settings-panel').forEach(panel => panel.classList.remove('active'));
                document.getElementById(`${tabName}-panel`).classList.add('active');
            });
        });
        
        // Font preview update
        const printFont = document.getElementById('print_font');
        if (printFont) {
            printFont.addEventListener('change', function() {
                const fontSize = document.querySelector('[name="print_font_size"]')?.value || 10;
                const lineHeight = document.querySelector('[name="print_line_height"]')?.value || 1.2;
                const preview = document.getElementById('fontPreview');
                if (preview) {
                    preview.style.fontFamily = this.value;
                    preview.style.fontSize = fontSize + 'pt';
                    preview.style.lineHeight = lineHeight;
                }
            });
        }
        
        // Color picker sync
        document.querySelectorAll('.color-input-group input[type="color"]').forEach(colorPicker => {
            const textInput = colorPicker.parentElement.querySelector('input[type="text"]');
            if (textInput) {
                colorPicker.addEventListener('input', () => textInput.value = colorPicker.value);
                textInput.addEventListener('input', () => colorPicker.value = textInput.value);
            }
        });
        
        // Price calculation function (based on cost price only)
        function calculateSellingPrice() {
            const costPrice = parseFloat(document.getElementById('calc_cost_price')?.value) || 0;
            
            // Get markup settings from the form
            const markupEnabled = document.querySelector('[name="markup_enabled"]')?.checked;
            const markupType = document.querySelector('[name="markup_type"]')?.value || 'percentage';
            let markupValue = parseFloat(document.querySelector('[name="markup_value"]')?.value) || 0;
            const roundToNearest = parseInt(document.querySelector('[name="round_to_nearest"]')?.value) || 0;
            const minSellingPrice = parseFloat(document.querySelector('[name="min_selling_price"]')?.value) || 0;
            const maxMarkupPercentage = parseFloat(document.querySelector('[name="max_markup_percentage"]')?.value) || 200;
            
            if (costPrice <= 0) {
                document.getElementById('calc_result').innerHTML = 'UGX 0';
                document.getElementById('calc_breakdown').innerHTML = 'Enter cost price to calculate';
                return;
            }
            
            let sellingPrice = costPrice;
            let markupAmount = 0;
            let markupPercent = 0;
            
            if (markupEnabled && markupValue > 0) {
                if (markupType === 'percentage') {
                    // Cap markup percentage if it exceeds maximum
                    if (markupValue > maxMarkupPercentage) {
                        markupValue = maxMarkupPercentage;
                    }
                    markupAmount = costPrice * (markupValue / 100);
                    sellingPrice = costPrice + markupAmount;
                    markupPercent = markupValue;
                } else {
                    // Fixed markup - calculate equivalent percentage for display
                    markupAmount = markupValue;
                    sellingPrice = costPrice + markupValue;
                    markupPercent = (markupValue / costPrice) * 100;
                    
                    // Check if markup percentage exceeds maximum
                    if (markupPercent > maxMarkupPercentage) {
                        markupAmount = costPrice * (maxMarkupPercentage / 100);
                        sellingPrice = costPrice + markupAmount;
                        markupPercent = maxMarkupPercentage;
                    }
                }
            }
            
            // Apply rounding
            let finalPrice = sellingPrice;
            if (roundToNearest > 0) {
                finalPrice = Math.ceil(sellingPrice / roundToNearest) * roundToNearest;
            }
            
            // Apply minimum selling price
            if (minSellingPrice > 0 && finalPrice < minSellingPrice) {
                finalPrice = minSellingPrice;
            }
            
            finalPrice = Math.round(finalPrice);
            
            // Format currency
            document.getElementById('calc_result').innerHTML = `UGX ${finalPrice.toLocaleString()}`;
            
            let breakdown = `Cost Price: UGX ${costPrice.toLocaleString()}<br>`;
            if (markupEnabled && markupValue > 0) {
                if (markupType === 'percentage') {
                    breakdown += `+ ${markupValue}% Markup: UGX ${Math.round(markupAmount).toLocaleString()}<br>`;
                } else {
                    breakdown += `+ Fixed Markup: UGX ${Math.round(markupValue).toLocaleString()} (${markupPercent.toFixed(1)}%)<br>`;
                }
            }
            breakdown += `= Base Price: UGX ${Math.round(sellingPrice).toLocaleString()}`;
            
            if (roundToNearest > 0) {
                breakdown += `<br>Rounded to nearest ${roundToNearest.toLocaleString()}: UGX ${finalPrice.toLocaleString()}`;
            }
            
            if (minSellingPrice > 0 && sellingPrice < minSellingPrice) {
                breakdown += `<br><span style="color: #ffd700;">⚠️ Minimum price applied (UGX ${minSellingPrice.toLocaleString()})</span>`;
            }
            
            if (markupType === 'percentage' && markupValue > maxMarkupPercentage) {
                breakdown += `<br><span style="color: #ffd700;">⚠️ Markup capped at ${maxMarkupPercentage}% maximum</span>`;
            }
            
            document.getElementById('calc_breakdown').innerHTML = breakdown;
        }
        
        function clearCategoryMarkup(btn) {
            const row = btn.closest('tr');
            const valueInput = row.querySelector('input[type="number"]');
            const select = row.querySelector('select');
            if (valueInput) valueInput.value = '';
            if (select) select.value = 'percentage';
        }
        
        // Test voice function
        function testVoice() {
            const enabled = document.querySelector('input[name="voice_enabled"]')?.checked;
            if (!enabled) {
                alert('Please enable voice announcements first');
                return;
            }
            
            const rate = parseFloat(document.querySelector('input[name="voice_rate"]')?.value) || 0.9;
            const pitch = parseFloat(document.querySelector('input[name="voice_pitch"]')?.value) || 1.1;
            const volume = parseFloat(document.querySelector('input[name="voice_volume"]')?.value) || 1;
            const language = document.querySelector('select[name="voice_language"]')?.value || 'en-US';
            
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
                
                const utterance = new SpeechSynthesisUtterance('This is a test of the voice announcement system. Savant Motors ERP voice notifications are working properly.');
                utterance.rate = rate;
                utterance.pitch = pitch;
                utterance.volume = volume;
                utterance.lang = language;
                
                const voices = window.speechSynthesis.getVoices();
                const preferredVoice = voices.find(voice => 
                    voice.lang === language && (voice.name.includes('Google') || voice.name.includes('Female'))
                );
                if (preferredVoice) {
                    utterance.voice = preferredVoice;
                }
                
                window.speechSynthesis.speak(utterance);
            } else {
                alert('Your browser does not support speech synthesis');
            }
        }
        
        // Update range display values
        document.querySelectorAll('input[type="range"]').forEach(range => {
            const output = range.nextElementSibling;
            if (output && output.tagName === 'OUTPUT') {
                range.addEventListener('input', () => output.value = range.value);
            }
        });
        
        // Add event listeners for price calculator
        document.querySelectorAll('[name="markup_enabled"], [name="markup_type"], [name="markup_value"], [name="round_to_nearest"], [name="min_selling_price"], [name="max_markup_percentage"]').forEach(el => {
            el.addEventListener('change', () => calculateSellingPrice());
            el.addEventListener('input', () => calculateSellingPrice());
        });
        
        // Initial calculation
        setTimeout(calculateSellingPrice, 100);
    </script>
</body>
</html>