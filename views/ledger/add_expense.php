<?php
// views/ledger/add_expense.php - Add Expense Entry
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$success = $error = '';
$suppliers  = [];
$accounts   = [];
$costCentres = [];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Reference data for dropdowns ────────────────────────────────────────
    try {
        $suppliers = $conn->query(
            "SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    try {
        $accounts = $conn->query(
            "SELECT id, account_name, account_code FROM cash_accounts WHERE is_active=1 ORDER BY account_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    try {
        $costCentres = $conn->query(
            "SELECT id, centre_name FROM cost_centres ORDER BY centre_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Handle POST ──────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Collect & sanitise
        $expense_date    = trim($_POST['expense_date']    ?? '');
        $category        = trim($_POST['category']        ?? '');
        $sub_category    = trim($_POST['sub_category']    ?? '');
        $description     = trim($_POST['description']     ?? '');
        $amount          = (float)($_POST['amount']       ?? 0);
        $tax_amount      = (float)($_POST['tax_amount']   ?? 0);
        $payment_method  = trim($_POST['payment_method']  ?? '');
        $reference_no    = trim($_POST['reference_no']    ?? '');
        $supplier_id     = !empty($_POST['supplier_id'])   ? (int)$_POST['supplier_id']  : null;
        $account_id      = !empty($_POST['account_id'])    ? (int)$_POST['account_id']   : null;
        $cost_centre_id  = !empty($_POST['cost_centre_id'])? (int)$_POST['cost_centre_id']: null;
        $is_recurring    = isset($_POST['is_recurring']) ? 1 : 0;
        $recurring_freq  = $is_recurring ? trim($_POST['recurring_freq'] ?? '') : null;
        $notes           = trim($_POST['notes']           ?? '');
        $recorded_by     = $_SESSION['username'] ?? 'System';

        // Validation
        if (empty($expense_date))   $error = 'Expense date is required.';
        elseif (empty($category))   $error = 'Category is required.';
        elseif (empty($description))$error = 'Description is required.';
        elseif ($amount <= 0)       $error = 'Amount must be greater than zero.';
        elseif (empty($payment_method)) $error = 'Payment method is required.';
        else {
            $total_amount = $amount + $tax_amount;

            $stmt = $conn->prepare(
                "INSERT INTO expenses
                 (expense_date, category, sub_category, description,
                  amount, tax_amount, total_amount,
                  payment_method, reference_no,
                  supplier_id, account_id, cost_centre_id,
                  is_recurring, recurring_freq,
                  notes, recorded_by, created_at)
                 VALUES
                 (:ed, :cat, :scat, :desc,
                  :amt, :tax, :tot,
                  :pm, :ref,
                  :sid, :aid, :ccid,
                  :ir, :rf,
                  :notes, :rb, NOW())"
            );
            $stmt->execute([
                ':ed'   => $expense_date,
                ':cat'  => $category,
                ':scat' => $sub_category  ?: null,
                ':desc' => $description,
                ':amt'  => $amount,
                ':tax'  => $tax_amount,
                ':tot'  => $total_amount,
                ':pm'   => $payment_method,
                ':ref'  => $reference_no  ?: null,
                ':sid'  => $supplier_id,
                ':aid'  => $account_id,
                ':ccid' => $cost_centre_id,
                ':ir'   => $is_recurring,
                ':rf'   => $recurring_freq,
                ':notes'=> $notes         ?: null,
                ':rb'   => $recorded_by,
            ]);

            $success = 'Expense recorded successfully! Total: UGX ' . number_format($total_amount);
            $_POST = [];
        }
    }

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

// ── Category → Sub-category map ────────────────────────────────────────────
$categoryMap = [
    'Salaries & Wages'    => ['Basic Salary','Overtime','Bonuses','NSSF Contribution','PAYE'],
    'Rent & Utilities'    => ['Office Rent','Workshop Rent','Electricity','Water','Internet','Generator Fuel'],
    'Parts & Inventory'   => ['Spare Parts','Consumables','Workshop Supplies','Tools & Equipment'],
    'Vehicle & Transport' => ['Fuel','Vehicle Maintenance','Road Tax','Insurance','Parking'],
    'Marketing'           => ['Advertising','Social Media','Promotions','Printing'],
    'Professional Fees'   => ['Accounting','Legal','Consulting','Audit'],
    'Office Expenses'     => ['Stationery','Printing','Postage','Cleaning','Security'],
    'Bank & Finance'      => ['Bank Charges','Loan Interest','Exchange Loss','Transfer Fees'],
    'Repairs & Maintenance'=> ['Building Maintenance','Equipment Repair','Plumbing','Electrical Works'],
    'Insurance'           => ['Fire Insurance','Motor Insurance','Staff Insurance','CCTV'],
    'Training'            => ['Staff Training','Certifications','Seminars','Books'],
    'Miscellaneous'       => ['Other Expenses','Donations','Subscriptions','Penalties'],
];

$paymentMethods = ['Cash','Mobile Money','Bank Transfer','Cheque','POS/Card','Credit (Supplier)'];
$recurringFreqs = ['Weekly','Monthly','Quarterly','Annually'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;min-height:100vh;}
        :root{
            --primary:#1e40af; --primary-light:#3b82f6;
            --success:#10b981; --danger:#ef4444; --warning:#f59e0b;
            --border:#e2e8f0; --gray:#64748b; --dark:#0f172a;
            --bg-light:#f8fafc;
            --shadow-sm:0 1px 2px rgba(0,0,0,.05);
            --shadow-md:0 4px 6px -1px rgba(0,0,0,.1);
        }

        /* ── Sidebar ──────────────────────────────────────────────────────── */
        .sidebar{position:fixed;left:0;top:0;width:260px;height:100%;
            background:linear-gradient(180deg,#e0f2fe 0%,#bae6fd 100%);
            color:#0c4a6e;z-index:1000;overflow-y:auto;}
        .sidebar-header{padding:1.5rem;border-bottom:1px solid rgba(0,0,0,.08);}
        .sidebar-header h2{font-size:1.2rem;font-weight:700;color:#0369a1;}
        .sidebar-header p{font-size:.7rem;opacity:.7;margin-top:.25rem;color:#0284c7;}
        .sidebar-menu{padding:1rem 0;}
        .sidebar-title{padding:.5rem 1.5rem;font-size:.7rem;text-transform:uppercase;
            letter-spacing:1px;color:#0369a1;font-weight:600;}
        .menu-item{padding:.7rem 1.5rem;display:flex;align-items:center;gap:.75rem;
            color:#0c4a6e;text-decoration:none;transition:all .2s;
            border-left:3px solid transparent;font-size:.85rem;font-weight:500;}
        .menu-item:hover,.menu-item.active{background:rgba(14,165,233,.2);
            color:#0284c7;border-left-color:#0284c7;}

        /* ── Layout ───────────────────────────────────────────────────────── */
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh;}
        .top-bar{background:white;border-radius:1rem;padding:1rem 1.5rem;
            margin-bottom:1.5rem;display:flex;justify-content:space-between;
            align-items:center;flex-wrap:wrap;gap:1rem;
            box-shadow:var(--shadow-sm);border:1px solid var(--border);}
        .page-title h1{font-size:1.3rem;font-weight:700;color:var(--dark);
            display:flex;align-items:center;gap:.5rem;}
        .page-title p{font-size:.75rem;color:var(--gray);margin-top:.25rem;}

        /* ── Alerts ───────────────────────────────────────────────────────── */
        .alert{border-radius:.75rem;padding:.85rem 1rem;margin-bottom:1.25rem;
            display:flex;align-items:center;gap:.75rem;font-size:.84rem;font-weight:500;}
        .alert-success{background:#dcfce7;border-left:4px solid #10b981;color:#166534;}
        .alert-danger {background:#fee2e2;border-left:4px solid #ef4444;color:#991b1b;}

        /* ── Cards ────────────────────────────────────────────────────────── */
        .card{background:white;border-radius:1rem;border:1px solid var(--border);
            margin-bottom:1.5rem;overflow:hidden;box-shadow:var(--shadow-sm);}
        .card-header{padding:1rem 1.25rem;background:var(--bg-light);
            border-bottom:1px solid var(--border);display:flex;
            justify-content:space-between;align-items:center;}
        .card-header h3{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
        .card-body{padding:1.5rem;}

        /* ── Form ─────────────────────────────────────────────────────────── */
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
        .form-grid.three{grid-template-columns:1fr 1fr 1fr;}
        .form-group{display:flex;flex-direction:column;gap:.35rem;}
        .form-group.full{grid-column:1/-1;}
        label{font-size:.72rem;font-weight:600;color:var(--gray);
            text-transform:uppercase;letter-spacing:.05em;}
        label span.req{color:var(--danger);margin-left:2px;}
        input,select,textarea{
            width:100%;padding:.6rem .85rem;
            border:1px solid var(--border);border-radius:.6rem;
            font-family:'Inter',sans-serif;font-size:.85rem;color:var(--dark);
            background:white;transition:border-color .2s,box-shadow .2s;outline:none;}
        input:focus,select:focus,textarea:focus{
            border-color:var(--primary-light);
            box-shadow:0 0 0 3px rgba(59,130,246,.15);}
        textarea{resize:vertical;min-height:80px;}
        .input-hint{font-size:.68rem;color:var(--gray);}

        /* ── Toggle (recurring) ───────────────────────────────────────────── */
        .toggle-row{display:flex;align-items:center;gap:.75rem;padding:.6rem .85rem;
            border:1px solid var(--border);border-radius:.6rem;background:var(--bg-light);}
        .toggle-label{font-size:.83rem;font-weight:500;color:var(--dark);}
        .toggle-sub{font-size:.7rem;color:var(--gray);}
        input[type="checkbox"]{width:18px;height:18px;accent-color:var(--primary-light);cursor:pointer;}

        /* ── Summary strip ────────────────────────────────────────────────── */
        .summary-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;
            background:var(--bg-light);border-radius:.75rem;padding:1rem;
            margin-top:1.25rem;border:1px solid var(--border);}
        .sum-item{text-align:center;}
        .sum-label{font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gray);}
        .sum-value{font-size:1.05rem;font-weight:800;color:var(--dark);margin-top:.25rem;}
        .sum-value.red{color:#dc2626;}
        .sum-value.amber{color:#d97706;}
        .sum-value.green{color:#059669;}
        .sum-value.blue{color:#1d4ed8;}

        /* ── Buttons ──────────────────────────────────────────────────────── */
        .btn{padding:.55rem 1.2rem;border-radius:.6rem;font-weight:600;font-size:.85rem;
            cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;
            text-decoration:none;transition:opacity .2s;}
        .btn:hover{opacity:.88;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-danger{background:linear-gradient(135deg,#f87171,#dc2626);color:white;}
        .form-actions{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem;
            padding-top:1.25rem;border-top:1px solid var(--border);}

        /* ── Divider ──────────────────────────────────────────────────────── */
        .section-divider{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;
            color:var(--gray);font-weight:700;padding:.5rem 0;border-bottom:1px dashed var(--border);
            margin-bottom:1.25rem;}

        /* ── Badges ───────────────────────────────────────────────────────── */
        .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.62rem;font-weight:700;}
        .badge-red{background:#fee2e2;color:#991b1b;}

        @media(max-width:768px){
            .sidebar{left:-260px;}
            .main-content{margin-left:0;padding:1rem;}
            .form-grid,.form-grid.three{grid-template-columns:1fr;}
            .summary-strip{grid-template-columns:1fr 1fr;}
        }
    </style>
</head>
<body>

<!-- ══ Sidebar ══════════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <h2>📚 SAVANT MOTORS</h2>
        <p>General Ledger System</p>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-title">LEDGER</div>
        <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
        <a href="../ledger/index.php" class="menu-item">📚 General Ledger</a>
        <a href="../ledger/trial_balance.php" class="menu-item">⚖️ Trial Balance</a>
        <a href="../ledger/income_statement.php" class="menu-item">📈 Income Statement</a>
        <a href="../ledger/balance_sheet.php" class="menu-item">📊 Balance Sheet</a>
        <div class="sidebar-title" style="margin-top:1rem;">CONNECTED LEDGERS</div>
        <a href="../accounting/debtors.php" class="menu-item">📥 Debtors (AR)</a>
        <a href="../accounting/creditors.php" class="menu-item">📤 Creditors (AP)</a>
        <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
        <a href="../cash/accounts.php" class="menu-item">🏦 Cash Accounts</a>
        <div class="sidebar-title" style="margin-top:1rem;">OPERATIONS</div>
        <a href="expenses_index.php" class="menu-item active">💸 Expense Monitoring</a>
        <a href="../labour/index.php" class="menu-item">🔧 Labour Utilization</a>
        <a href="../inventory/index.php" class="menu-item">📦 Inventory</a>
        <a href="../jobs/index.php" class="menu-item">🚗 Job Cards</a>
    </div>
</div>

<!-- ══ Main Content ══════════════════════════════════════════════════════════ -->
<div class="main-content">

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-file-invoice-dollar" style="color:#ef4444;"></i> Add Expense</h1>
            <p>Record a new expense against the ledger</p>
        </div>
        <a href="expenses_index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Expenses
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle" style="font-size:1.1rem;"></i>
        <?php echo htmlspecialchars($success); ?>
        &nbsp;—&nbsp;
        <a href="add_expense.php" style="color:#166534;font-weight:700;">Add another</a>
        &nbsp;|&nbsp;
        <a href="expenses_index.php" style="color:#166534;font-weight:700;">View all expenses</a>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle" style="font-size:1.1rem;"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="add_expense.php" id="expenseForm">

        <!-- ── Section 1: Basic Details ─────────────────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle" style="color:#3b82f6;"></i> Expense Details</h3>
            </div>
            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="expense_date">Expense Date <span class="req">*</span></label>
                        <input type="date" id="expense_date" name="expense_date"
                               value="<?php echo htmlspecialchars($_POST['expense_date'] ?? date('Y-m-d')); ?>"
                               max="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="reference_no">Reference / Receipt No.</label>
                        <input type="text" id="reference_no" name="reference_no"
                               placeholder="e.g. REC-00123"
                               value="<?php echo htmlspecialchars($_POST['reference_no'] ?? ''); ?>">
                        <span class="input-hint">Invoice, receipt, or voucher number</span>
                    </div>

                    <div class="form-group">
                        <label for="category">Category <span class="req">*</span></label>
                        <select id="category" name="category" required onchange="updateSubCategories()">
                            <option value="">— Select category —</option>
                            <?php foreach (array_keys($categoryMap) as $cat): ?>
                            <option value="<?php echo $cat; ?>"
                                <?php echo (($_POST['category'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sub_category">Sub-Category</label>
                        <select id="sub_category" name="sub_category">
                            <option value="">— Select sub-category —</option>
                            <?php
                            $selCat = $_POST['category'] ?? '';
                            if ($selCat && isset($categoryMap[$selCat])):
                                foreach ($categoryMap[$selCat] as $sub):
                            ?>
                            <option value="<?php echo $sub; ?>"
                                <?php echo (($_POST['sub_category'] ?? '') === $sub) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sub); ?>
                            </option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label for="description">Description <span class="req">*</span></label>
                        <textarea id="description" name="description"
                                  placeholder="Describe the expense clearly…" required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Section 2: Amount & Payment ──────────────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-money-bill-wave" style="color:#10b981;"></i> Amount & Payment</h3>
            </div>
            <div class="card-body">

                <div class="form-grid three">

                    <div class="form-group">
                        <label for="amount">Net Amount (UGX) <span class="req">*</span></label>
                        <input type="number" id="amount" name="amount"
                               min="1" step="100" placeholder="0"
                               value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
                               required oninput="updateSummary()">
                        <span class="input-hint">Amount before tax</span>
                    </div>

                    <div class="form-group">
                        <label for="tax_amount">Tax / VAT Amount (UGX)</label>
                        <input type="number" id="tax_amount" name="tax_amount"
                               min="0" step="100" placeholder="0"
                               value="<?php echo htmlspecialchars($_POST['tax_amount'] ?? '0'); ?>"
                               oninput="updateSummary()">
                        <span class="input-hint">Leave 0 if tax-exempt</span>
                    </div>

                    <div class="form-group">
                        <label>Total Amount (UGX)</label>
                        <input type="text" id="total_display" readonly
                               style="background:#f8fafc;font-weight:700;color:#dc2626;"
                               placeholder="Auto-calculated">
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Payment Method <span class="req">*</span></label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="">— Select method —</option>
                            <?php foreach ($paymentMethods as $pm): ?>
                            <option value="<?php echo $pm; ?>"
                                <?php echo (($_POST['payment_method'] ?? '') === $pm) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pm); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="account_id">Paid From Account</label>
                        <select id="account_id" name="account_id">
                            <option value="">— Select cash/bank account —</option>
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>"
                                <?php echo ((int)($_POST['account_id'] ?? 0) === (int)$acc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['account_code'] . ' — ' . $acc['account_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="supplier_id">Supplier / Payee</label>
                        <select id="supplier_id" name="supplier_id">
                            <option value="">— Select supplier (optional) —</option>
                            <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo $sup['id']; ?>"
                                <?php echo ((int)($_POST['supplier_id'] ?? 0) === (int)$sup['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sup['supplier_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <!-- Summary strip -->
                <div class="summary-strip">
                    <div class="sum-item">
                        <div class="sum-label">Net Amount</div>
                        <div class="sum-value blue" id="sumNet">UGX 0</div>
                    </div>
                    <div class="sum-item">
                        <div class="sum-label">Tax / VAT</div>
                        <div class="sum-value amber" id="sumTax">UGX 0</div>
                    </div>
                    <div class="sum-item">
                        <div class="sum-label">Total Payable</div>
                        <div class="sum-value red" id="sumTotal">UGX 0</div>
                    </div>
                    <div class="sum-item">
                        <div class="sum-label">Tax Rate</div>
                        <div class="sum-value green" id="sumRate">0%</div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 3: Classification & Recurrence ────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tags" style="color:#8b5cf6;"></i> Classification & Recurrence</h3>
            </div>
            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="cost_centre_id">Cost Centre</label>
                        <select id="cost_centre_id" name="cost_centre_id">
                            <option value="">— Select cost centre —</option>
                            <?php foreach ($costCentres as $cc): ?>
                            <option value="<?php echo $cc['id']; ?>"
                                <?php echo ((int)($_POST['cost_centre_id'] ?? 0) === (int)$cc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cc['centre_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="input-hint">Department or project this expense belongs to</span>
                    </div>

                    <div class="form-group" style="justify-content:flex-end;">
                        <label>Recurring Expense?</label>
                        <div class="toggle-row">
                            <input type="checkbox" id="is_recurring" name="is_recurring"
                                   <?php echo !empty($_POST['is_recurring']) ? 'checked' : ''; ?>
                                   onchange="toggleRecurring()">
                            <div>
                                <div class="toggle-label">Mark as recurring</div>
                                <div class="toggle-sub">E.g. rent, subscriptions, loan repayments</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" id="recurringFreqGroup"
                         style="display:<?php echo !empty($_POST['is_recurring']) ? 'flex' : 'none'; ?>;">
                        <label for="recurring_freq">Recurring Frequency</label>
                        <select id="recurring_freq" name="recurring_freq">
                            <option value="">— Select frequency —</option>
                            <?php foreach ($recurringFreqs as $rf): ?>
                            <option value="<?php echo $rf; ?>"
                                <?php echo (($_POST['recurring_freq'] ?? '') === $rf) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rf); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label for="notes">Internal Notes</label>
                        <textarea id="notes" name="notes"
                                  placeholder="Optional internal notes, approvals, or context…"
                                  style="min-height:65px;"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>

                </div>

                <div class="form-actions">
                    <a href="expenses_index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-undo"></i> Clear
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-save"></i> Save Expense
                    </button>
                </div>

            </div>
        </div>

    </form>

</div><!-- /main-content -->

<!-- ── Category Map (JS) ─────────────────────────────────────────────────── -->
<script>
const categoryMap = <?php echo json_encode($categoryMap); ?>;

function updateSubCategories(){
    const cat = document.getElementById('category').value;
    const sub = document.getElementById('sub_category');
    sub.innerHTML = '<option value="">— Select sub-category —</option>';
    if(cat && categoryMap[cat]){
        categoryMap[cat].forEach(s => {
            const o = document.createElement('option');
            o.value = s; o.textContent = s;
            sub.appendChild(o);
        });
        sub.disabled = false;
    } else {
        sub.disabled = true;
    }
}

function fmt(n){
    return 'UGX ' + Number(n).toLocaleString('en-UG',{maximumFractionDigits:0});
}

function updateSummary(){
    const net = parseFloat(document.getElementById('amount').value) || 0;
    const tax = parseFloat(document.getElementById('tax_amount').value) || 0;
    const tot = net + tax;
    const rate = net > 0 ? ((tax/net)*100).toFixed(1) : '0.0';

    document.getElementById('total_display').value = 'UGX ' + tot.toLocaleString('en-UG',{maximumFractionDigits:0});
    document.getElementById('sumNet').textContent   = fmt(net);
    document.getElementById('sumTax').textContent   = fmt(tax);
    document.getElementById('sumTotal').textContent = fmt(tot);
    document.getElementById('sumRate').textContent  = rate + '%';
}

function toggleRecurring(){
    const grp = document.getElementById('recurringFreqGroup');
    grp.style.display = document.getElementById('is_recurring').checked ? 'flex' : 'none';
}

function resetForm(){
    setTimeout(()=>{ updateSummary(); updateSubCategories(); }, 50);
}

// Initialise on page load (handles POST re-render)
updateSubCategories();
updateSummary();
</script>
</body>
</html>
