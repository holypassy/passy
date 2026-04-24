<?php
// views/ledger/journal_entry.php - Create Journal Entry
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$user_id        = $_SESSION['user_id']   ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
$accounts       = [];
$dbError        = null;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure core accounting tables exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            account_code VARCHAR(20) NOT NULL UNIQUE,
            account_name VARCHAR(100) NOT NULL,
            account_type ENUM('asset','liability','equity','revenue','expense') DEFAULT 'asset',
            balance      DECIMAL(15,2) DEFAULT 0.00,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS account_ledger (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME NOT NULL,
            description      VARCHAR(255),
            account_id       INT NOT NULL,
            debit            DECIMAL(15,2) DEFAULT 0.00,
            credit           DECIMAL(15,2) DEFAULT 0.00,
            reference_type   VARCHAR(50) DEFAULT 'journal',
            reference_id     INT,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES accounts(id)
        )
    ");

    // Seed default accounts if table is empty
    $conn->exec("
        INSERT IGNORE INTO accounts (account_code, account_name, account_type) VALUES
        ('1010','Cash on Hand','asset'),
        ('1020','Mobile Money','asset'),
        ('1030','Bank Account','asset'),
        ('1040','Cheque Account','asset'),
        ('1200','Accounts Receivable','asset'),
        ('2000','Accounts Payable','liability'),
        ('3000','Owner Equity','equity'),
        ('4000','Sales Revenue','revenue'),
        ('4100','Service Revenue','revenue'),
        ('5000','Cost of Goods Sold','expense'),
        ('5100','General Expenses','expense'),
        ('5200','Purchases','expense'),
        ('5300','Salaries & Wages','expense'),
        ('5400','Rent Expense','expense'),
        ('5500','Utilities Expense','expense')
    ");

    // ── Handle POST: create journal entry ─────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_journal_entry'])) {
        $entry_date     = $_POST['entry_date'];
        $description    = trim($_POST['description']);
        $reference      = trim($_POST['reference'] ?? '');
        $debit_account  = (int)$_POST['debit_account'];
        $credit_account = (int)$_POST['credit_account'];
        $amount         = floatval($_POST['amount']);

        $errors = [];
        if (empty($description))    $errors[] = "Description is required";
        if ($debit_account <= 0)    $errors[] = "Please select a debit account";
        if ($credit_account <= 0)   $errors[] = "Please select a credit account";
        if ($debit_account === $credit_account) $errors[] = "Debit and credit accounts cannot be the same";
        if ($amount <= 0)           $errors[] = "Amount must be greater than zero";

        if (empty($errors)) {
            $conn->beginTransaction();

            $now = date('Y-m-d H:i:s');

            // Insert debit entry
            $conn->prepare("
                INSERT INTO account_ledger (transaction_date, description, account_id, debit, credit, reference_type, reference_id)
                VALUES (?, ?, ?, ?, 0, 'journal', ?)
            ")->execute([$entry_date.' 00:00:00', ($reference ? "[$reference] " : '').$description, $debit_account, $amount, $user_id]);

            // Insert credit entry
            $conn->prepare("
                INSERT INTO account_ledger (transaction_date, description, account_id, debit, credit, reference_type, reference_id)
                VALUES (?, ?, ?, 0, ?, 'journal', ?)
            ")->execute([$entry_date.' 00:00:00', ($reference ? "[$reference] " : '').$description, $credit_account, $amount, $user_id]);

            // Update account balances
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $debit_account]);
            $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $credit_account]);

            // Also insert into journal_entries table if it exists
            $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('journal_entries', $tables)) {
                $lastJe = $conn->query("SELECT journal_number FROM journal_entries ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $nextNum = $lastJe ? str_pad((int)substr($lastJe['journal_number'],-4)+1,4,'0',STR_PAD_LEFT) : '0001';
                $jeStmt = $conn->prepare("
                    INSERT INTO journal_entries (journal_number, entry_date, description, reference, status, created_by)
                    VALUES (?,?,?,?,'posted',?)
                ");
                $jeStmt->execute(['JE-'.date('Y').'-'.$nextNum, $entry_date, $description, $reference, $user_id]);
                $je_id = $conn->lastInsertId();

                // Also insert into journal_lines if available
                $jlTable = in_array('journal_lines',$tables) ? 'journal_lines'
                         : (in_array('journal_entry_details',$tables) ? 'journal_entry_details' : null);
                if ($jlTable) {
                    $fkCol = ($jlTable==='journal_lines') ? 'journal_id' : 'journal_entry_id';
                    $jlCols = $conn->query("SHOW COLUMNS FROM $jlTable")->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array('entry_type',$jlCols)) {
                        $conn->prepare("INSERT INTO $jlTable ($fkCol,account_id,entry_type,amount) VALUES (?,?,'debit',?)")->execute([$je_id,$debit_account,$amount]);
                        $conn->prepare("INSERT INTO $jlTable ($fkCol,account_id,entry_type,amount) VALUES (?,?,'credit',?)")->execute([$je_id,$credit_account,$amount]);
                    } elseif (in_array('debit',$jlCols)) {
                        $conn->prepare("INSERT INTO $jlTable ($fkCol,account_id,debit,credit) VALUES (?,?,?,0)")->execute([$je_id,$debit_account,$amount]);
                        $conn->prepare("INSERT INTO $jlTable ($fkCol,account_id,debit,credit) VALUES (?,?,0,?)")->execute([$je_id,$credit_account,$amount]);
                    }
                }
            }

            $conn->commit();
            $_SESSION['success'] = "Journal entry posted successfully!";
            header('Location: journal_history.php');
            exit();
        } else {
            $_SESSION['error'] = implode('<br>', $errors);
        }
    }

    // Load accounts for dropdowns
    $accounts = $conn->query("SELECT id, account_code, account_name, account_type FROM accounts ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$error_message   = $_SESSION['error']   ?? null;
$success_message = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entry | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;}
        :root{--primary:#1e40af;--primary-light:#3b82f6;--success:#10b981;--danger:#ef4444;--border:#e2e8f0;--gray:#64748b;--dark:#0f172a;--bg-light:#f8fafc;}
        .sidebar{position:fixed;left:0;top:0;width:260px;height:100%;background:linear-gradient(180deg,#e0f2fe,#bae6fd);color:#0c4a6e;z-index:1000;overflow-y:auto;}
        .sidebar-header{padding:1.5rem;border-bottom:1px solid rgba(0,0,0,.08);}
        .sidebar-header h2{font-size:1.2rem;font-weight:700;color:#0369a1;}
        .sidebar-header p{font-size:.7rem;opacity:.7;margin-top:.25rem;color:#0284c7;}
        .sidebar-menu{padding:1rem 0;}
        .sidebar-title{padding:.5rem 1.5rem;font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:#0369a1;font-weight:600;}
        .menu-item{padding:.7rem 1.5rem;display:flex;align-items:center;gap:.75rem;color:#0c4a6e;text-decoration:none;transition:all .2s;border-left:3px solid transparent;font-size:.85rem;font-weight:500;}
        .menu-item:hover,.menu-item.active{background:rgba(14,165,233,.2);color:#0284c7;border-left-color:#0284c7;}
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh;}
        .top-bar{background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;border:1px solid var(--border);}
        .page-title h1{font-size:1.3rem;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:.5rem;}
        .form-card{background:white;border-radius:1rem;border:1px solid var(--border);overflow:hidden;}
        .form-header{background:linear-gradient(135deg,var(--primary-light),var(--primary));padding:1rem 1.5rem;color:white;}
        .form-header h2{font-size:1.1rem;font-weight:600;display:flex;align-items:center;gap:.5rem;}
        .form-body{padding:1.5rem;}
        .form-group{margin-bottom:1rem;}
        .form-group label{display:block;font-size:.7rem;font-weight:700;color:var(--gray);margin-bottom:.25rem;text-transform:uppercase;}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:.6rem .75rem;border:1.5px solid var(--border);border-radius:.5rem;font-size:.85rem;font-family:inherit;transition:border-color .15s;}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--primary-light);}
        .double-entry-row{background:var(--bg-light);padding:1rem;border-radius:.5rem;margin-bottom:1rem;border:1px solid var(--border);}
        .double-entry-header{font-size:.75rem;font-weight:700;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem;}
        .form-actions{display:flex;gap:1rem;justify-content:flex-end;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);}
        .btn{padding:.6rem 1.2rem;border-radius:.5rem;font-weight:600;font-size:.85rem;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:all .15s;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-secondary:hover{background:#cbd5e1;}
        .alert{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
        .alert-error{background:#fee2e2;color:#991b1b;border-left:3px solid var(--danger);}
        .alert-success{background:#dcfce7;color:#166534;border-left:3px solid var(--success);}
        .balance-indicator{background:#f0fdf4;border:1px solid #86efac;border-radius:.5rem;padding:.75rem 1rem;margin-top:1rem;font-size:.85rem;color:#166534;display:flex;align-items:center;gap:.5rem;}
        @media(max-width:768px){.sidebar{left:-260px;}.main-content{margin-left:0;padding:1rem;}}
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header"><h2>📚 SAVANT MOTORS</h2><p>General Ledger System</p></div>
    <div class="sidebar-menu">
        <div class="sidebar-title">MAIN</div>
        <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
        <a href="index.php"            class="menu-item">📚 General Ledger</a>
        <a href="trial_balance.php"    class="menu-item">⚖️ Trial Balance</a>
        <a href="income_statement.php" class="menu-item">📈 Income Statement</a>
        <a href="balance_sheet.php"    class="menu-item">📊 Balance Sheet</a>
        <a href="journal_entry.php"    class="menu-item active">✏️ New Entry</a>
        <a href="journal_history.php"  class="menu-item">📜 Journal History</a>
        <div style="margin-top:2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-pen-alt"></i> Journal Entry</h1>
            <p>Record a new transaction with double-entry accounting</p>
        </div>
        <a href="journal_history.php" class="btn btn-secondary"><i class="fas fa-history"></i> View History</a>
    </div>

    <?php if ($dbError): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= $error_message ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <div class="form-header">
            <h2><i class="fas fa-journal-whills"></i> New Journal Entry</h2>
        </div>
        <div class="form-body">
            <form method="POST" id="journalForm">
                <div class="form-group">
                    <label>Entry Date *</label>
                    <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Description *</label>
                    <textarea name="description" rows="2" required placeholder="Describe the transaction…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Reference (Optional)</label>
                    <input type="text" name="reference" placeholder="Invoice #, PO #, etc." value="<?= htmlspecialchars($_POST['reference'] ?? '') ?>">
                </div>

                <!-- Debit -->
                <div class="double-entry-row">
                    <div class="double-entry-header">
                        <i class="fas fa-arrow-down" style="color:var(--success);"></i>
                        <span style="color:var(--success);">DEBIT ENTRY</span>
                        <span style="font-weight:400;color:var(--gray);margin-left:.25rem;">(money flows in to this account)</span>
                    </div>
                    <div class="form-group">
                        <label>Account *</label>
                        <select name="debit_account" id="debitAccount" required onchange="checkAccounts()">
                            <option value="">— Select Account —</option>
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= ($_POST['debit_account']??'')==$acc['id']?'selected':'' ?>>
                                <?= htmlspecialchars($acc['account_code']) ?> – <?= htmlspecialchars($acc['account_name']) ?>
                                (<?= ucfirst($acc['account_type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (UGX) *</label>
                        <input type="number" name="amount" id="amountInput" step="1000" min="1" placeholder="0" required oninput="syncCredit(this.value)" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                    </div>
                </div>

                <!-- Credit -->
                <div class="double-entry-row">
                    <div class="double-entry-header">
                        <i class="fas fa-arrow-up" style="color:var(--danger);"></i>
                        <span style="color:var(--danger);">CREDIT ENTRY</span>
                        <span style="font-weight:400;color:var(--gray);margin-left:.25rem;">(money flows out of this account)</span>
                    </div>
                    <div class="form-group">
                        <label>Account *</label>
                        <select name="credit_account" id="creditAccount" required onchange="checkAccounts()">
                            <option value="">— Select Account —</option>
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= ($_POST['credit_account']??'')==$acc['id']?'selected':'' ?>>
                                <?= htmlspecialchars($acc['account_code']) ?> – <?= htmlspecialchars($acc['account_name']) ?>
                                (<?= ucfirst($acc['account_type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (UGX)</label>
                        <input type="number" id="creditAmount" step="1000" placeholder="0" readonly style="background:#f1f5f9;color:var(--gray);" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                    </div>
                </div>

                <div class="balance-indicator" id="balanceIndicator" style="display:none;">
                    <i class="fas fa-check-circle"></i>
                    Entry is balanced — Debit = Credit = UGX <span id="balanceAmount">0</span>
                </div>

                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" name="add_journal_entry" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i> Post Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function syncCredit(val) {
    const n = parseFloat(val) || 0;
    document.getElementById('creditAmount').value = val;
    const ind = document.getElementById('balanceIndicator');
    if (n > 0) {
        ind.style.display = 'flex';
        document.getElementById('balanceAmount').textContent = parseInt(n).toLocaleString();
    } else {
        ind.style.display = 'none';
    }
}

function checkAccounts() {
    const d = document.getElementById('debitAccount').value;
    const c = document.getElementById('creditAccount').value;
    const btn = document.getElementById('submitBtn');
    if (d && c && d === c) {
        btn.style.opacity = '0.5';
        btn.title = 'Debit and credit accounts cannot be the same';
    } else {
        btn.style.opacity = '1';
        btn.title = '';
    }
}

document.getElementById('journalForm').addEventListener('submit', function(e) {
    const d = document.getElementById('debitAccount').value;
    const c = document.getElementById('creditAccount').value;
    const amt = parseFloat(document.getElementById('amountInput').value);
    if (!d) { alert('Please select a debit account'); e.preventDefault(); return; }
    if (!c) { alert('Please select a credit account'); e.preventDefault(); return; }
    if (d === c) { alert('Debit and credit accounts cannot be the same'); e.preventDefault(); return; }
    if (!amt || amt <= 0) { alert('Please enter a valid amount'); e.preventDefault(); return; }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting…';
});

// Init display
const initAmt = document.getElementById('amountInput').value;
if (initAmt) syncCredit(initAmt);
</script>
</body>
</html>
