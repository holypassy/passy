<?php
/**
 * send_reminder.php  –  Savant Motors Reminder Dispatcher
 * =========================================================
 * Sends reminders via WhatsApp, SMS (Twilio) and/or Email (PHPMailer/SMTP).
 *
 * SETUP INSTRUCTIONS
 * ------------------
 * 1. Install dependencies once:
 *      composer require twilio/sdk phpmailer/phpmailer
 *
 * 2. Fill in the CONFIG section below with your real credentials.
 *
 * 3. For WhatsApp: enable the Twilio WhatsApp Sandbox (or a production sender)
 *    in your Twilio console, and have customers opt-in first.
 *
 * 4. Point the AJAX handler in index.php to call sendReminder() after
 *    inserting the DB record (see the patch instructions at bottom of this file).
 *
 * 5. For scheduled reminders, add a cron job that calls reminder_cron.php every
 *    minute (see reminder_cron.php).
 */

// ─── Load Composer autoloader ────────────────────────────────────────────────
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    error_log('[Reminder] Composer autoload not found. Run: composer require twilio/sdk phpmailer/phpmailer');
    // Return gracefully so the page still works even without the libraries
    function sendReminder(array $cfg): array { return ['sent'=>[],'failed'=>['autoload missing – run composer']]; }
    return;
}
require_once $autoload;

use Twilio\Rest\Client as TwilioClient;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

// ════════════════════════════════════════════════════════════════════════════
//  ★  CONFIGURATION  ★  – edit this section with your real credentials
// ════════════════════════════════════════════════════════════════════════════
define('TWILIO_ACCOUNT_SID',  getenv('TWILIO_SID')   ?: 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('TWILIO_AUTH_TOKEN',   getenv('TWILIO_TOKEN')  ?: 'your_twilio_auth_token');
// Your Twilio SMS sender number (E.164 format, e.g. +256700000000)
define('TWILIO_SMS_FROM',     getenv('TWILIO_SMS_FROM')  ?: '+15005550006');
// WhatsApp sender: use "whatsapp:+14155238886" for the sandbox,
// or your approved WhatsApp Business number
define('TWILIO_WA_FROM',      getenv('TWILIO_WA_FROM')   ?: 'whatsapp:+14155238886');

// SMTP / Email settings
define('SMTP_HOST',     getenv('SMTP_HOST')     ?: 'smtp.gmail.com');
define('SMTP_PORT',     (int)(getenv('SMTP_PORT')    ?: 587));
define('SMTP_USER',     getenv('SMTP_USER')     ?: 'your@gmail.com');
define('SMTP_PASS',     getenv('SMTP_PASS')     ?: 'your_app_password');
define('SMTP_FROM',     getenv('SMTP_FROM')     ?: 'your@gmail.com');
define('SMTP_FROM_NAME',getenv('SMTP_FROM_NAME')?: 'Savant Motors');
// ════════════════════════════════════════════════════════════════════════════

/**
 * Main dispatcher function.
 *
 * @param array $cfg {
 *   channels  string[]  e.g. ['whatsapp','sms','email']
 *   phone     string    customer phone in E.164 or local format (+256...)
 *   email     string    customer email address
 *   name      string    customer full name
 *   subject   string    email subject line
 *   message   string    plain-text message body
 * }
 * @return array { sent: string[], failed: string[] }
 */
function sendReminder(array $cfg): array
{
    $sent   = [];
    $failed = [];

    $channels = $cfg['channels'] ?? [];
    $phone    = normalisePhone($cfg['phone'] ?? '');
    $email    = trim($cfg['email'] ?? '');
    $name     = $cfg['name']    ?? 'Valued Customer';
    $subject  = $cfg['subject'] ?? 'Service Reminder – Savant Motors';
    $message  = $cfg['message'] ?? '';

    // Personalise the message
    $message = str_replace('{name}', $name, $message);

    foreach ($channels as $ch) {
        switch ($ch) {
            case 'whatsapp':
                if (!$phone) { $failed[] = 'whatsapp (no phone)'; break; }
                $r = sendWhatsApp($phone, $message);
                $r ? $sent[] = 'whatsapp' : $failed[] = 'whatsapp';
                break;

            case 'sms':
                if (!$phone) { $failed[] = 'sms (no phone)'; break; }
                $r = sendSMS($phone, $message);
                $r ? $sent[] = 'sms' : $failed[] = 'sms';
                break;

            case 'email':
                if (!$email) { $failed[] = 'email (no address)'; break; }
                $r = sendEmail($email, $name, $subject, $message);
                $r ? $sent[] = 'email' : $failed[] = 'email';
                break;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

// ─── WhatsApp via Twilio ─────────────────────────────────────────────────────
function sendWhatsApp(string $phone, string $message): bool
{
    try {
        $twilio = new TwilioClient(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
        $twilio->messages->create(
            'whatsapp:' . $phone,
            ['from' => TWILIO_WA_FROM, 'body' => $message]
        );
        return true;
    } catch (\Exception $e) {
        error_log('[Reminder:WhatsApp] ' . $e->getMessage());
        return false;
    }
}

// ─── SMS via Twilio ──────────────────────────────────────────────────────────
function sendSMS(string $phone, string $message): bool
{
    try {
        $twilio = new TwilioClient(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
        $twilio->messages->create(
            $phone,
            ['from' => TWILIO_SMS_FROM, 'body' => $message]
        );
        return true;
    } catch (\Exception $e) {
        error_log('[Reminder:SMS] ' . $e->getMessage());
        return false;
    }
}

// ─── Email via PHPMailer ─────────────────────────────────────────────────────
function sendEmail(string $toEmail, string $toName, string $subject, string $message): bool
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;

        // HTML body (wraps plain text in a nice template)
        $htmlBody = buildEmailHtml($toName, $message, $subject);
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $message; // plain-text fallback

        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('[Reminder:Email] ' . $e->getMessage());
        return false;
    }
}

// ─── Email HTML template ─────────────────────────────────────────────────────
function buildEmailHtml(string $name, string $message, string $subject): string
{
    $lines   = nl2br(htmlspecialchars($message));
    $year    = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0;">
    <tr><td align="center">
      <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#2563eb,#7c3aed);padding:28px 36px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">🔧 SAVANT MOTORS</div>
            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:4px;">Quality Service You Can Trust</div>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:32px 36px;font-size:14px;color:#334155;line-height:1.7;">
            {$lines}
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;padding:20px 36px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;">
            &copy; {$year} Savant Motors. All rights reserved.<br>
            You are receiving this because you are a valued customer.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ─── Phone normaliser ────────────────────────────────────────────────────────
/**
 * Converts a local Ugandan number to E.164 (+256...).
 * Adjust the country code / rules to match your customers.
 */
function normalisePhone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);
    if (!$digits) return '';

    // Already international (starts with country code digits)
    if (str_starts_with($raw, '+')) return '+' . $digits;

    // Local Ugandan numbers: 07x / 03x → +2567x / +2563x
    if (strlen($digits) === 10 && ($digits[0] === '0')) {
        return '+256' . substr($digits, 1);
    }

    // 9-digit without leading 0
    if (strlen($digits) === 9) {
        return '+256' . $digits;
    }

    return '+' . $digits; // best effort
}
