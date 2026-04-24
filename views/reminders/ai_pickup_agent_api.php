<?php
/**
 * ai_pickup_agent_api.php
 * ─────────────────────────────────────────────────────────────────────
 * SAVANT MOTORS — AI Pickup Agent: Backend API
 *
 * Deploy this file alongside index.php at:
 *   views/reminders/ai_pickup_agent_api.php
 *
 * It handles four endpoints (via ?action=...):
 *   1. whatsapp_send      – Logs & opens WhatsApp link for a recipient
 *   2. check_reminders    – Cron-safe: returns pickups due soon, overdue
 *   3. update_location    – Staff posts their current location & status
 *   4. admin_notify       – Sends an admin alert email + logs it to DB
 *   5. get_status         – Returns full live dashboard JSON for AI
 *
 * Called from the frontend JS (no session required for cron endpoints,
 * session required for admin_notify and whatsapp_send).
 * ─────────────────────────────────────────────────────────────────────
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── DB ────────────────────────────────────────────────────────────────
function getDB(): PDO {
    $pdo = new PDO("mysql:host=localhost;dbname=savant_motors_pos;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// ── Helpers ───────────────────────────────────────────────────────────
function respond(bool $ok, array $data = [], string $msg = ''): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit;
}

function ugPhone(string $raw): string {
    $digits = preg_replace('/[^0-9]/', '', $raw);
    if (str_starts_with($digits, '0')) $digits = '256' . substr($digits, 1);
    elseif (!str_starts_with($digits, '256')) $digits = '256' . $digits;
    return $digits;
}

function waLink(string $phone, string $message): string {
    return 'https://wa.me/' . ugPhone($phone) . '?text=' . rawurlencode($message);
}

// ── Ensure helper tables exist ────────────────────────────────────────
function ensureTables(PDO $db): void {
    // Staff location log
    $db->exec("CREATE TABLE IF NOT EXISTS staff_location_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT,
        staff_name VARCHAR(255),
        reminder_id INT,
        location_text VARCHAR(500),
        eta_minutes INT DEFAULT NULL,
        status ENUM('departed','enroute','arrived','returning','done') DEFAULT 'enroute',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(staff_id), INDEX(reminder_id)
    )");

    // WhatsApp message log
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reminder_id INT,
        recipient_name VARCHAR(255),
        recipient_phone VARCHAR(50),
        message TEXT,
        sent_by INT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(reminder_id)
    )");

    // Admin notifications log
    $db->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reminder_id INT,
        raised_by INT,
        raised_by_name VARCHAR(255),
        subject VARCHAR(500),
        body TEXT,
        status ENUM('sent','pending','read') DEFAULT 'sent',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(reminder_id)
    )");

    // Reminder send log
    $db->exec("CREATE TABLE IF NOT EXISTS reminder_send_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reminder_id INT,
        channel VARCHAR(20),
        recipient_name VARCHAR(255),
        recipient_phone VARCHAR(50),
        message TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        auto_sent TINYINT(1) DEFAULT 0,
        INDEX(reminder_id)
    )");
}

// ══════════════════════════════════════════════════════════════════════
//  ROUTER
// ══════════════════════════════════════════════════════════════════════
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

try {
    $db = getDB();
    ensureTables($db);

    match($action) {
        'whatsapp_send'   => handleWhatsAppSend($db),
        'check_reminders' => handleCheckReminders($db),
        'update_location' => handleUpdateLocation($db),
        'admin_notify'    => handleAdminNotify($db),
        'get_status'      => handleGetStatus($db),
        'mark_status'     => handleMarkStatus($db),
        'get_locations'   => handleGetLocations($db),
        default           => respond(false, [], "Unknown action: '$action'")
    };
} catch (PDOException $e) {
    respond(false, ['error' => $e->getMessage()], 'Database error');
}


// ══════════════════════════════════════════════════════════════════════
//  1. WHATSAPP SEND — log + return WA link
// ══════════════════════════════════════════════════════════════════════
function handleWhatsAppSend(PDO $db): void {
    $reminderId    = (int)($_POST['reminder_id'] ?? 0);
    $phone         = trim($_POST['phone'] ?? '');
    $recipientName = trim($_POST['recipient_name'] ?? 'Recipient');
    $message       = trim($_POST['message'] ?? '');
    $sentBy        = (int)($_SESSION['user_id'] ?? 0);

    if (empty($phone) || empty($message)) {
        respond(false, [], 'Phone and message are required');
    }

    // Log it
    $db->prepare("INSERT INTO whatsapp_log
        (reminder_id, recipient_name, recipient_phone, message, sent_by)
        VALUES (?,?,?,?,?)")
       ->execute([$reminderId, $recipientName, $phone, $message, $sentBy]);

    // Also log in reminder_send_log
    if ($reminderId) {
        $db->prepare("INSERT INTO reminder_send_log
            (reminder_id, channel, recipient_name, recipient_phone, message, auto_sent)
            VALUES (?,?,?,?,?,0)")
           ->execute([$reminderId, 'whatsapp', $recipientName, $phone, $message]);
    }

    respond(true, [
        'wa_link'       => waLink($phone, $message),
        'phone_e164'    => ugPhone($phone),
        'recipient'     => $recipientName,
        'message_chars' => strlen($message),
    ], 'WhatsApp message logged');
}


// ══════════════════════════════════════════════════════════════════════
//  2. CHECK REMINDERS — due soon / overdue (safe for cron)
// ══════════════════════════════════════════════════════════════════════
function handleCheckReminders(PDO $db): void {
    $horizonHours = (int)($_GET['hours'] ?? 3);
    $now          = new DateTime();
    $horizon      = (clone $now)->modify("+{$horizonHours} hours");

    $rows = $db->query("
        SELECT vpr.*,
               COALESCE(u.full_name, s.full_name, 'Unassigned') as staff_name,
               COALESCE(u.email, s.email, '')                    as staff_email
        FROM vehicle_pickup_reminders vpr
        LEFT JOIN users u ON vpr.assigned_to = u.id
        LEFT JOIN staff s ON vpr.assigned_to = s.id
        WHERE vpr.status IN ('pending','scheduled','in_progress')
        ORDER BY vpr.pickup_date ASC, vpr.pickup_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $dueSoon  = [];
    $overdue  = [];
    $enRoute  = [];
    $upcoming = [];

    foreach ($rows as $r) {
        if (empty($r['pickup_date'])) continue;
        $timeStr = !empty($r['pickup_time']) ? $r['pickup_time'] : '08:00:00';
        try {
            $due = new DateTime($r['pickup_date'] . ' ' . $timeStr);
        } catch (Exception $e) { continue; }

        $diffMin = ($due->getTimestamp() - $now->getTimestamp()) / 60;

        $entry = [
            'id'             => $r['id'],
            'reminder_number'=> $r['reminder_number'] ?? '',
            'customer_name'  => $r['customer_name'] ?? '',
            'customer_phone' => $r['customer_phone'] ?? '',
            'vehicle_reg'    => $r['vehicle_reg'] ?? '',
            'pickup_type'    => $r['pickup_type'] ?? 'workshop',
            'pickup_address' => $r['pickup_address'] ?? '',
            'pickup_datetime'=> $due->format('D d M Y, h:i A'),
            'minutes_until'  => round($diffMin),
            'staff_name'     => $r['staff_name'],
            'staff_email'    => $r['staff_email'],
            'status'         => $r['status'],
            'wa_staff_link'  => '',
            'wa_customer_link' => '',
        ];

        // Pre-build WA reminder messages
        $staffMsg = buildStaffReminderMsg($entry);
        $custMsg  = buildCustomerReminderMsg($entry);
        $entry['wa_staff_link']    = waLink($r['customer_phone'] ?? '', $staffMsg); // staff gets same number for now
        $entry['wa_customer_link'] = waLink($r['customer_phone'] ?? '', $custMsg);
        $entry['staff_wa_message']    = $staffMsg;
        $entry['customer_wa_message'] = $custMsg;

        if ($r['status'] === 'in_progress') {
            $enRoute[] = $entry;
        } elseif ($diffMin < 0) {
            $overdue[] = $entry;
        } elseif ($diffMin <= $horizonHours * 60) {
            $dueSoon[] = $entry;
        } else {
            $upcoming[] = $entry;
        }
    }

    respond(true, [
        'due_soon'        => $dueSoon,
        'overdue'         => $overdue,
        'en_route'        => $enRoute,
        'upcoming'        => $upcoming,
        'total_active'    => count($rows),
        'checked_at'      => $now->format('Y-m-d H:i:s'),
        'horizon_hours'   => $horizonHours,
    ], count($dueSoon) . ' pickup(s) due within ' . $horizonHours . 'h');
}

function buildStaffReminderMsg(array $r): string {
    $time = $r['pickup_datetime'];
    $name = $r['customer_name'];
    $reg  = $r['vehicle_reg'];
    $type = strtolower($r['pickup_type']);
    $addr = $r['pickup_address'] ? "\nLocation: {$r['pickup_address']}" : '';
    $mins = abs($r['minutes_until']);

    if ($r['minutes_until'] < 0) {
        $urgency = "⚠️ OVERDUE by {$mins} minutes!";
    } elseif ($r['minutes_until'] <= 30) {
        $urgency = "🚨 URGENT — due in {$mins} minutes!";
    } elseif ($r['minutes_until'] <= 60) {
        $urgency = "⏰ Due in {$mins} minutes";
    } else {
        $urgency = "🔔 Reminder";
    }

    return "🔧 *SAVANT MOTORS — Pickup Reminder*\n"
         . "{$urgency}\n\n"
         . "📋 Job: {$r['reminder_number']}\n"
         . "👤 Customer: {$name}\n"
         . "🚗 Vehicle: {$reg}\n"
         . "📍 Type: " . ucfirst($type) . "{$addr}\n"
         . "🕐 Due: {$time}\n\n"
         . "Please confirm when you depart and when you arrive.\n"
         . "Reply with your current location if running late.";
}

function buildCustomerReminderMsg(array $r): string {
    $time = $r['pickup_datetime'];
    $reg  = $r['vehicle_reg'];
    return "🚗 *SAVANT MOTORS*\n\n"
         . "Dear {$r['customer_name']},\n\n"
         . "This is a reminder that your vehicle *{$reg}* is scheduled for pickup on *{$time}*.\n\n"
         . "Our staff will be with you shortly. Please ensure the vehicle is accessible.\n\n"
         . "📞 For any queries, please call us.\n"
         . "Thank you for choosing Savant Motors! 🙏";
}


// ══════════════════════════════════════════════════════════════════════
//  3. UPDATE LOCATION — staff posts their GPS/text location
// ══════════════════════════════════════════════════════════════════════
function handleUpdateLocation(PDO $db): void {
    $staffId      = (int)($_POST['staff_id'] ?? $_SESSION['user_id'] ?? 0);
    $staffName    = trim($_POST['staff_name'] ?? ($_SESSION['full_name'] ?? 'Unknown'));
    $reminderId   = (int)($_POST['reminder_id'] ?? 0);
    $locationText = trim($_POST['location'] ?? '');
    $etaMinutes   = isset($_POST['eta_minutes']) ? (int)$_POST['eta_minutes'] : null;
    $status       = trim($_POST['status'] ?? 'enroute');

    $allowed = ['departed','enroute','arrived','returning','done'];
    if (!in_array($status, $allowed)) $status = 'enroute';

    if (empty($locationText)) {
        respond(false, [], 'Location text is required');
    }

    $db->prepare("INSERT INTO staff_location_updates
        (staff_id, staff_name, reminder_id, location_text, eta_minutes, status)
        VALUES (?,?,?,?,?,?)")
       ->execute([$staffId, $staffName, $reminderId, $locationText, $etaMinutes, $status]);

    // If status is 'arrived' or 'done', update the reminder status too
    if ($reminderId && in_array($status, ['arrived', 'done'])) {
        $newStatus = $status === 'done' ? 'completed' : 'in_progress';
        $db->prepare("UPDATE vehicle_pickup_reminders SET status=? WHERE id=?")
           ->execute([$newStatus, $reminderId]);
    }
    if ($reminderId && $status === 'departed') {
        $db->prepare("UPDATE vehicle_pickup_reminders SET status='in_progress' WHERE id=? AND status IN ('pending','scheduled')")
           ->execute([$reminderId]);
    }

    respond(true, [
        'staff_name'   => $staffName,
        'location'     => $locationText,
        'status'       => $status,
        'eta_minutes'  => $etaMinutes,
        'reminder_id'  => $reminderId,
        'updated_at'   => date('Y-m-d H:i:s'),
    ], 'Location updated successfully');
}


// ══════════════════════════════════════════════════════════════════════
//  4. ADMIN NOTIFY — log + send email alert
// ══════════════════════════════════════════════════════════════════════
function handleAdminNotify(PDO $db): void {
    $reminderId  = (int)($_POST['reminder_id'] ?? 0);
    $raisedBy    = (int)($_POST['raised_by']   ?? $_SESSION['user_id'] ?? 0);
    $raisedName  = trim($_POST['raised_by_name'] ?? ($_SESSION['full_name'] ?? 'Staff'));
    $subject     = trim($_POST['subject'] ?? 'Pickup Issue Reported');
    $body        = trim($_POST['body'] ?? '');
    $adminEmail  = trim($_POST['admin_email'] ?? 'admin@savantmotors.ug');

    if (empty($body)) {
        respond(false, [], 'Notification body is required');
    }

    // Log to DB
    $db->prepare("INSERT INTO admin_notifications
        (reminder_id, raised_by, raised_by_name, subject, body)
        VALUES (?,?,?,?,?)")
       ->execute([$reminderId, $raisedBy, $raisedName, $subject, $body]);

    $notifId = $db->lastInsertId();

    // Try to send email (uses PHP mail() — works if server has sendmail)
    $emailSent = false;
    $emailBody = "SAVANT MOTORS — Admin Notification\n"
               . str_repeat('=', 40) . "\n"
               . "From:       {$raisedName}\n"
               . "Time:       " . date('D d M Y H:i:s') . "\n"
               . "Reminder ID:{$reminderId}\n"
               . str_repeat('-', 40) . "\n\n"
               . $body . "\n\n"
               . str_repeat('-', 40) . "\n"
               . "View in system: http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost')
               . "/savant/views/reminders/index.php\n";

    $headers = "From: noreply@savantmotors.ug\r\n"
             . "Reply-To: {$adminEmail}\r\n"
             . "X-Mailer: SavantMotors-AI-Agent/1.0";

    if (function_exists('mail')) {
        $emailSent = @mail($adminEmail, "🚗 [SAVANT] {$subject}", $emailBody, $headers);
    }

    respond(true, [
        'notification_id' => $notifId,
        'email_sent'      => $emailSent,
        'admin_email'     => $adminEmail,
        'logged_at'       => date('Y-m-d H:i:s'),
    ], 'Admin notification ' . ($emailSent ? 'sent and ' : '') . 'logged');
}


// ══════════════════════════════════════════════════════════════════════
//  5. GET STATUS — full live JSON for the AI agent
// ══════════════════════════════════════════════════════════════════════
function handleGetStatus(PDO $db): void {
    // Reminders
    $reminders = $db->query("
        SELECT vpr.*,
               COALESCE(u.full_name, s.full_name, 'Unassigned') as staff_name,
               COALESCE(u.email, s.email, '') as staff_email
        FROM vehicle_pickup_reminders vpr
        LEFT JOIN users u ON vpr.assigned_to = u.id
        LEFT JOIN staff s ON vpr.assigned_to = s.id
        ORDER BY vpr.pickup_date ASC, vpr.pickup_time ASC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Latest locations per staff
    $locations = $db->query("
        SELECT l1.*
        FROM staff_location_updates l1
        INNER JOIN (
            SELECT staff_id, MAX(created_at) as latest
            FROM staff_location_updates
            WHERE created_at >= NOW() - INTERVAL 6 HOUR
            GROUP BY staff_id
        ) l2 ON l1.staff_id = l2.staff_id AND l1.created_at = l2.latest
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recent WA log
    $waLog = $db->query("
        SELECT * FROM whatsapp_log
        ORDER BY sent_at DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Admin notifications
    $adminNotifs = $db->query("
        SELECT * FROM admin_notifications
        ORDER BY created_at DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = $db->query("
        SELECT
            COUNT(*) as total,
            SUM(status='pending')     as pending,
            SUM(status='scheduled')   as scheduled,
            SUM(status='in_progress') as in_progress,
            SUM(status='completed')   as completed,
            SUM(status='cancelled')   as cancelled
        FROM vehicle_pickup_reminders
    ")->fetch(PDO::FETCH_ASSOC);

    respond(true, [
        'stats'         => $stats,
        'reminders'     => $reminders,
        'staff_locations'=> $locations,
        'whatsapp_log'  => $waLog,
        'admin_notifs'  => $adminNotifs,
        'server_time'   => date('Y-m-d H:i:s'),
    ]);
}


// ══════════════════════════════════════════════════════════════════════
//  6. MARK STATUS — update a reminder's status
// ══════════════════════════════════════════════════════════════════════
function handleMarkStatus(PDO $db): void {
    $id     = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $allowed = ['pending','scheduled','in_progress','completed','cancelled'];

    if (!$id || !in_array($status, $allowed)) {
        respond(false, [], 'Invalid id or status');
    }

    $db->prepare("UPDATE vehicle_pickup_reminders SET status=? WHERE id=?")
       ->execute([$status, $id]);

    respond(true, ['id'=>$id,'new_status'=>$status], 'Status updated');
}


// ══════════════════════════════════════════════════════════════════════
//  7. GET LOCATIONS — all staff locations in last N hours
// ══════════════════════════════════════════════════════════════════════
function handleGetLocations(PDO $db): void {
    $hours = min(24, (int)($_GET['hours'] ?? 6));
    $rows = $db->query("
        SELECT l.*, vpr.customer_name, vpr.vehicle_reg, vpr.pickup_address
        FROM staff_location_updates l
        LEFT JOIN vehicle_pickup_reminders vpr ON l.reminder_id = vpr.id
        WHERE l.created_at >= NOW() - INTERVAL {$hours} HOUR
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    respond(true, ['locations'=>$rows,'hours'=>$hours]);
}
