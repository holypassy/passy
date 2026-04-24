#!/usr/bin/env php
<?php
/**
 * pickup_reminder_cron.php
 * ──────────────────────────────────────────────────────────────────
 * SAVANT MOTORS — Auto Reminder Cron Job
 *
 * Runs every 30 minutes via server cron:
 *   */30 * * * * php /var/www/html/savant/views/reminders/pickup_reminder_cron.php
 *
 * What it does:
 *   1. Finds pickups due within the next 2 hours that haven't been reminded yet
 *   2. Logs the reminder to reminder_send_log
 *   3. Generates WhatsApp links (logs them + optionally sends via WhatsApp Business API)
 *   4. Sends admin summary email of what's pending
 *   5. Marks reminders as reminder_sent = 1
 *
 * Configure ADMIN_EMAIL and optionally WA_API_TOKEN below.
 * ──────────────────────────────────────────────────────────────────
 */

define('ADMIN_EMAIL',    'admin@savantmotors.ug');
define('WORKSHOP_NAME',  'Savant Motors');
define('WORKSHOP_PHONE', '256700000000');   // Workshop main WhatsApp
define('HORIZON_HOURS',  2);               // Remind if pickup within 2 hours
define('WA_API_TOKEN',   '');              // Optional: WhatsApp Business Cloud API token
define('WA_PHONE_ID',    '');              // Optional: WhatsApp Business phone number ID

// ── DB ────────────────────────────────────────────────────────────────
try {
    $db = new PDO("mysql:host=localhost;dbname=savant_motors_pos;charset=utf8mb4", "root", "");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "[CRON] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Ensure tables ─────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS reminder_send_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reminder_id INT,
    channel VARCHAR(20),
    recipient_name VARCHAR(255),
    recipient_phone VARCHAR(50),
    message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    auto_sent TINYINT(1) DEFAULT 1,
    INDEX(reminder_id)
)");

// ── Find due pickups ───────────────────────────────────────────────────
$now     = new DateTime();
$horizon = (clone $now)->modify('+' . HORIZON_HOURS . ' hours');

$stmt = $db->prepare("
    SELECT vpr.*,
           COALESCE(u.full_name, s.full_name, 'Unassigned') as staff_name,
           COALESCE(u.email, s.email, '')                    as staff_email
    FROM vehicle_pickup_reminders vpr
    LEFT JOIN users u ON vpr.assigned_to = u.id
    LEFT JOIN staff s ON vpr.assigned_to = s.id
    WHERE vpr.status IN ('pending','scheduled')
      AND (vpr.reminder_sent = 0 OR vpr.reminder_sent IS NULL)
      AND vpr.pickup_date IS NOT NULL
    ORDER BY vpr.pickup_date ASC, vpr.pickup_time ASC
");
$stmt->execute();
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$report    = [];

foreach ($reminders as $r) {
    $timeStr = !empty($r['pickup_time']) ? $r['pickup_time'] : '08:00:00';
    try {
        $due = new DateTime($r['pickup_date'] . ' ' . $timeStr);
    } catch (Exception $e) { continue; }

    // Only process if within horizon or already overdue
    $diffMin = ($due->getTimestamp() - $now->getTimestamp()) / 60;
    if ($diffMin > HORIZON_HOURS * 60) continue;   // too far ahead — skip

    $urgency = $diffMin < 0
        ? '⚠️ OVERDUE by ' . abs(round($diffMin)) . ' min'
        : ($diffMin <= 30 ? '🚨 URGENT — ' . round($diffMin) . ' min left' : '🔔 Due in ' . round($diffMin) . ' min');

    // Build messages
    $staffMsg = buildMsg($r, $urgency, 'staff');
    $custMsg  = buildMsg($r, $urgency, 'customer');

    $staffPhone = normalisePhone($r['customer_phone'] ?? '');   // use customer phone for staff too unless staff phone column exists
    $custPhone  = normalisePhone($r['customer_phone'] ?? '');

    $staffWaLink = waLink($staffPhone, $staffMsg);
    $custWaLink  = waLink($custPhone, $custMsg);

    // Log to reminder_send_log
    logSend($db, $r['id'], 'whatsapp', $r['staff_name'], $staffPhone, $staffMsg);
    if ($custPhone !== $staffPhone) {
        logSend($db, $r['id'], 'whatsapp_customer', $r['customer_name'] ?? '', $custPhone, $custMsg);
    }

    // Optional: push via WhatsApp Business Cloud API
    if (!empty(WA_API_TOKEN) && !empty(WA_PHONE_ID) && $staffPhone) {
        sendWaCloudApi($staffPhone, $staffMsg);
    }

    // Mark reminder_sent = 1
    $db->prepare("UPDATE vehicle_pickup_reminders SET reminder_sent = 1 WHERE id = ?")
       ->execute([$r['id']]);

    $processed++;
    $report[] = [
        'reminder'    => $r['reminder_number'] ?? 'N/A',
        'customer'    => $r['customer_name'] ?? 'N/A',
        'vehicle'     => $r['vehicle_reg'] ?? 'N/A',
        'due'         => $due->format('D d M H:i'),
        'staff'       => $r['staff_name'],
        'urgency'     => $urgency,
        'staff_wa'    => $staffWaLink,
        'customer_wa' => $custWaLink,
    ];

    log_info("Processed reminder #{$r['id']} — {$r['customer_name']} — {$urgency}");
}

// ── Send admin summary email ───────────────────────────────────────────
if ($processed > 0) {
    sendAdminSummary($report, $processed);
}

log_info("CRON complete — {$processed} reminder(s) processed.");
exit(0);


// ══════════════════════════════════════════════════════════════════════
//  FUNCTIONS
// ══════════════════════════════════════════════════════════════════════

function buildMsg(array $r, string $urgency, string $type): string {
    $due = $r['pickup_date'] . ' ' . substr($r['pickup_time'] ?? '08:00', 0, 5);
    try {
        $dueStr = (new DateTime($due))->format('D d M Y h:i A');
    } catch (Exception $e) { $dueStr = $due; }

    if ($type === 'staff') {
        $addr = !empty($r['pickup_address']) ? "\n📍 Address: {$r['pickup_address']}" : '';
        $lm   = !empty($r['pickup_location_details']) ? "\n🗺️ Landmark: {$r['pickup_location_details']}" : '';
        return "🔧 *" . WORKSHOP_NAME . " — Pickup Reminder*\n"
             . "{$urgency}\n\n"
             . "📋 Ref: {$r['reminder_number']}\n"
             . "👤 Customer: {$r['customer_name']}\n"
             . "📞 Phone: {$r['customer_phone']}\n"
             . "🚗 Vehicle: {$r['vehicle_reg']} {$r['vehicle_make']} {$r['vehicle_model']}\n"
             . "📦 Type: " . ucfirst($r['pickup_type'] ?? 'workshop') . "{$addr}{$lm}\n"
             . "🕐 Due: {$dueStr}\n\n"
             . "Please confirm departure time.\n"
             . "Reply with your location if running late.";
    } else {
        return "🚗 *" . WORKSHOP_NAME . "*\n\n"
             . "Dear {$r['customer_name']},\n\n"
             . "Your vehicle *{$r['vehicle_reg']}* is scheduled for pickup on *{$dueStr}*.\n\n"
             . "Our driver will be with you shortly. Please ensure the vehicle is accessible.\n\n"
             . "Thank you for choosing " . WORKSHOP_NAME . "! 🙏";
    }
}

function waLink(string $phone, string $msg): string {
    if (empty($phone)) return '';
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
}

function normalisePhone(string $raw): string {
    $d = preg_replace('/[^0-9]/', '', $raw);
    if (str_starts_with($d, '0')) $d = '256' . substr($d, 1);
    elseif (!str_starts_with($d, '256')) $d = '256' . $d;
    return strlen($d) >= 11 ? $d : '';
}

function logSend(PDO $db, int $remId, string $channel, string $name, string $phone, string $msg): void {
    try {
        $db->prepare("INSERT INTO reminder_send_log (reminder_id,channel,recipient_name,recipient_phone,message,auto_sent) VALUES (?,?,?,?,?,1)")
           ->execute([$remId, $channel, $name, $phone, $msg]);
    } catch (PDOException $e) { /* non-fatal */ }
}

function sendWaCloudApi(string $phone, string $msg): void {
    // WhatsApp Business Cloud API (Meta) — requires token & phone_id
    $url  = 'https://graph.facebook.com/v18.0/' . WA_PHONE_ID . '/messages';
    $body = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'text',
        'text'              => ['preview_url' => false, 'body' => $msg],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . WA_API_TOKEN . "\r\n",
        'content' => $body,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}

function sendAdminSummary(array $report, int $count): void {
    $lines = array_map(function($r) {
        return "• [{$r['reminder']}] {$r['customer']} | {$r['vehicle']} | {$r['due']} | {$r['urgency']}\n"
             . "  Staff: {$r['staff']}\n"
             . "  Staff WA:    {$r['staff_wa']}\n"
             . "  Customer WA: {$r['customer_wa']}\n";
    }, $report);

    $body = WORKSHOP_NAME . " — Auto Reminder Cron Report\n"
          . date('D d M Y H:i:s') . "\n"
          . str_repeat('=', 50) . "\n\n"
          . "{$count} reminder(s) sent this run:\n\n"
          . implode("\n", $lines)
          . "\n" . str_repeat('-', 50) . "\n"
          . "Auto-generated by Savant Motors AI Pickup Agent";

    @mail(
        ADMIN_EMAIL,
        "🚗 [Savant] Auto Reminder: {$count} pickup(s) notified",
        $body,
        "From: noreply@savantmotors.ug\r\nX-Mailer: SavantMotors-Cron/1.0"
    );
}

function log_info(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}
