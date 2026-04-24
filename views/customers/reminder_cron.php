<?php
/**
 * reminder_cron.php  –  Savant Motors Scheduled Reminder Dispatcher
 * ==================================================================
 * Run this every minute via cron to fire scheduled reminders automatically.
 *
 * CRON SETUP (add to server crontab via: crontab -e)
 * ---------------------------------------------------
 *   * * * * * php /var/www/html/savant/reminder_cron.php >> /var/log/savant_reminders.log 2>&1
 *
 * Replace the path above with the actual path to this file on your server.
 *
 * HOW IT WORKS
 * ------------
 * 1. Finds all reminders with status='scheduled' where scheduled_at <= NOW()
 * 2. For each one, fetches the customer's phone and email from the DB
 * 3. Calls sendReminder() from send_reminder.php to dispatch via
 *    WhatsApp / SMS / Email
 * 4. Marks successful ones as 'sent', failed ones as 'failed'
 * 5. Logs every action so you can audit what was sent
 */

// ─── Bootstrap ───────────────────────────────────────────────────────────────
define('CRON_MODE', true);
chdir(__DIR__);

require_once __DIR__ . '/send_reminder.php';   // sendReminder() lives here

// ─── Database connection ──────────────────────────────────────────────────────
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    cronLog('ERROR: Cannot connect to DB – ' . $e->getMessage());
    exit(1);
}

// ─── Fetch due reminders ──────────────────────────────────────────────────────
$due = $conn->query("
    SELECT r.*, c.full_name, c.telephone, c.email
    FROM   customer_service_reminders r
    JOIN   customers c ON r.customer_id = c.id
    WHERE  r.status      = 'scheduled'
      AND  r.scheduled_at <= NOW()
    ORDER  BY r.scheduled_at ASC
    LIMIT  50
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($due)) {
    cronLog('No scheduled reminders due.');
    exit(0);
}

cronLog(count($due) . ' reminder(s) due for dispatch.');

// ─── Dispatch each reminder ───────────────────────────────────────────────────
$updateSent   = $conn->prepare("UPDATE customer_service_reminders SET status='sent',   sent_at=NOW() WHERE id=?");
$updateFailed = $conn->prepare("UPDATE customer_service_reminders SET status='failed'              WHERE id=?");

foreach ($due as $row) {
    $channels = array_filter(array_map('trim', explode(',', $row['channel'])));

    $result = sendReminder([
        'channels' => $channels,
        'phone'    => $row['telephone'] ?? '',
        'email'    => $row['email']     ?? '',
        'name'     => $row['full_name'] ?? 'Customer',
        'subject'  => $row['subject']  ?? 'Service Reminder – Savant Motors',
        'message'  => $row['message'],
    ]);

    if (!empty($result['sent'])) {
        $updateSent->execute([$row['id']]);
        cronLog(sprintf(
            'OK  reminder #%d → %s (%s) via %s',
            $row['id'],
            $row['full_name'],
            implode(' / ', array_filter([$row['telephone'], $row['email']])),
            implode(', ', $result['sent'])
        ));
    } else {
        $updateFailed->execute([$row['id']]);
        cronLog(sprintf(
            'FAIL reminder #%d → %s – channels: %s',
            $row['id'],
            $row['full_name'],
            implode(', ', $result['failed'])
        ));
    }
}

cronLog('Done.');
exit(0);

// ─── Helpers ─────────────────────────────────────────────────────────────────
function cronLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}
