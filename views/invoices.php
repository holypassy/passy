<?php
// invoices.php - Complete Invoice Management with Receipt Generation & Accounting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ========== ENSURE TABLE STRUCTURE ==========
    $conn->exec("ALTER TABLE invoices DROP COLUMN IF EXISTS updated_at");
    $conn->exec("ALTER TABLE invoices DROP COLUMN IF EXISTS due_date");
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            quotation_id INT,
            customer_id INT NOT NULL,
            invoice_date DATE NOT NULL,
            subtotal DECIMAL(15,2) DEFAULT 0,
            discount DECIMAL(15,2) DEFAULT 0,
            tax DECIMAL(15,2) DEFAULT 0,
            total_amount DECIMAL(15,2) DEFAULT 0,
            status ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
            payment_status ENUM('unpaid','partial','paid') DEFAULT 'unpaid',
            amount_paid DECIMAL(15,2) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id),
            FOREIGN KEY (quotation_id) REFERENCES quotations(id)
        )
    ");
    
    $cols = [
        'vehicle_reg' => "ALTER TABLE invoices ADD COLUMN vehicle_reg VARCHAR(50) AFTER customer_id",
        'vehicle_model' => "ALTER TABLE invoices ADD COLUMN vehicle_model VARCHAR(100) AFTER vehicle_reg",
        'odometer_reading' => "ALTER TABLE invoices ADD COLUMN odometer_reading VARCHAR(20) AFTER vehicle_model",
        'notes' => "ALTER TABLE invoices ADD COLUMN notes TEXT AFTER amount_paid",
        'created_by' => "ALTER TABLE invoices ADD COLUMN created_by INT AFTER notes",
        'payment_method' => "ALTER TABLE invoices ADD COLUMN payment_method VARCHAR(50) AFTER payment_status",
        'payment_date' => "ALTER TABLE invoices ADD COLUMN payment_date DATETIME AFTER payment_method"
    ];
    foreach ($cols as $col => $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] != 1060) throw $e;
        }
    }
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            description VARCHAR(200) NOT NULL,
            quantity INT DEFAULT 1,
            unit_price DECIMAL(15,2) DEFAULT 0,
            total_price DECIMAL(15,2) DEFAULT 0,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        )
    ");
    
    // ========== ACCOUNTING TABLES ==========
    $conn->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_code VARCHAR(20) NOT NULL UNIQUE,
            account_name VARCHAR(100) NOT NULL,
            account_type ENUM('asset','liability','equity','revenue','expense') DEFAULT 'asset',
            balance DECIMAL(15,2) DEFAULT 0.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS account_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME NOT NULL,
            description VARCHAR(255),
            account_id INT NOT NULL,
            debit DECIMAL(15,2) DEFAULT 0.00,
            credit DECIMAL(15,2) DEFAULT 0.00,
            reference_type VARCHAR(50),
            reference_id INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES accounts(id)
        )
    ");
    // Insert default asset accounts if missing
    $conn->exec("
        INSERT IGNORE INTO accounts (account_code, account_name, account_type, balance) VALUES
        ('1010', 'Cash on Hand', 'asset', 0),
        ('1020', 'Mobile Money', 'asset', 0),
        ('1030', 'Bank Account', 'asset', 0),
        ('1040', 'Cheque Account', 'asset', 0),
        ('1200', 'Accounts Receivable', 'asset', 0)
    ");

    // Ensure debtors table exists (shared with debtors.php)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS debtors (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            customer_id    INT,
            customer_name  VARCHAR(120) NOT NULL,
            reference_type VARCHAR(30)  DEFAULT 'manual',
            reference_id   INT,
            reference_no   VARCHAR(50),
            amount_owed    DECIMAL(15,2) DEFAULT 0,
            amount_paid    DECIMAL(15,2) DEFAULT 0,
            balance        DECIMAL(15,2) DEFAULT 0,
            due_date       DATE,
            status         ENUM('open','partial','settled') DEFAULT 'open',
            notes          TEXT,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /**
     * Upsert a debtor record that mirrors an invoice.
     * - Creates the debtor row when an invoice is first created (unpaid/partial)
     * - Updates amount_paid / balance / status on every payment
     * - Marks settled when fully paid
     */
    function syncInvoiceToDebtors(PDO $db, int $invoiceId): void {
        $stmt = $db->prepare("
            SELECT i.id, i.invoice_number, i.customer_id, i.total_amount,
                   i.amount_paid, i.payment_status, i.invoice_date,
                   c.full_name AS customer_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return;

        // Only track unpaid / partially paid invoices as debtors
        $balance     = (float)$inv['total_amount'] - (float)$inv['amount_paid'];
        $debtStatus  = $inv['payment_status'] === 'paid' ? 'settled'
                     : ($inv['amount_paid'] > 0 ? 'partial' : 'open');

        // Check if a debtor row already exists for this invoice
        $existing = $db->prepare("SELECT id FROM debtors WHERE reference_type='invoice' AND reference_id=?");
        $existing->execute([$invoiceId]);
        $debtorRow = $existing->fetch(PDO::FETCH_ASSOC);

        if ($debtorRow) {
            // Update existing row
            $db->prepare("
                UPDATE debtors
                SET amount_paid = ?, balance = ?, status = ?, customer_name = ?
                WHERE id = ?
            ")->execute([
                $inv['amount_paid'],
                $balance,
                $debtStatus,
                $inv['customer_name'] ?? $inv['customer_id'],
                $debtorRow['id'],
            ]);
        } else {
            // Insert new debtor record linked to this invoice
            $db->prepare("
                INSERT INTO debtors
                    (customer_id, customer_name, reference_type, reference_id, reference_no,
                     amount_owed, amount_paid, balance, due_date, status, notes)
                VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $inv['customer_id'],
                $inv['customer_name'] ?? $inv['customer_id'],
                $invoiceId,
                $inv['invoice_number'],
                $inv['total_amount'],
                $inv['amount_paid'],
                $balance,
                null,  // due_date – invoices don't have one; can be set manually in debtors.php
                $debtStatus,
                'Auto-linked from Invoice ' . $inv['invoice_number'],
            ]);
        }
    }
    
    // Fetch invoices
    $stmt = $conn->query("
        SELECT i.id, i.invoice_number, i.quotation_id, i.customer_id, i.invoice_date,
               i.vehicle_reg, i.vehicle_model, i.odometer_reading,
               i.subtotal, i.discount, i.tax, i.total_amount,
               i.status, i.payment_status, i.amount_paid, i.payment_method, i.payment_date,
               i.notes, i.created_by, i.created_at,
               c.full_name as customer_name, c.telephone, c.email, c.address, c.customer_tier
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        ORDER BY i.created_at DESC
    ");
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $quotations = $conn->query("
        SELECT q.id, q.quotation_number, q.customer_id, q.quotation_date, q.status, q.total_amount,
               c.full_name as customer_name, c.telephone, c.email, c.address
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        WHERE q.status != 'invoiced'
        ORDER BY q.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// ========== AJAX HANDLER FOR VIEW INVOICE DETAILS ==========
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    try {
        $stmt = $conn->prepare("
            SELECT i.*, c.full_name as customer_name, c.telephone, c.email, c.address, c.customer_tier
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            echo json_encode(['error' => 'Invoice not found']);
            exit;
        }
        $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'invoice' => $invoice, 'items' => $items]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ========== NEW ACCOUNTING AJAX HANDLERS ==========
if (isset($_GET['action']) && $_GET['action'] === 'get_accounts') {
    header('Content-Type: application/json');
    try {
        $stmt = $conn->query("SELECT id, account_code, account_name, account_type, balance FROM accounts ORDER BY account_code");
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'accounts' => $accounts]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_ledger') {
    header('Content-Type: application/json');
    try {
        $stmt = $conn->query("
            SELECT l.id, l.transaction_date, l.description, a.account_name, a.account_code, l.debit, l.credit, l.reference_type, l.reference_id
            FROM account_ledger l
            JOIN accounts a ON l.account_id = a.id
            ORDER BY l.transaction_date DESC
            LIMIT 50
        ");
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'entries' => $entries]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  AI DEBT COLLECTION AGENT — WhatsApp config & helpers
// ═══════════════════════════════════════════════════════════════════════════

// ── WhatsApp / CallMeBot config (update with your details) ──────────────
define('WA_API_URL',    'https://api.callmebot.com/whatsapp.php');
define('WA_ADMIN_PHONE','256700000000');   // ← Admin WhatsApp number (no +)
define('WA_APIKEY',     'YOUR_CALLMEBOT_APIKEY'); // ← CallMeBot API key

function sendWhatsAppMsg(string $phone, string $message, string $apiKey = WA_APIKEY): bool {
    // Strip non-digits, remove leading +
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (!$phone) return false;
    $url = WA_API_URL . '?phone=' . urlencode($phone)
         . '&text=' . urlencode($message)
         . '&apikey=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300);
}

// ── AJAX: fetch overdue debtor invoices (>15 days unpaid) ───────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_overdue_debtors') {
    header('Content-Type: application/json');
    $days = max(1, (int)($_GET['days'] ?? 15));
    try {
        $rows = $conn->prepare("
            SELECT
                i.id,
                i.invoice_number,
                i.invoice_date,
                i.total_amount,
                i.amount_paid,
                (i.total_amount - i.amount_paid) AS balance_due,
                i.payment_status,
                DATEDIFF(NOW(), i.invoice_date) AS days_overdue,
                c.full_name  AS customer_name,
                c.telephone  AS customer_phone,
                c.email      AS customer_email,
                i.vehicle_reg,
                i.vehicle_model
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            WHERE i.payment_status IN ('unpaid','partial')
              AND DATEDIFF(NOW(), i.invoice_date) >= ?
            ORDER BY days_overdue DESC
        ");
        $rows->execute([$days]);
        $debtors = $rows->fetchAll(PDO::FETCH_ASSOC);

        // Summary stats
        $totalOwed   = array_sum(array_column($debtors, 'balance_due'));
        $criticalCnt = count(array_filter($debtors, fn($d) => $d['days_overdue'] >= 30));
        $partialCnt  = count(array_filter($debtors, fn($d) => $d['payment_status'] === 'partial'));

        echo json_encode([
            'success'      => true,
            'debtors'      => $debtors,
            'count'        => count($debtors),
            'total_owed'   => $totalOwed,
            'critical_cnt' => $criticalCnt,
            'partial_cnt'  => $partialCnt,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: send WhatsApp reminder to a single customer ───────────────────
if (isset($_POST['action']) && $_POST['action'] === 'send_wa_customer') {
    header('Content-Type: application/json');
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    try {
        $stmt = $conn->prepare("
            SELECT i.invoice_number, i.invoice_date, i.total_amount,
                   i.amount_paid, i.payment_status, i.vehicle_reg,
                   (i.total_amount - i.amount_paid) AS balance_due,
                   DATEDIFF(NOW(), i.invoice_date)  AS days_overdue,
                   c.full_name AS customer_name, c.telephone
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) throw new Exception('Invoice not found');
        if (!$inv['telephone']) throw new Exception('No phone number on record for this customer');

        $balance    = number_format((float)$inv['balance_due']);
        $total      = number_format((float)$inv['total_amount']);
        $paid       = number_format((float)$inv['amount_paid']);
        $daysOver   = (int)$inv['days_overdue'];
        $vehicle    = $inv['vehicle_reg'] ? " ({$inv['vehicle_reg']})" : '';
        $isPart     = $inv['payment_status'] === 'partial';

        if ($isPart) {
            $message = "Dear {$inv['customer_name']},\n\n"
                . "This is a friendly reminder from *Savant Motors* regarding Invoice *{$inv['invoice_number']}*{$vehicle}.\n\n"
                . "📌 Invoice Total: UGX {$total}\n"
                . "✅ Amount Paid: UGX {$paid}\n"
                . "⚠️ *Outstanding Balance: UGX {$balance}*\n\n"
                . "Your invoice is {$daysOver} day(s) overdue. Please settle the remaining balance at your earliest convenience.\n\n"
                . "Thank you for choosing Savant Motors! 🚗";
        } else {
            $message = "Dear {$inv['customer_name']},\n\n"
                . "This is a payment reminder from *Savant Motors* for Invoice *{$inv['invoice_number']}*{$vehicle}.\n\n"
                . "💰 *Amount Due: UGX {$balance}*\n"
                . "📅 Invoice is {$daysOver} day(s) overdue.\n\n"
                . "Kindly make payment via:\n"
                . "🏦 ABSA A/C: 6007717553\n"
                . "📱 MoMo Pay: 915573\n\n"
                . "Thank you for your continued business! 🙏";
        }

        $sent = sendWhatsAppMsg($inv['telephone'], $message);

        // Log reminder in DB
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_reminder_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL, customer_name VARCHAR(150),
            phone VARCHAR(50), message TEXT, sent TINYINT(1) DEFAULT 0,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, sent_by VARCHAR(100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->prepare("INSERT INTO wa_reminder_log (invoice_id,customer_name,phone,message,sent,sent_by) VALUES (?,?,?,?,?,?)")
             ->execute([$invoiceId, $inv['customer_name'], $inv['telephone'], $message, (int)$sent, $user_full_name]);

        echo json_encode(['success' => true, 'sent' => $sent, 'message_preview' => $message, 'phone' => $inv['telephone']]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: send bulk WhatsApp reminders to all overdue debtors ───────────
if (isset($_POST['action']) && $_POST['action'] === 'send_wa_bulk') {
    header('Content-Type: application/json');
    $days = max(1, (int)($_POST['days'] ?? 15));
    try {
        $rows = $conn->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount,
                   i.amount_paid, i.payment_status, i.vehicle_reg,
                   (i.total_amount - i.amount_paid) AS balance_due,
                   DATEDIFF(NOW(), i.invoice_date) AS days_overdue,
                   c.full_name AS customer_name, c.telephone
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            WHERE i.payment_status IN ('unpaid','partial')
              AND DATEDIFF(NOW(), i.invoice_date) >= ?
              AND c.telephone IS NOT NULL AND c.telephone != ''
            ORDER BY days_overdue DESC
        ");
        $rows->execute([$days]);
        $debtors = $rows->fetchAll(PDO::FETCH_ASSOC);

        $conn->exec("CREATE TABLE IF NOT EXISTS wa_reminder_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL, customer_name VARCHAR(150),
            phone VARCHAR(50), message TEXT, sent TINYINT(1) DEFAULT 0,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, sent_by VARCHAR(100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $sentCount = 0; $failCount = 0; $results = [];
        foreach ($debtors as $inv) {
            $balance  = number_format((float)$inv['balance_due']);
            $total    = number_format((float)$inv['total_amount']);
            $paid     = number_format((float)$inv['amount_paid']);
            $daysOver = (int)$inv['days_overdue'];
            $vehicle  = $inv['vehicle_reg'] ? " ({$inv['vehicle_reg']})" : '';
            $isPart   = $inv['payment_status'] === 'partial';

            $message = $isPart
                ? "Dear {$inv['customer_name']},\n\nReminder from *Savant Motors* — Invoice *{$inv['invoice_number']}*{$vehicle}.\n✅ Paid: UGX {$paid}\n⚠️ *Balance: UGX {$balance}* ({$daysOver} days overdue)\n\nPlease settle at your earliest. Thank you! 🙏"
                : "Dear {$inv['customer_name']},\n\nPayment reminder from *Savant Motors* — Invoice *{$inv['invoice_number']}*{$vehicle}.\n💰 *Due: UGX {$balance}* ({$daysOver} days overdue)\n\nPay via ABSA 6007717553 or MoMo 915573. Thank you! 🚗";

            $sent = sendWhatsAppMsg($inv['telephone'], $message);
            $conn->prepare("INSERT INTO wa_reminder_log (invoice_id,customer_name,phone,message,sent,sent_by) VALUES (?,?,?,?,?,?)")
                 ->execute([$inv['id'], $inv['customer_name'], $inv['telephone'], $message, (int)$sent, $user_full_name]);

            $sent ? $sentCount++ : $failCount++;
            $results[] = ['name' => $inv['customer_name'], 'phone' => $inv['telephone'], 'sent' => $sent, 'balance' => $balance];
            usleep(500000); // 0.5s between messages to avoid API rate limits
        }

        // Send admin summary
        if ($sentCount > 0 || $failCount > 0) {
            $totalOwed = number_format(array_sum(array_column($debtors, 'balance_due')));
            $summary   = "📊 *Savant Motors — Debt Collection Report*\n"
                . "Date: " . date('d M Y, H:i') . "\n\n"
                . "🔔 Overdue invoices (>{$days} days): " . count($debtors) . "\n"
                . "💰 Total Outstanding: UGX {$totalOwed}\n"
                . "✅ Reminders sent: {$sentCount}\n"
                . "❌ Failed: {$failCount}\n\n"
                . "Debtors:\n";
            foreach ($debtors as $d) {
                $summary .= "• {$d['customer_name']}: UGX " . number_format($d['balance_due']) . " ({$d['days_overdue']} days)\n";
            }
            sendWhatsAppMsg(WA_ADMIN_PHONE, $summary);
        }

        echo json_encode(['success' => true, 'sent' => $sentCount, 'failed' => $failCount, 'total' => count($debtors), 'results' => $results]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: send admin-only summary (no customer messages) ────────────────
if (isset($_POST['action']) && $_POST['action'] === 'send_wa_admin_summary') {
    header('Content-Type: application/json');
    $days = max(1, (int)($_POST['days'] ?? 15));
    try {
        $rows = $conn->prepare("
            SELECT i.invoice_number, i.payment_status,
                   (i.total_amount - i.amount_paid) AS balance_due,
                   DATEDIFF(NOW(), i.invoice_date) AS days_overdue,
                   c.full_name AS customer_name, c.telephone
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            WHERE i.payment_status IN ('unpaid','partial')
              AND DATEDIFF(NOW(), i.invoice_date) >= ?
            ORDER BY days_overdue DESC
        ");
        $rows->execute([$days]);
        $debtors = $rows->fetchAll(PDO::FETCH_ASSOC);

        $totalOwed  = array_sum(array_column($debtors, 'balance_due'));
        $partialSum = array_sum(array_map(fn($d) => $d['payment_status'] === 'partial' ? $d['balance_due'] : 0, $debtors));

        $msg = "📋 *SAVANT MOTORS — Outstanding Invoices Report*\n"
             . "Generated: " . date('d M Y, H:i') . " by {$user_full_name}\n"
             . str_repeat("─", 30) . "\n\n"
             . "📌 Overdue (>{$days} days): *" . count($debtors) . " invoice(s)*\n"
             . "💰 Total Outstanding: *UGX " . number_format($totalOwed) . "*\n"
             . "⚡ Partial Balances: *UGX " . number_format($partialSum) . "*\n\n"
             . "━━ DEBTOR LIST ━━\n";

        foreach ($debtors as $i => $d) {
            $flag = $d['days_overdue'] >= 30 ? '🔴' : ($d['days_overdue'] >= 15 ? '🟡' : '🟢');
            $type = $d['payment_status'] === 'partial' ? '[PARTIAL]' : '[UNPAID]';
            $msg .= ($i+1) . ". {$flag} {$d['customer_name']} {$type}\n"
                  . "   Inv: {$d['invoice_number']} | Due: UGX " . number_format($d['balance_due'])
                  . " | {$d['days_overdue']}d\n";
        }

        $sent = sendWhatsAppMsg(WA_ADMIN_PHONE, $msg);
        echo json_encode(['success' => true, 'sent' => $sent, 'count' => count($debtors), 'total_owed' => $totalOwed, 'preview' => $msg]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: reminder history log ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_reminder_log') {
    header('Content-Type: application/json');
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS wa_reminder_log (
            id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL,
            customer_name VARCHAR(150), phone VARCHAR(50), message TEXT,
            sent TINYINT(1) DEFAULT 0, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, sent_by VARCHAR(100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log = $conn->query("SELECT * FROM wa_reminder_log ORDER BY sent_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'log' => $log]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'sync_invoices_to_debtors') {
    header('Content-Type: application/json');
    try {
        $rows = $conn->query("
            SELECT id FROM invoices
            WHERE payment_status IN ('unpaid','partial')
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            syncInvoiceToDebtors($conn, (int)$id);
        }
        echo json_encode(['success' => true, 'synced' => count($rows)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========== FIXED: CONVERT QUOTATION TO INVOICE WITH ALL ITEMS ==========
if (isset($_POST['convert_quotation'])) {
    try {
        $conn->beginTransaction();
        
        // Get quotation header data
        $quoteStmt = $conn->prepare("
            SELECT q.* 
            FROM quotations q
            WHERE q.id = ?
        ");
        $quoteStmt->execute([$_POST['quotation_id']]);
        $quotation = $quoteStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$quotation) {
            throw new Exception("Quotation not found");
        }
        
        // Get quotation items
        $itemsStmt = $conn->prepare("
            SELECT qi.* 
            FROM quotation_items qi
            WHERE qi.quotation_id = ?
        ");
        $itemsStmt->execute([$_POST['quotation_id']]);
        $quotationItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($quotationItems)) {
            throw new Exception("Quotation has no items to convert");
        }
        
        // Generate invoice number
        $lastInv = $conn->query("SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($lastInv) {
            $lastNum = intval(substr($lastInv['invoice_number'], -4));
            $nextNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
            $invNumber = 'INV-' . date('Y') . '-' . $nextNum;
        } else {
            $invNumber = 'INV-' . date('Y') . '-0001';
        }
        
        // Insert invoice
        $stmt = $conn->prepare("
            INSERT INTO invoices (
                invoice_number, quotation_id, customer_id, invoice_date,
                vehicle_reg, vehicle_model, odometer_reading,
                subtotal, discount, tax, total_amount, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $invNumber, 
            $quotation['id'], 
            $quotation['customer_id'], 
            date('Y-m-d'),
            $quotation['vehicle_reg'] ?? null, 
            $quotation['vehicle_model'] ?? null, 
            $quotation['odo_reading'] ?? null,
            $quotation['subtotal'] ?? 0, 
            $quotation['discount'] ?? 0, 
            $quotation['tax'] ?? 0,
            $quotation['total_amount'] ?? 0, 
            $quotation['notes'] ?? null, 
            $user_id
        ]);
        
        $invoice_id = $conn->lastInsertId();
        
        // Insert all quotation items into invoice_items
        $itemStmt = $conn->prepare("
            INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total_price) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($quotationItems as $item) {
            $itemStmt->execute([
                $invoice_id, 
                $item['description'], 
                $item['quantity'], 
                $item['unit_price'], 
                $item['total_price']
            ]);
        }
        
        // Update quotation status to 'invoiced'
        $conn->prepare("UPDATE quotations SET status = 'invoiced' WHERE id = ?")->execute([$quotation['id']]);

        // === AUTO-UPDATE LINKED JOB CARD TO in_progress WHEN INVOICE IS CREATED ===
        $conn->prepare("
            UPDATE job_cards jc
            INNER JOIN quotations q ON q.job_card_id = jc.id
            SET jc.status = 'in_progress'
            WHERE q.id = ? AND jc.deleted_at IS NULL
        ")->execute([$quotation['id']]);
        
        // Sync the new invoice to debtors table automatically
        syncInvoiceToDebtors($conn, $invoice_id);
        
        $conn->commit();
        $_SESSION['success'] = "Invoice {$invNumber} created successfully with " . count($quotationItems) . " items!";
        header('Location: invoices.php');
        exit();
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error creating invoice: " . $e->getMessage();
        header('Location: invoices.php');
        exit();
    }
}
    
// ========== UPDATED PAYMENT HANDLER WITH ACCOUNTING ==========
if (isset($_POST['record_payment'])) {
    try {
        $conn->beginTransaction();
        
        $invoice_id = (int)$_POST['invoice_id'];
        $payment_amount = (float)$_POST['payment_amount'];
        $payment_method = $_POST['payment_method'];
        
        // Fetch current invoice data with row lock
        $stmt = $conn->prepare("SELECT total_amount, amount_paid FROM invoices WHERE id = ? FOR UPDATE");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new Exception("Invoice not found");
        
        $total = (float)$invoice['total_amount'];
        $already_paid = (float)$invoice['amount_paid'];
        $balance = $total - $already_paid;
        
        if ($payment_amount <= 0) throw new Exception("Payment amount must be greater than zero");
        if ($payment_amount > $balance) throw new Exception("Payment amount cannot exceed balance due (UGX " . number_format($balance) . ")");
        
        // Map payment method to account code
        $method_to_account = [
            'cash'          => '1010',
            'mobile_money'  => '1020',
            'bank_transfer' => '1030',
            'cheque'        => '1040'
        ];
        if (!isset($method_to_account[$payment_method])) {
            throw new Exception("Invalid payment method: " . $payment_method);
        }
        $asset_account_code = $method_to_account[$payment_method];
        
        // Get account IDs
        $stmt = $conn->prepare("SELECT id FROM accounts WHERE account_code = ?");
        $stmt->execute([$asset_account_code]);
        $asset_account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset_account) throw new Exception("Asset account not found for code: $asset_account_code");
        
        $stmt = $conn->prepare("SELECT id FROM accounts WHERE account_code = '1200'");
        $stmt->execute();
        $ar_account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ar_account) throw new Exception("Accounts Receivable account not found");
        
        // Update invoice payment status
        $new_paid = $already_paid + $payment_amount;
        $payment_status = $new_paid >= $total ? 'paid' : 'partial';
        $invoice_status = $new_paid >= $total ? 'paid' : $invoice['status'];
        
        $stmt = $conn->prepare("
            UPDATE invoices 
            SET amount_paid = ?, payment_status = ?, status = ?, 
                payment_method = ?, payment_date = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$new_paid, $payment_status, $invoice_status, $payment_method, $invoice_id]);
        
        // Insert accounting entries: Debit asset account, Credit Accounts Receivable
        $description = "Payment received for Invoice #" . $invoice_id . " via " . ucfirst(str_replace('_', ' ', $payment_method));
        $now = date('Y-m-d H:i:s');
        
        // Debit entry for the asset account
        $stmt = $conn->prepare("
            INSERT INTO account_ledger (transaction_date, description, account_id, debit, credit, reference_type, reference_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$now, $description, $asset_account['id'], $payment_amount, 0, 'invoice_payment', $invoice_id]);
        
        // Credit entry for Accounts Receivable
        $stmt->execute([$now, $description, $ar_account['id'], 0, $payment_amount, 'invoice_payment', $invoice_id]);
        
        // Update running balances in accounts table
        $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$payment_amount, $asset_account['id']]);
        $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")->execute([$payment_amount, $ar_account['id']]);
        
        // Keep debtors table in sync — update or settle the linked debtor record
        syncInvoiceToDebtors($conn, $invoice_id);

        // === AUTO-UPDATE LINKED JOB CARD TO completed WHEN INVOICE IS PAID OR PARTIALLY PAID ===
        if (in_array($payment_status, ['paid', 'partial'])) {
            $conn->prepare("
                UPDATE job_cards jc
                INNER JOIN quotations q ON q.job_card_id = jc.id
                INNER JOIN invoices i ON i.quotation_id = q.id
                SET jc.status = 'completed'
                WHERE i.id = ? AND jc.deleted_at IS NULL
            ")->execute([$invoice_id]);
        }

        $conn->commit();
        $_SESSION['success'] = "Payment of UGX " . number_format($payment_amount) . " recorded successfully!";
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Payment failed: " . $e->getMessage();
    }
    header('Location: invoices.php');
    exit();
}

$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$total_invoices = count($invoices);
$paid_invoices = count(array_filter($invoices, fn($i) => $i['payment_status'] == 'paid'));
$unpaid_invoices = count(array_filter($invoices, fn($i) => $i['payment_status'] == 'unpaid'));
$partial_invoices = count(array_filter($invoices, fn($i) => $i['payment_status'] == 'partial'));
$total_value = array_sum(array_column($invoices, 'total_amount'));
$collected_amount = array_sum(array_column($invoices, 'amount_paid'));
$outstanding_amount = $total_value - $collected_amount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INVOICES | Savant Motors Uganda</title>
    <link href="https://fonts.googleapis.com/css2?family=Calibri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Calibri', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f0f4fc 0%, #e2eaf5 100%);
        }
        :root {
            --primary: #2563eb;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        /* Very Light Blue Sidebar with Logo */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(145deg, #e6f2ff 0%, #cce5ff 50%, #b3d9ff 100%);
            color: #0a2b44;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 4px 0 20px rgba(0,0,0,0.06);
        }
        .sidebar-header {
            padding: 24px 24px 20px 24px;
            border-bottom: 1px solid rgba(10,43,68,0.1);
            text-align: center;
        }
        .logo-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .logo-image {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 50%;
            background: white;
            padding: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .logo-area h2 {
            font-size: 22px;
            font-weight: 800;
            margin: 0;
            color: #0a2b44;
            letter-spacing: -0.5px;
        }
        .logo-area p {
            font-size: 11px;
            opacity: 0.7;
            font-weight: 500;
            margin-top: -5px;
            color: #0a2b44;
        }
        .sidebar-menu { padding: 20px 0; }
        .sidebar-title { padding: 10px 24px; font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(10,43,68,0.6); font-weight: 800; margin-top: 8px; }
        .menu-item { 
            padding: 12px 24px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            color: #0a2b44; 
            text-decoration: none; 
            transition: all 0.2s; 
            border-left: 3px solid transparent; 
            font-size: 15px; 
            font-weight: 600; 
        }
        .menu-item i { width: 22px; font-size: 16px; color: #0a2b44; }
        .menu-item:hover, .menu-item.active { 
            background: rgba(255,255,255,0.6); 
            color: #0a2b44; 
            border-left-color: #ffb347;
        }
        .main-content { margin-left: 280px; padding: 28px 32px; min-height: 100vh; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 20px; }
        .page-title h1 { font-size: 30px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 12px; letter-spacing: -0.5px; }
        .page-title h1 i { color: var(--primary); font-size: 34px; }
        .page-title p { color: var(--gray); font-size: 15px; margin-top: 6px; }
        .btn { padding: 12px 24px; border: none; border-radius: 40px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 10px; font-family: 'Calibri', sans-serif; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; box-shadow: var(--shadow-sm); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3); }
        .btn-secondary { background: white; color: var(--gray); border: 1px solid var(--border); }
        .btn-secondary:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .btn-success { background: var(--success); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-receipt { background: #f59e0b; color: white; }
        .btn-receipt:hover { background: #d97706; transform: translateY(-2px); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 24px; margin-bottom: 32px; }
        .stat-card { background: white; border-radius: 28px; padding: 22px; border: 1px solid var(--border); transition: all 0.2s; box-shadow: var(--shadow-sm); }
        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .stat-title { font-size: 13px; font-weight: 600; color: var(--gray); text-transform: uppercase; letter-spacing: 0.8px; }
        .stat-value { font-size: 28px; font-weight: 800; color: var(--dark); letter-spacing: -0.5px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 24px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .tabs-container {
            background: white;
            border-radius: 60px;
            padding: 6px;
            margin-bottom: 28px;
            display: flex;
            gap: 8px;
            border: 1px solid var(--border);
            width: fit-content;
        }
        .tab {
            padding: 10px 28px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.2s;
        }
        .tab.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: var(--shadow-sm);
        }
        
        .filter-bar {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid var(--border);
        }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 11px; font-weight: 700; color: var(--gray); margin-bottom: 6px; text-transform: uppercase; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px 14px; border: 2px solid var(--border); border-radius: 12px; font-size: 13px; }
        
        .table-container {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--border);
            overflow-x: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 16px 16px;
            background: var(--light);
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        tr:hover { background: #fafbff; }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            min-width: 80px;
        }
        .status-badge.paid { background: #dcfce7; color: #166534; }
        .status-badge.unpaid { background: #fee2e2; color: #991b1b; }
        .status-badge.partial { background: #fed7aa; color: #9a3412; }
        .status-badge.draft { background: #e2e8f0; color: #475569; }
        .status-badge.sent { background: #dbeafe; color: #1e40af; }
        
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        .action-btn.view { background: #e2e8f0; color: var(--gray); }
        .action-btn.view:hover { background: var(--primary); color: white; }
        .action-btn.print { background: #dcfce7; color: var(--success); }
        .action-btn.print:hover { background: var(--success); color: white; }
        .action-btn.payment { background: #dbeafe; color: var(--primary); }
        .action-btn.payment:hover { background: var(--primary); color: white; }
        .action-btn.receipt { background: #fed7aa; color: #d97706; }
        .action-btn.receipt:hover { background: #f59e0b; color: white; }
        
        .empty-state { text-align: center; padding: 60px; color: var(--gray); }
        .empty-state i { font-size: 64px; margin-bottom: 20px; opacity: 0.5; }
        
        .bulk-actions {
            background: white;
            border-radius: 16px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border);
        }
        .bulk-actions.show { display: flex; }
        .selected-count { font-size: 13px; font-weight: 600; color: var(--primary); }
        
        .checkbox-col { width: 40px; text-align: center; }
        .checkbox-col input { width: 18px; height: 18px; cursor: pointer; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 32px; width: 90%; max-width: 800px; max-height: 85vh; overflow-y: auto; animation: modalSlideIn 0.3s ease; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        @keyframes modalSlideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .modal-header { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; padding: 22px 28px; border-radius: 32px 32px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 12px; }
        .close-btn { background: rgba(255,255,255,0.2); border: none; width: 40px; height: 40px; border-radius: 30px; color: white; cursor: pointer; transition: all 0.2s; font-size: 18px; }
        .close-btn:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }
        .modal-body { padding: 28px; }
        .modal-footer { padding: 20px 28px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 14px; }
        .form-group { margin-bottom: 22px; }
        .form-group label { display: block; font-size: 13px; font-weight: 700; color: var(--gray); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.8px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 24px; font-size: 15px; }
        .alert { padding: 14px 20px; border-radius: 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #dcfce7; border-left: 4px solid var(--success); color: #166534; }
        .alert-error { background: #fee2e2; border-left: 4px solid var(--danger); color: #991b1b; }
        .items-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        .items-table th, .items-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border); }
        .items-table th { background: var(--light); font-weight: 700; color: var(--gray); font-size: 12px; text-transform: uppercase; }
        .totals-row { display: flex; justify-content: flex-end; margin-top: 16px; padding-top: 16px; border-top: 2px solid var(--border); }
        .totals-table { width: 300px; }
        .totals-table td { padding: 6px 0; }
        .totals-table td:last-child { text-align: right; font-weight: 700; }
        
        .pagination { margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; padding: 20px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            th, td { padding: 10px 12px; }
            .action-buttons { flex-direction: column; }
            .action-btn { justify-content: center; }
        }
        @media (max-width: 480px) { .stats-grid { grid-template-columns: 1fr; } }

        /* ═══ AI DEBT COLLECTION AGENT ═══════════════════════════════════ */
        .ai-agent-panel {
            background: white;
            border-radius: 24px;
            border: 2px solid #e0e7ff;
            margin-bottom: 32px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(37,99,235,.08);
        }
        .ai-agent-header {
            background: linear-gradient(135deg, #1e40af 0%, #7c3aed 60%, #db2777 100%);
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .ai-agent-header-left h2 {
            color: white;
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .ai-agent-header-left p { color: rgba(255,255,255,.75); font-size: 12px; margin-top: 4px; }
        .ai-pulse {
            display: inline-block;
            width: 10px; height: 10px;
            background: #34d399;
            border-radius: 50%;
            animation: pulse 1.8s infinite;
            margin-right: 4px;
        }
        @keyframes pulse { 0%,100%{box-shadow:0 0 0 0 rgba(52,211,153,.7)} 50%{box-shadow:0 0 0 8px rgba(52,211,153,0)} }

        .ai-tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            overflow-x: auto;
        }
        .ai-tab {
            padding: 14px 22px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
            background: none;
            border-top: none;
            border-left: none;
            border-right: none;
            font-family: 'Calibri', sans-serif;
            transition: all .2s;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .ai-tab:hover { color: #2563eb; background: #eff6ff; }
        .ai-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: white; }
        .ai-tab .tab-badge {
            background: #ef4444;
            color: white;
            border-radius: 20px;
            padding: 1px 7px;
            font-size: 10px;
            font-weight: 800;
        }

        .ai-pane { display: none; padding: 24px 28px; }
        .ai-pane.active { display: block; }

        /* Debtor cards */
        .debtor-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .day-selector {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
        }
        .day-selector input[type=number] {
            width: 64px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            font-family: 'Calibri', sans-serif;
        }
        .ai-btn {
            padding: 9px 20px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-family: 'Calibri', sans-serif;
            transition: all .2s;
        }
        .ai-btn-primary { background: #2563eb; color: white; }
        .ai-btn-primary:hover { background: #1d4ed8; transform: translateY(-1px); }
        .ai-btn-success { background: #10b981; color: white; }
        .ai-btn-success:hover { background: #059669; }
        .ai-btn-warning { background: #f59e0b; color: white; }
        .ai-btn-warning:hover { background: #d97706; }
        .ai-btn-danger  { background: #ef4444; color: white; }
        .ai-btn-danger:hover  { background: #dc2626; }
        .ai-btn-purple  { background: #7c3aed; color: white; }
        .ai-btn-purple:hover  { background: #6d28d9; }
        .ai-btn-sm { padding: 5px 12px; font-size: 11px; }

        /* Summary bar */
        .debtor-summary-bar {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px,1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .dsb-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
            text-align: center;
        }
        .dsb-value { font-size: 22px; font-weight: 800; }
        .dsb-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-top: 3px; letter-spacing: .5px; }

        /* Debtor table */
        .debtor-table-wrap { overflow-x: auto; }
        .debtor-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .debtor-table thead th {
            background: #f1f5f9;
            padding: 10px 12px;
            text-align: left;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
        }
        .debtor-table tbody td {
            padding: 12px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-weight: 600;
            vertical-align: middle;
        }
        .debtor-table tbody tr:hover td { background: #f8fafc; }
        .overdue-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
        }
        .overdue-critical { background: #fee2e2; color: #991b1b; }
        .overdue-warning  { background: #fef9c3; color: #854d0e; }
        .overdue-normal   { background: #dbeafe; color: #1e40af; }
        .pstatus-unpaid   { background: #fee2e2; color: #991b1b; border-radius: 20px; padding: 2px 9px; font-size: 10px; font-weight: 800; }
        .pstatus-partial  { background: #fef9c3; color: #854d0e; border-radius: 20px; padding: 2px 9px; font-size: 10px; font-weight: 800; }

        /* WA send result */
        .wa-result-box {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 14px;
            padding: 16px 20px;
            margin-top: 16px;
            font-size: 13px;
            display: none;
        }
        .wa-result-box.error { background: #fef2f2; border-color: #fca5a5; }

        /* Message preview */
        .msg-preview {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 12px;
            padding: 14px 18px;
            font-size: 13px;
            white-space: pre-wrap;
            line-height: 1.6;
            color: #0f172a;
            font-family: 'Calibri', sans-serif;
            margin-top: 10px;
            max-height: 220px;
            overflow-y: auto;
        }
        .msg-preview-admin {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        /* Log table */
        .log-table { width:100%; border-collapse:collapse; font-size:12px; }
        .log-table th { background:#f8fafc; padding:8px 10px; text-align:left; font-size:10px; font-weight:800; text-transform:uppercase; color:#64748b; border-bottom:1px solid #e2e8f0; }
        .log-table td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .sent-yes { color:#059669; font-weight:700; }
        .sent-no  { color:#dc2626; font-weight:700; }

        /* Loading spinner */
        .ai-loading { text-align:center; padding:40px; color:#64748b; font-size:14px; }
        .ai-loading i { font-size:28px; display:block; margin-bottom:10px; color:#2563eb; }

        /* Config notice */
        .config-notice {
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: #92400e;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo-area">
            <img src="images/logo.jpeg" alt="Savant Motors Logo" class="logo-image" onerror="this.src='https://via.placeholder.com/80?text=SM'">
            <h2>SAVANT MOTORS</h2>
            <p>ERP System</p>
        </div>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-title">MAIN</div>
        <a href="dashboard_erp.php" class="menu-item"><i class="fas fa-chart-line"></i> Dashboard</a>
        <a href="invoices.php" class="menu-item active"><i class="fas fa-file-invoice-dollar"></i> Invoices</a>
        <a href="quotations.php" class="menu-item"><i class="fas fa-file-invoice"></i> Quotations</a>
        
        <div class="sidebar-title">ACCOUNTING</div>
        <a href="#" class="menu-item" id="viewAccountsBtn"><i class="fas fa-book"></i> Chart of Accounts</a>
        <a href="#" class="menu-item" id="viewLedgerBtn"><i class="fas fa-list-ul"></i> Ledger Entries</a>
        
        <div class="sidebar-title">OPERATIONS</div>
        <a href="../views/unified/index.php" class="menu-item"><i class="fas fa-boxes"></i> Inventory</a>
        <a href="job_cards.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Job Cards</a>
        <a href="technicians.php" class="menu-item"><i class="fas fa-users-cog"></i> Technicians</a>
        <a href="../views/tools/index.php" class="menu-item"><i class="fas fa-tools"></i> Tools</a>
        
        <div class="sidebar-title">SYSTEM</div>
        <a href="logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-file-invoice-dollar"></i> Invoices</h1>
            <p>Manage invoices, track payments, and generate receipts</p>
        </div>
        <button class="btn btn-primary" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Export to Excel</button>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         🤖  AI DEBT COLLECTION AGENT
    ════════════════════════════════════════════════════════════════════ -->
    <div class="ai-agent-panel" id="aiAgentPanel">

        <!-- Header -->
        <div class="ai-agent-header">
            <div class="ai-agent-header-left">
                <h2>
                    <span class="ai-pulse"></span>
                    🤖 AI Debt Collection Agent
                </h2>
                <p>Automatically detects overdue invoices · Sends WhatsApp reminders to customers · Alerts admin with full debtor report</p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="ai-btn ai-btn-warning" onclick="loadDebtors()">
                    <i class="fas fa-sync-alt"></i> Scan Overdue
                </button>
                <button class="ai-btn ai-btn-purple" onclick="sendAdminSummary()">
                    <i class="fab fa-whatsapp"></i> Alert Admin
                </button>
                <button class="ai-btn ai-btn-success" id="bulkWaBtn" onclick="sendBulkReminders()" disabled>
                    <i class="fab fa-whatsapp"></i> Send All Reminders
                </button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="ai-tabs">
            <button class="ai-tab active" onclick="switchAiTab('debtors',this)">
                <i class="fas fa-exclamation-circle"></i> Overdue Debtors
                <span class="tab-badge" id="debtorBadge">0</span>
            </button>
            <button class="ai-tab" onclick="switchAiTab('partial',this)">
                <i class="fas fa-adjust"></i> Partial Payments
                <span class="tab-badge" id="partialBadge" style="background:#f59e0b;">0</span>
            </button>
            <button class="ai-tab" onclick="switchAiTab('preview',this)">
                <i class="fab fa-whatsapp"></i> Message Preview
            </button>
            <button class="ai-tab" onclick="switchAiTab('log',this)">
                <i class="fas fa-history"></i> Reminder Log
            </button>
        </div>

        <!-- ① OVERDUE DEBTORS TAB -->
        <div class="ai-pane active" id="pane-debtors">
            <div class="config-notice">
                <i class="fas fa-cog" style="margin-top:1px;flex-shrink:0;"></i>
                <span>
                    <strong>Setup:</strong> Edit <code>WA_ADMIN_PHONE</code> and <code>WA_APIKEY</code> at the top of invoices.php with your CallMeBot WhatsApp API key.
                    Get your free key at <strong>callmebot.com</strong> — takes 30 seconds.
                </span>
            </div>

            <div class="debtor-controls">
                <div class="day-selector">
                    <i class="fas fa-calendar-alt" style="color:#2563eb;"></i>
                    Show invoices overdue by more than
                    <input type="number" id="dayThreshold" value="15" min="1" max="365">
                    days
                </div>
                <button class="ai-btn ai-btn-primary" onclick="loadDebtors()">
                    <i class="fas fa-search"></i> Scan
                </button>
                <span id="lastScanTime" style="font-size:11px;color:#94a3b8;"></span>
            </div>

            <!-- Summary bar -->
            <div class="debtor-summary-bar" id="debtorSummary" style="display:none;">
                <div class="dsb-card">
                    <div class="dsb-value" id="dsbCount" style="color:#ef4444;">0</div>
                    <div class="dsb-label">Overdue Invoices</div>
                </div>
                <div class="dsb-card">
                    <div class="dsb-value" id="dsbTotal" style="color:#dc2626;font-size:16px;">UGX 0</div>
                    <div class="dsb-label">Total Outstanding</div>
                </div>
                <div class="dsb-card">
                    <div class="dsb-value" id="dsbCritical" style="color:#991b1b;">0</div>
                    <div class="dsb-label">Critical (30+ days)</div>
                </div>
                <div class="dsb-card">
                    <div class="dsb-value" id="dsbPartial" style="color:#f59e0b;">0</div>
                    <div class="dsb-label">Partial Payments</div>
                </div>
            </div>

            <div id="debtorTableArea">
                <div style="text-align:center;padding:40px;color:#94a3b8;font-size:13px;">
                    <i class="fas fa-robot" style="font-size:32px;display:block;margin-bottom:10px;color:#c7d2fe;"></i>
                    Click <strong>"Scan Overdue"</strong> to run the AI agent — it will find all unpaid &amp; partial invoices past your day threshold.
                </div>
            </div>

            <!-- Bulk result box -->
            <div class="wa-result-box" id="bulkResultBox">
                <div id="bulkResultContent"></div>
            </div>
        </div>

        <!-- ② PARTIAL PAYMENTS TAB -->
        <div class="ai-pane" id="pane-partial">
            <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                Customers who have made partial payments but still have outstanding balances. These are prioritised for follow-up.
            </p>
            <div id="partialTableArea">
                <div style="text-align:center;padding:40px;color:#94a3b8;font-size:13px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                    Loading partial payment data…
                </div>
            </div>
        </div>

        <!-- ③ MESSAGE PREVIEW TAB -->
        <div class="ai-pane" id="pane-preview">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap;">
                <div>
                    <h4 style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:8px;">
                        <i class="fab fa-whatsapp" style="color:#25d366;"></i> Customer Reminder Message
                    </h4>
                    <p style="font-size:12px;color:#64748b;margin-bottom:8px;">Template sent to each overdue customer. Variables are auto-filled per invoice.</p>
                    <div class="msg-preview">Dear [Customer Name],

This is a payment reminder from *Savant Motors* for Invoice *[INV-XXXX]* ([Vehicle Reg]).

💰 *Amount Due: UGX [Balance]*
📅 Invoice is [X] day(s) overdue.

Kindly make payment via:
🏦 ABSA A/C: 6007717553
📱 MoMo Pay: 915573

Thank you for your continued business! 🚗</div>
                </div>
                <div>
                    <h4 style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:8px;">
                        <i class="fas fa-user-shield" style="color:#2563eb;"></i> Admin Alert Message
                    </h4>
                    <p style="font-size:12px;color:#64748b;margin-bottom:8px;">Summary sent to the admin WhatsApp number. Shows all debtors and totals.</p>
                    <div class="msg-preview msg-preview-admin" id="adminPreviewBox">📋 *SAVANT MOTORS — Outstanding Invoices Report*
Generated: [Date &amp; Time]
──────────────────────────────

📌 Overdue (>15 days): *N invoice(s)*
💰 Total Outstanding: *UGX X,XXX,XXX*
⚡ Partial Balances: *UGX X,XXX,XXX*

━━ DEBTOR LIST ━━
1. 🔴 [Customer] [UNPAID]
   Inv: INV-XXXX | Due: UGX XXX | Xd
2. 🟡 [Customer] [PARTIAL]
   Inv: INV-XXXX | Due: UGX XXX | Xd</div>
                    <button class="ai-btn ai-btn-purple" style="margin-top:10px;" onclick="sendAdminSummary()">
                        <i class="fab fa-whatsapp"></i> Send This to Admin Now
                    </button>
                    <div id="adminSendResult" style="margin-top:10px;font-size:12px;"></div>
                </div>
            </div>
        </div>

        <!-- ④ REMINDER LOG TAB -->
        <div class="ai-pane" id="pane-log">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <span style="font-size:13px;font-weight:700;color:#0f172a;">Last 50 WhatsApp reminders sent</span>
                <button class="ai-btn ai-btn-primary ai-btn-sm" onclick="loadReminderLog()">
                    <i class="fas fa-sync"></i> Refresh
                </button>
            </div>
            <div id="logTableArea">
                <div class="ai-loading"><i class="fas fa-spinner fa-spin"></i> Loading log…</div>
            </div>
        </div>

    </div><!-- /.ai-agent-panel -->

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-header"><span class="stat-title">Total Invoices</span><div class="stat-icon" style="background:#dbeafe;color:var(--primary);"><i class="fas fa-file-invoice"></i></div></div><div class="stat-value"><?php echo $total_invoices; ?></div></div>
        <div class="stat-card"><div class="stat-header"><span class="stat-title">Paid</span><div class="stat-icon" style="background:#dcfce7;color:var(--success);"><i class="fas fa-check-circle"></i></div></div><div class="stat-value"><?php echo $paid_invoices; ?></div></div>
        <div class="stat-card"><div class="stat-header"><span class="stat-title">Partial</span><div class="stat-icon" style="background:#fed7aa;color:var(--warning);"><i class="fas fa-chart-line"></i></div></div><div class="stat-value"><?php echo $partial_invoices; ?></div></div>
        <div class="stat-card"><div class="stat-header"><span class="stat-title">Unpaid</span><div class="stat-icon" style="background:#fee2e2;color:var(--danger);"><i class="fas fa-clock"></i></div></div><div class="stat-value"><?php echo $unpaid_invoices; ?></div></div>
        <div class="stat-card"><div class="stat-header"><span class="stat-title">Total Value</span><div class="stat-icon" style="background:#f3e8ff;color:var(--secondary);"><i class="fas fa-chart-line"></i></div></div><div class="stat-value">UGX <?php echo number_format($total_value); ?></div></div>
    </div>

    <div class="tabs-container">
        <div class="tab active" onclick="switchTab('invoices')">All Invoices</div>
        <div class="tab" onclick="switchTab('quotations')">Convert Quotation</div>
    </div>

    <!-- Invoices Table Tab -->
    <div id="invoicesTab" class="tab-content">
        <div class="filter-bar">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" id="searchInput" placeholder="Invoice #, Customer, Vehicle...">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Payment Status</label>
                <select id="statusFilter">
                    <option value="all">All Status</option>
                    <option value="paid">Paid</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="partial">Partial</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> Date Range</label>
                <select id="dateFilter">
                    <option value="all">All Time</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                </select>
            </div>
            <button class="btn btn-secondary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn btn-secondary" onclick="resetFilters()"><i class="fas fa-undo-alt"></i> Reset</button>
        </div>

        <div class="bulk-actions" id="bulkActions">
            <i class="fas fa-check-circle" style="color: var(--primary);"></i>
            <span class="selected-count" id="selectedCount">0</span> invoice(s) selected
            <button class="btn btn-sm btn-danger" onclick="bulkDelete()"><i class="fas fa-trash-alt"></i> Delete Selected</button>
            <button class="btn btn-sm btn-secondary" onclick="clearSelection()"><i class="fas fa-times"></i> Clear</button>
        </div>

        <div class="table-container">
            <table id="invoicesTable">
                <thead>
                    <tr>
                        <th class="checkbox-col"><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                        <th>Invoice #</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Vehicle</th>
                        <th>Date</th>
                        <th>Amount (UGX)</th>
                        <th>Paid (UGX)</th>
                        <th>Balance (UGX)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="invoicesTableBody">
                    <?php if (empty($invoices)): ?>
                    <tr><td colspan="11" class="empty-state"><i class="fas fa-file-invoice"></i><h3>No Invoices Found</h3><p>Convert a quotation to create your first invoice</p></td></tr>
                    <?php else: ?>
                    <?php foreach ($invoices as $inv): 
                        $balance = $inv['total_amount'] - $inv['amount_paid'];
                    ?>
                    <tr data-invoice="<?php echo strtolower($inv['invoice_number']); ?>" 
                        data-customer="<?php echo strtolower($inv['customer_name']); ?>"
                        data-vehicle="<?php echo strtolower($inv['vehicle_reg']); ?>"
                        data-status="<?php echo $inv['payment_status']; ?>"
                        data-date="<?php echo $inv['invoice_date']; ?>">
                        <td class="checkbox-col"><input type="checkbox" class="invoice-checkbox" value="<?php echo $inv['id']; ?>"></td>
                        <td><strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($inv['telephone'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($inv['vehicle_reg'] ?? 'N/A'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($inv['invoice_date'])); ?></td>
                        <td class="amount"><?php echo number_format($inv['total_amount'], 0); ?></td>
                        <td><?php echo number_format($inv['amount_paid'], 0); ?></td>
                        <td class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo number_format($balance, 0); ?></td>
                        <td><span class="status-badge <?php echo $inv['payment_status']; ?>"><?php echo strtoupper($inv['payment_status']); ?></span></td>
                        <td class="action-buttons">
                            <button class="action-btn view" onclick="viewInvoice(<?php echo $inv['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                            <button class="action-btn print" onclick="printInvoice(<?php echo $inv['id']; ?>)"><i class="fas fa-print"></i> Print</button>
                            <?php if ($inv['payment_status'] != 'paid'): ?>
                            <button class="action-btn payment" onclick="recordPayment(<?php echo $inv['id']; ?>, <?php echo $inv['total_amount']; ?>, <?php echo $inv['amount_paid']; ?>)"><i class="fas fa-money-bill-wave"></i> Pay</button>
                            <?php endif; ?>
                            <button class="action-btn receipt" onclick="generateReceipt(<?php echo $inv['id']; ?>)">
                                <i class="fas fa-receipt"></i> Receipt
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="pagination">
            <div><i class="fas fa-list"></i> Showing <span id="visibleCount">0</span> of <span id="totalCount"><?php echo count($invoices); ?></span> invoices</div>
            <div><button class="btn btn-secondary btn-sm" onclick="previousPage()" id="prevBtn" disabled>Previous</button><span id="pageInfo">Page 1</span><button class="btn btn-secondary btn-sm" onclick="nextPage()" id="nextBtn">Next</button></div>
        </div>
    </div>

    <!-- Quotations Tab -->
    <div id="quotationsTab" class="tab-content" style="display:none;">
        <div class="filter-bar">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="quoteSearchInput" placeholder="Quote #, Customer..."></div>
            <button class="btn btn-secondary" onclick="filterQuotations()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn btn-secondary" onclick="resetQuoteFilters()"><i class="fas fa-undo-alt"></i> Reset</button>
        </div>
        
        <div class="table-container">
            <table id="quotationsTable">
                <thead>
                    <tr><th>Quotation #</th><th>Customer</th><th>Contact</th><th>Date</th><th>Amount (UGX)</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($quotations)): ?>
                    <tr><td colspan="7" class="empty-state"><i class="fas fa-file-invoice"></i><h3>No Quotations Available</h3><p>Create and approve quotations to convert them to invoices</p></td></tr>
                    <?php else: foreach ($quotations as $quote): ?>
                    <tr data-quote="<?php echo strtolower($quote['quotation_number']); ?>" data-customer="<?php echo strtolower($quote['customer_name']); ?>">
                        <td><strong><?php echo htmlspecialchars($quote['quotation_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($quote['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($quote['telephone'] ?? 'N/A'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($quote['quotation_date'])); ?></td>
                        <td class="amount">UGX <?php echo number_format($quote['total_amount'], 0); ?></td>
                        <td><span class="status-badge sent"><?php echo strtoupper($quote['status']); ?></span></td>
                        <td class="action-buttons">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="quotation_id" value="<?php echo $quote['id']; ?>">
                                <button type="submit" name="convert_quotation" class="action-btn payment">
                                    <i class="fas fa-exchange-alt"></i> Convert to Invoice
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-money-bill-wave"></i> Record Payment</h3><button class="close-btn" onclick="closeModal('paymentModal')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="invoice_id" id="paymentInvoiceId">
            <div class="modal-body">
                <div class="form-group"><label>Invoice Amount</label><input type="text" id="invoiceTotal" readonly style="background:#f1f5f9;"></div>
                <div class="form-group"><label>Amount Already Paid</label><input type="text" id="amountPaid" readonly style="background:#f1f5f9;"></div>
                <div class="form-group"><label>Balance Due</label><input type="text" id="balanceDue" readonly style="background:#f1f5f9;"></div>
                <div class="form-group"><label>Payment Amount *</label><input type="number" name="payment_amount" id="paymentAmount" step="0.01" required></div>
                <div class="form-group"><label>Payment Method</label><select name="payment_method" required>
                    <option value="cash">Cash</option>
                    <option value="mobile_money">Mobile Money</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                </select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('paymentModal')">Cancel</button><button type="submit" name="record_payment" class="btn btn-primary">Record Payment</button></div>
        </form>
    </div>
</div>

<!-- Invoice Details Modal -->
<div id="viewInvoiceModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header"><h3><i class="fas fa-file-invoice"></i> Invoice Details</h3><button class="close-btn" onclick="closeModal('viewInvoiceModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body" id="invoiceDetailsContent"><div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse"></i> Loading...</div></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewInvoiceModal')">Close</button>
            <button class="btn btn-primary" onclick="printInvoiceFromModal()"><i class="fas fa-print"></i> Print</button>
            <button class="btn btn-receipt" onclick="generateReceiptFromModal()"><i class="fas fa-receipt"></i> Generate Receipt</button>
        </div>
    </div>
</div>

<!-- Chart of Accounts Modal -->
<div id="accountsModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header"><h3><i class="fas fa-book"></i> Chart of Accounts</h3><button class="close-btn" onclick="closeModal('accountsModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body" id="accountsContent"><div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse"></i> Loading accounts...</div></div>
    </div>
</div>

<!-- Ledger Entries Modal -->
<div id="ledgerModal" class="modal">
    <div class="modal-content" style="max-width: 1100px;">
        <div class="modal-header"><h3><i class="fas fa-list-ul"></i> General Ledger (Recent Transactions)</h3><button class="close-btn" onclick="closeModal('ledgerModal')"><i class="fas fa-times"></i></button></div>
        <div class="modal-body" id="ledgerContent"><div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse"></i> Loading ledger entries...</div></div>
    </div>
</div>

<script>
    let currentPage = 1;
    let rowsPerPage = 15;
    let filteredRows = [];
    let currentInvoiceId = null;

    function applyFilters() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const status = document.getElementById('statusFilter').value;
        const dateRange = document.getElementById('dateFilter').value;
        const today = new Date().toISOString().split('T')[0];
        const rows = document.querySelectorAll('#invoicesTableBody tr');
        let visibleCount = 0;
        
        rows.forEach(row => {
            if (row.querySelector('.empty-state')) return;
            const invoice = row.dataset.invoice || '';
            const customer = row.dataset.customer || '';
            const vehicle = row.dataset.vehicle || '';
            const rowStatus = row.dataset.status || '';
            const rowDate = row.dataset.date || '';
            let matchesSearch = search === '' || invoice.includes(search) || customer.includes(search) || vehicle.includes(search);
            let matchesStatus = status === 'all' || rowStatus === status;
            let matchesDate = true;
            if (dateRange !== 'all') {
                const rowDateObj = new Date(rowDate);
                if (dateRange === 'today') matchesDate = rowDate === today;
                else if (dateRange === 'week') { const weekAgo = new Date(); weekAgo.setDate(weekAgo.getDate() - 7); matchesDate = rowDateObj >= weekAgo; }
                else if (dateRange === 'month') { const monthAgo = new Date(); monthAgo.setDate(monthAgo.getDate() - 30); matchesDate = rowDateObj >= monthAgo; }
            }
            const show = matchesSearch && matchesStatus && matchesDate;
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        document.getElementById('visibleCount').innerText = visibleCount;
        currentPage = 1;
        updatePagination();
    }
    
    function updatePagination() {
        const rows = Array.from(document.querySelectorAll('#invoicesTableBody tr')).filter(row => row.style.display !== 'none' && !row.querySelector('.empty-state'));
        filteredRows = rows;
        const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
        filteredRows.forEach((row, index) => { const page = Math.floor(index / rowsPerPage) + 1; row.style.display = page === currentPage ? '' : 'none'; });
        document.getElementById('pageInfo').innerText = `Page ${currentPage} of ${totalPages || 1}`;
        document.getElementById('prevBtn').disabled = currentPage <= 1;
        document.getElementById('nextBtn').disabled = currentPage >= totalPages;
    }
    
    function previousPage() { if (currentPage > 1) { currentPage--; updatePagination(); } }
    function nextPage() { const totalPages = Math.ceil(filteredRows.length / rowsPerPage); if (currentPage < totalPages) { currentPage++; updatePagination(); } }
    
    function resetFilters() { document.getElementById('searchInput').value = ''; document.getElementById('statusFilter').value = 'all'; document.getElementById('dateFilter').value = 'all'; applyFilters(); }
    
    function filterQuotations() {
        const search = document.getElementById('quoteSearchInput').value.toLowerCase();
        document.querySelectorAll('#quotationsTable tbody tr').forEach(row => {
            if (row.querySelector('.empty-state')) return;
            const quote = row.dataset.quote || '';
            const customer = row.dataset.customer || '';
            row.style.display = (search === '' || quote.includes(search) || customer.includes(search)) ? '' : 'none';
        });
    }
    
    function resetQuoteFilters() { document.getElementById('quoteSearchInput').value = ''; filterQuotations(); }
    
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        document.querySelectorAll('.invoice-checkbox').forEach(cb => { if (cb.closest('tr').style.display !== 'none') cb.checked = selectAll.checked; });
        updateBulkActions();
    }
    
    function updateBulkActions() {
        const count = document.querySelectorAll('.invoice-checkbox:checked').length;
        const bulkActions = document.getElementById('bulkActions');
        if (count > 0) { bulkActions.classList.add('show'); document.getElementById('selectedCount').innerText = count; }
        else { bulkActions.classList.remove('show'); document.getElementById('selectAll').checked = false; }
    }
    
    function clearSelection() { document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.checked = false); updateBulkActions(); }
    
    function bulkDelete() {
        const ids = [...document.querySelectorAll('.invoice-checkbox:checked')].map(cb => cb.value);
        if (ids.length && confirm(`Delete ${ids.length} invoice(s)?`)) {
            fetch('delete_invoices.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids }) })
            .then(response => response.json()).then(data => { if (data.success) location.reload(); else alert('Error: ' + data.message); })
            .catch(error => alert('An error occurred'));
        }
    }
    
    function exportToExcel() {
        const rows = document.querySelectorAll('#invoicesTableBody tr');
        let csv = [['Invoice #','Customer','Contact','Vehicle','Date','Amount','Paid','Balance','Status'].join(',')];
        rows.forEach(row => {
            if (row.querySelector('.empty-state')) return;
            const cells = row.querySelectorAll('td');
            if (cells.length > 1) {
                const data = [cells[1]?.innerText, cells[2]?.innerText, cells[3]?.innerText, cells[4]?.innerText, cells[5]?.innerText, cells[6]?.innerText, cells[7]?.innerText, cells[8]?.innerText, cells[9]?.innerText].map(t => `"${t?.trim().replace(/,/g,';') || ''}"`);
                if(data.length) csv.push(data.join(','));
            }
        });
        const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `invoices_${new Date().toISOString().slice(0,10)}.csv`; a.click(); URL.revokeObjectURL(a.href);
    }

    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function switchTab(tab) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        if (tab === 'invoices') document.querySelector('.tab:first-child').classList.add('active');
        else document.querySelector('.tab:last-child').classList.add('active');
        document.getElementById('invoicesTab').style.display = tab === 'invoices' ? 'block' : 'none';
        document.getElementById('quotationsTab').style.display = tab === 'quotations' ? 'block' : 'none';
    }

    async function viewInvoice(id) {
        currentInvoiceId = id;
        const modal = document.getElementById('viewInvoiceModal');
        const contentDiv = document.getElementById('invoiceDetailsContent');
        contentDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse"></i> Loading invoice details...</div>';
        openModal('viewInvoiceModal');
        try {
            const response = await fetch(`invoices.php?action=get_invoice&id=${id}`);
            const data = await response.json();
            if (data.error) { contentDiv.innerHTML = `<div style="color: var(--danger); text-align: center;">Error: ${data.error}</div>`; return; }
            if (data.success) {
                const inv = data.invoice;
                const items = data.items;
                let itemsHtml = '';
                if (items.length > 0) {
                    itemsHtml = `<table class="items-table"><thead><tr><th>Description</th><th>Qty</th><th>Unit Price (UGX)</th><th>Total (UGX)</th></tr></thead><tbody>${items.map(item => `<tr><td>${escapeHtml(item.description)}</td><td>${item.quantity}</td><td>${numberFormat(item.unit_price)}</td><td>${numberFormat(item.total_price)}</td></tr>`).join('')}</tbody></table>`;
                } else { itemsHtml = '<p style="color: var(--gray);">No line items found.</p>'; }
                const paidAmount = parseFloat(inv.amount_paid) || 0;
                const totalAmount = parseFloat(inv.total_amount) || 0;
                const balanceDue = totalAmount - paidAmount;
                const paymentStatusClass = inv.payment_status === 'paid' ? 'success' : (inv.payment_status === 'partial' ? 'warning' : 'danger');
                contentDiv.innerHTML = `
                    <div style="margin-bottom: 20px;"><h2 style="color: var(--primary);">${escapeHtml(inv.invoice_number)}</h2>
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div><strong>Customer:</strong> ${escapeHtml(inv.customer_name)}<br><strong>Date:</strong> ${formatDate(inv.invoice_date)}<br><strong>Vehicle:</strong> ${escapeHtml(inv.vehicle_reg || 'N/A')} ${escapeHtml(inv.vehicle_model || '')}</div>
                        <div><strong>Status:</strong> <span style="color: var(--${paymentStatusClass});">${inv.payment_status.toUpperCase()}</span><br><strong>Amount Paid:</strong> UGX ${numberFormat(paidAmount)}<br><strong>Balance Due:</strong> UGX ${numberFormat(balanceDue)}</div>
                    </div></div>
                    <h3>Items</h3>${itemsHtml}
                    <div class="totals-row"><table class="totals-table"><tr><td>Subtotal:</td><td>UGX ${numberFormat(inv.subtotal)}</td></tr><tr><td>Discount:</td><td>UGX ${numberFormat(inv.discount)}</td></tr><tr><td>Tax:</td><td>UGX ${numberFormat(inv.tax)}</td></tr><tr style="border-top: 2px solid var(--border);"><td><strong>Total:</strong></td><td><strong>UGX ${numberFormat(inv.total_amount)}</strong></td></tr></table></div>
                    ${inv.notes ? `<div style="margin-top: 16px; padding: 12px; background: var(--light); border-radius: 16px;"><strong>Notes:</strong><br>${escapeHtml(inv.notes)}</div>` : ''}
                `;
            } else { contentDiv.innerHTML = '<div style="color: var(--danger); text-align: center;">Failed to load invoice details.</div>'; }
        } catch (err) { contentDiv.innerHTML = `<div style="color: var(--danger); text-align: center;">Network error: ${err.message}</div>`; }
    }

    function printInvoiceFromModal() { if (currentInvoiceId) window.open(`print_invoice.php?id=${currentInvoiceId}`, '_blank'); }
    function printInvoice(id) { window.open(`print_invoice.php?id=${id}`, '_blank'); }
    
    function generateReceiptFromModal() {
        if (currentInvoiceId) {
            window.open(`receipt.php?invoice_id=${currentInvoiceId}`, '_blank', 'width=1000,height=800');
        }
    }

    function generateReceipt(invoiceId) {
        window.open(`receipt.php?invoice_id=${invoiceId}`, '_blank', 'width=1000,height=800');
    }

    function recordPayment(id, total, paid) {
        document.getElementById('paymentInvoiceId').value = id;
        document.getElementById('invoiceTotal').value = 'UGX ' + total.toLocaleString();
        document.getElementById('amountPaid').value = 'UGX ' + paid.toLocaleString();
        document.getElementById('balanceDue').value = 'UGX ' + (total - paid).toLocaleString();
        document.getElementById('paymentAmount').value = (total - paid).toFixed(2);
        openModal('paymentModal');
    }

    // Accounting: Chart of Accounts
    document.getElementById('viewAccountsBtn')?.addEventListener('click', async (e) => {
        e.preventDefault();
        const modal = document.getElementById('accountsModal');
        const contentDiv = document.getElementById('accountsContent');
        contentDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse"></i> Loading accounts...</div>';
        openModal('accountsModal');
        try {
            const res = await fetch('invoices.php?action=get_accounts');
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            if (data.success && data.accounts) {
                let html = '<table class="items-table" style="width:100%"><thead><tr><th>Code</th><th>Account Name</th><th>Type</th><th>Balance (UGX)</th></tr></thead><tbody>';
                data.accounts.forEach(acc => {
                    html += `<tr>
                        <td><strong>${escapeHtml(acc.account_code)}</strong></td>
                        <td>${escapeHtml(acc.account_name)}</td>
                        <td><span class="status-badge" style="background:#e2e8f0;">${acc.account_type}</span></td>
                        <td>${numberFormat(acc.balance)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                contentDiv.innerHTML = html;
            } else { contentDiv.innerHTML = '<div class="alert alert-error">Failed to load accounts.</div>'; }
        } catch(err) { contentDiv.innerHTML = `<div class="alert alert-error">Error: ${err.message}</div>`; }
    });
    
    // Ledger Entries
    document.getElementById('viewLedgerBtn')?.addEventListener('click', async (e) => {
        e.preventDefault();
        const modal = document.getElementById('ledgerModal');
        const contentDiv = document.getElementById('ledgerContent');
        contentDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse"></i> Loading ledger entries...</div>';
        openModal('ledgerModal');
        try {
            const res = await fetch('invoices.php?action=get_ledger');
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            if (data.success && data.entries) {
                let html = '<table class="items-table" style="width:100%"><thead><tr><th>Date</th><th>Description</th><th>Account</th><th>Debit (UGX)</th><th>Credit (UGX)</th><th>Reference</th></tr></thead><tbody>';
                if(data.entries.length === 0) html += '<tr><td colspan="6" style="text-align:center">No ledger entries found.</td></tr>';
                data.entries.forEach(entry => {
                    html += `<tr>
                        <td>${formatDate(entry.transaction_date)}</td>
                        <td>${escapeHtml(entry.description)}</td>
                        <td>${escapeHtml(entry.account_name)} (${entry.account_code})</td>
                        <td>${numberFormat(entry.debit)}</td>
                        <td>${numberFormat(entry.credit)}</td>
                        <td>${entry.reference_type}:${entry.reference_id || '-'}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                contentDiv.innerHTML = html;
            } else { contentDiv.innerHTML = '<div class="alert alert-error">Failed to load ledger.</div>'; }
        } catch(err) { contentDiv.innerHTML = `<div class="alert alert-error">Error: ${err.message}</div>`; }
    });

    function numberFormat(value) { return parseFloat(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }
    function formatDate(dateString) { if (!dateString) return ''; return new Date(dateString).toLocaleDateString('en-GB'); }
    function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m])); }

    document.getElementById('searchInput')?.addEventListener('keyup', applyFilters);
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('dateFilter')?.addEventListener('change', applyFilters);
    document.getElementById('quoteSearchInput')?.addEventListener('keyup', filterQuotations);
    document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.addEventListener('change', updateBulkActions));

    // ═══════════════════════════════════════════════════════════════════════
    //  🤖 AI DEBT COLLECTION AGENT — JavaScript
    // ═══════════════════════════════════════════════════════════════════════

    let agentDebtors = [];

    function switchAiTab(name, btn) {
        document.querySelectorAll('.ai-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ai-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('pane-' + name).classList.add('active');
        if (name === 'log')     loadReminderLog();
        if (name === 'partial') renderPartialTab();
    }

    async function loadDebtors() {
        const days = parseInt(document.getElementById('dayThreshold').value) || 15;
        document.getElementById('debtorTableArea').innerHTML =
            '<div class="ai-loading"><i class="fas fa-brain fa-spin"></i> AI agent scanning invoices…</div>';
        document.getElementById('debtorSummary').style.display = 'none';
        document.getElementById('bulkWaBtn').disabled = true;
        try {
            const r = await fetch(`invoices.php?action=get_overdue_debtors&days=${days}`);
            const d = await r.json();
            if (!d.success) throw new Error(d.error);
            agentDebtors = d.debtors;
            document.getElementById('lastScanTime').textContent = 'Last scan: ' + new Date().toLocaleTimeString();
            document.getElementById('debtorBadge').textContent  = d.count;
            document.getElementById('partialBadge').textContent = d.partial_cnt;
            document.getElementById('dsbCount').textContent    = d.count;
            document.getElementById('dsbTotal').textContent    = 'UGX ' + parseInt(d.total_owed).toLocaleString();
            document.getElementById('dsbCritical').textContent = d.critical_cnt;
            document.getElementById('dsbPartial').textContent  = d.partial_cnt;
            document.getElementById('debtorSummary').style.display = 'grid';
            if (!d.count) {
                document.getElementById('debtorTableArea').innerHTML =
                    `<div style="text-align:center;padding:40px;color:#059669;font-size:14px;font-weight:600;">
                        <i class="fas fa-check-circle" style="font-size:36px;display:block;margin-bottom:10px;"></i>
                        No overdue invoices found beyond ${days} days! 🎉</div>`;
                return;
            }
            document.getElementById('bulkWaBtn').disabled = false;
            renderDebtorTable(d.debtors);
        } catch(e) {
            document.getElementById('debtorTableArea').innerHTML =
                `<div style="color:#ef4444;padding:20px;font-weight:600;"><i class="fas fa-exclamation-triangle"></i> Error: ${e.message}</div>`;
        }
    }

    function renderDebtorTable(debtors) {
        let html = `<div class="debtor-table-wrap"><table class="debtor-table">
            <thead><tr>
                <th>#</th><th>Customer</th><th>Invoice</th><th>Vehicle</th>
                <th>Invoice Date</th><th>Days Overdue</th><th>Total</th>
                <th>Paid</th><th>Balance Due</th><th>Status</th><th>Action</th>
            </tr></thead><tbody>`;
        debtors.forEach((d, i) => {
            const urgency = d.days_overdue >= 30 ? 'overdue-critical' : d.days_overdue >= 15 ? 'overdue-warning' : 'overdue-normal';
            const flag    = d.days_overdue >= 30 ? '🔴' : d.days_overdue >= 15 ? '🟡' : '🟢';
            const pClass  = d.payment_status === 'partial' ? 'pstatus-partial' : 'pstatus-unpaid';
            html += `<tr>
                <td style="color:#94a3b8;font-size:11px;">${i+1}</td>
                <td><div style="font-weight:700;">${escapeHtml(d.customer_name)}</div>
                    ${d.customer_phone ? `<div style="font-size:11px;color:#64748b;">${d.customer_phone}</div>` : '<div style="font-size:10px;color:#ef4444;">No phone</div>'}</td>
                <td><a href="print_invoice.php?id=${d.id}" target="_blank" style="color:#2563eb;font-weight:700;">${escapeHtml(d.invoice_number)}</a></td>
                <td style="font-size:12px;color:#64748b;">${escapeHtml(d.vehicle_reg||'—')} ${escapeHtml(d.vehicle_model||'')}</td>
                <td style="font-size:12px;">${formatDate(d.invoice_date)}</td>
                <td><span class="overdue-badge ${urgency}">${flag} ${d.days_overdue} days</span></td>
                <td style="font-weight:700;">UGX ${parseInt(d.total_amount).toLocaleString()}</td>
                <td style="color:#059669;font-weight:700;">UGX ${parseInt(d.amount_paid).toLocaleString()}</td>
                <td style="color:#ef4444;font-weight:800;">UGX ${parseInt(d.balance_due).toLocaleString()}</td>
                <td><span class="${pClass}">${d.payment_status.toUpperCase()}</span></td>
                <td style="white-space:nowrap;">
                    ${d.customer_phone
                        ? `<button class="ai-btn ai-btn-success ai-btn-sm" onclick="sendSingleReminder(${d.id},'${escapeHtml(d.customer_name).replace(/'/g,"\\'")}')"><i class="fab fa-whatsapp"></i> Send</button>`
                        : '<span style="font-size:10px;color:#ef4444;">No phone</span>'}
                    <button class="ai-btn ai-btn-primary ai-btn-sm" style="margin-top:3px;" onclick="recordPayment(${d.id},${d.total_amount},${d.amount_paid})"><i class="fas fa-money-bill"></i> Pay</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        document.getElementById('debtorTableArea').innerHTML = html;
    }

    async function sendSingleReminder(invoiceId, name) {
        if (!confirm(`Send WhatsApp reminder to ${name}?`)) return;
        try {
            const fd = new FormData();
            fd.append('action','send_wa_customer');
            fd.append('invoice_id', invoiceId);
            const r = await fetch('invoices.php',{method:'POST',body:fd});
            const d = await r.json();
            if (d.success) {
                document.querySelectorAll('.ai-tab')[2].click();
                document.querySelector('.msg-preview').textContent = d.message_preview;
                document.getElementById('adminSendResult').innerHTML =
                    d.sent ? `<span style="color:#059669;font-weight:700;"><i class="fas fa-check-circle"></i> Sent to ${d.phone}</span>`
                           : `<span style="color:#f59e0b;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> Queued — check CallMeBot config</span>`;
                showToastAI(d.sent ? `✅ Reminder sent to ${name}` : `⚠️ Queued for ${name}`, d.sent ? 'success' : 'warning');
            } else { showToastAI('❌ ' + d.error, 'error'); }
        } catch(e) { showToastAI('❌ ' + e.message, 'error'); }
    }

    async function sendBulkReminders() {
        const days = parseInt(document.getElementById('dayThreshold').value) || 15;
        if (!agentDebtors.length) return showToastAI('Run a scan first.', 'error');
        if (!confirm(`Send WhatsApp reminders to all ${agentDebtors.length} overdue customers AND alert admin?`)) return;
        const btn = document.getElementById('bulkWaBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
        try {
            const fd = new FormData();
            fd.append('action','send_wa_bulk');
            fd.append('days', days);
            const r = await fetch('invoices.php',{method:'POST',body:fd});
            const d = await r.json();
            const box = document.getElementById('bulkResultBox');
            box.style.display = 'block';
            if (d.success) {
                box.classList.remove('error');
                let rows = (d.results||[]).map(res =>
                    `<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #dcfce7;font-size:12px;">
                        <span><i class="fas fa-user" style="color:#94a3b8;"></i> ${escapeHtml(res.name)} (${res.phone})</span>
                        <span>${res.sent ? '<span style="color:#059669;font-weight:700;">✅ Sent</span>' : '<span style="color:#ef4444;font-weight:700;">❌ Failed</span>'}
                        <span style="color:#64748b;margin-left:8px;">UGX ${parseInt(res.balance).toLocaleString()}</span></span></div>`).join('');
                document.getElementById('bulkResultContent').innerHTML =
                    `<div style="font-weight:800;font-size:14px;margin-bottom:10px;color:#059669;">
                        <i class="fas fa-check-circle"></i> Bulk send complete — ${d.sent} sent, ${d.failed} failed of ${d.total}
                     </div><div style="font-size:12px;margin-bottom:8px;">Admin summary also sent to your WhatsApp.</div>${rows}`;
                showToastAI(`✅ ${d.sent} reminders sent!`, 'success');
            } else {
                box.classList.add('error');
                document.getElementById('bulkResultContent').innerHTML = `❌ ${d.error}`;
            }
        } catch(e) { showToastAI('❌ ' + e.message, 'error'); }
        finally { btn.disabled = false; btn.innerHTML = '<i class="fab fa-whatsapp"></i> Send All Reminders'; }
    }

    async function sendAdminSummary() {
        const days = parseInt(document.getElementById('dayThreshold').value) || 15;
        if (!confirm('Send outstanding invoice summary to admin WhatsApp?')) return;
        try {
            const fd = new FormData();
            fd.append('action','send_wa_admin_summary');
            fd.append('days', days);
            const r = await fetch('invoices.php',{method:'POST',body:fd});
            const d = await r.json();
            if (d.success) {
                document.querySelectorAll('.ai-tab')[2].click();
                document.getElementById('adminPreviewBox').textContent = d.preview;
                const statusTxt = d.sent ? '✅ Admin alert sent!' : '⚠️ Check CallMeBot config.';
                document.getElementById('adminSendResult').innerHTML =
                    `<span style="font-weight:700;color:${d.sent?'#059669':'#f59e0b'}">${statusTxt}</span>
                     <span style="color:#64748b;font-size:11px;"> (${d.count} debtors · UGX ${parseInt(d.total_owed).toLocaleString()} total)</span>`;
                showToastAI(statusTxt, d.sent ? 'success' : 'warning');
            } else { showToastAI('❌ ' + d.error, 'error'); }
        } catch(e) { showToastAI('❌ ' + e.message, 'error'); }
    }

    function renderPartialTab() {
        const partials = agentDebtors.filter(d => d.payment_status === 'partial');
        const el = document.getElementById('partialTableArea');
        if (!agentDebtors.length) {
            el.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">Run a scan first from the <strong>Overdue Debtors</strong> tab.</div>';
            return;
        }
        if (!partials.length) {
            el.innerHTML = '<div style="text-align:center;padding:30px;color:#059669;font-weight:600;"><i class="fas fa-check-circle"></i> No partial payments in this scan.</div>';
            return;
        }
        const totalBalance = partials.reduce((s,d) => s + parseFloat(d.balance_due), 0);
        const totalPaid    = partials.reduce((s,d) => s + parseFloat(d.amount_paid), 0);
        let html = `
            <div style="display:flex;gap:14px;margin-bottom:16px;flex-wrap:wrap;">
                <div class="dsb-card"><div class="dsb-value" style="color:#f59e0b;">${partials.length}</div><div class="dsb-label">Partial Invoices</div></div>
                <div class="dsb-card"><div class="dsb-value" style="color:#059669;font-size:16px;">UGX ${parseInt(totalPaid).toLocaleString()}</div><div class="dsb-label">Already Paid</div></div>
                <div class="dsb-card"><div class="dsb-value" style="color:#ef4444;font-size:16px;">UGX ${parseInt(totalBalance).toLocaleString()}</div><div class="dsb-label">Still Owed</div></div>
            </div>
            <div class="debtor-table-wrap"><table class="debtor-table">
                <thead><tr><th>Customer</th><th>Invoice</th><th>Days Overdue</th><th>Total</th><th>Paid</th><th>Balance</th><th>% Paid</th><th>Action</th></tr></thead>
                <tbody>`;
        partials.forEach(d => {
            const pct = Math.round((parseFloat(d.amount_paid) / parseFloat(d.total_amount)) * 100);
            const bar = `<div style="background:#e2e8f0;border-radius:99px;height:6px;width:100%;margin-top:4px;"><div style="background:#f59e0b;height:6px;border-radius:99px;width:${pct}%;"></div></div>`;
            html += `<tr>
                <td><div style="font-weight:700;">${escapeHtml(d.customer_name)}</div>${d.customer_phone?`<div style="font-size:11px;color:#64748b;">${d.customer_phone}</div>`:''}</td>
                <td><a href="print_invoice.php?id=${d.id}" target="_blank" style="color:#2563eb;font-weight:700;">${escapeHtml(d.invoice_number)}</a></td>
                <td><span class="overdue-badge ${d.days_overdue>=30?'overdue-critical':'overdue-warning'}">${d.days_overdue}d</span></td>
                <td>UGX ${parseInt(d.total_amount).toLocaleString()}</td>
                <td style="color:#059669;font-weight:700;">UGX ${parseInt(d.amount_paid).toLocaleString()}</td>
                <td style="color:#ef4444;font-weight:800;">UGX ${parseInt(d.balance_due).toLocaleString()}</td>
                <td><div style="font-weight:700;">${pct}%</div>${bar}</td>
                <td style="white-space:nowrap;">
                    ${d.customer_phone?`<button class="ai-btn ai-btn-success ai-btn-sm" onclick="sendSingleReminder(${d.id},'${escapeHtml(d.customer_name).replace(/'/g,"\\'")}')"><i class="fab fa-whatsapp"></i> Remind</button>`:''}
                    <button class="ai-btn ai-btn-primary ai-btn-sm" style="margin-top:3px;" onclick="recordPayment(${d.id},${d.total_amount},${d.amount_paid})"><i class="fas fa-money-bill"></i> Record</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;
    }

    async function loadReminderLog() {
        document.getElementById('logTableArea').innerHTML = '<div class="ai-loading"><i class="fas fa-spinner fa-spin"></i> Loading log…</div>';
        try {
            const r = await fetch('invoices.php?action=get_reminder_log');
            const d = await r.json();
            if (!d.success) throw new Error(d.error);
            if (!d.log.length) {
                document.getElementById('logTableArea').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;">No reminders sent yet.</div>';
                return;
            }
            let html = `<div style="overflow-x:auto;"><table class="log-table">
                <thead><tr><th>Date & Time</th><th>Customer</th><th>Invoice</th><th>Phone</th><th>Sent By</th><th>Status</th><th>Message</th></tr></thead><tbody>`;
            d.log.forEach(l => {
                const dt = new Date(l.sent_at);
                html += `<tr>
                    <td style="white-space:nowrap;">${dt.toLocaleDateString('en-GB')} ${dt.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'})}</td>
                    <td style="font-weight:700;">${escapeHtml(l.customer_name||'—')}</td>
                    <td style="color:#2563eb;">#${l.invoice_id}</td>
                    <td style="font-size:12px;">${l.phone||'—'}</td>
                    <td style="font-size:12px;color:#64748b;">${escapeHtml(l.sent_by||'—')}</td>
                    <td>${l.sent==1?'<span class="sent-yes"><i class="fas fa-check-circle"></i> Sent</span>':'<span class="sent-no"><i class="fas fa-times-circle"></i> Failed</span>'}</td>
                    <td>
                        <button class="ai-btn ai-btn-primary ai-btn-sm" onclick="
                            var nx=this.nextElementSibling;
                            nx.style.display=nx.style.display===''?'none':'';
                            this.textContent=nx.style.display===''?'Hide':'View'
                        ">View</button>
                        <div style="display:none;font-size:11px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;margin-top:6px;white-space:pre-wrap;max-width:280px;">${escapeHtml(l.message||'')}</div>
                    </td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            document.getElementById('logTableArea').innerHTML = html;
        } catch(e) {
            document.getElementById('logTableArea').innerHTML = `<div style="color:#ef4444;padding:20px;">Error: ${e.message}</div>`;
        }
    }

    function showToastAI(msg, type='success') {
        const t = document.createElement('div');
        t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:99999;padding:14px 22px;border-radius:40px;
            font-size:13px;font-weight:700;font-family:'Calibri',sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.18);
            background:${type==='success'?'#059669':type==='error'?'#dc2626':'#f59e0b'};color:white;
            display:flex;align-items:center;gap:8px;max-width:360px;animation:slideUp .3s ease;`;
        t.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-triangle'}"></i>${msg}`;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 4500);
    }

    // Auto-scan 15-day debtors on page load
    window.addEventListener('DOMContentLoaded', () => setTimeout(loadDebtors, 900));

    window.onclick = function(e) { if (e.target.classList.contains('modal')) e.target.classList.remove('active'); }
    
    applyFilters();
    updateBulkActions();
</script>
</body>
</html>