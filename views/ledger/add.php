<?php
// views/labour/add.php - Log Labour Entry
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$success = $error = '';
$jobCards = [];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch open job cards for the dropdown
    try {
        $jobCards = $conn->query(
            "SELECT id, job_number, COALESCE(customer_name,'Unknown') AS customer_name
             FROM job_cards
             WHERE status NOT IN ('Completed','Cancelled')
             ORDER BY job_number DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Handle POST ──────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $technician_name = trim($_POST['technician_name'] ?? '');
        $technician_id   = trim($_POST['technician_id']   ?? '');
        $job_id          = !empty($_POST['job_id']) ? (int)$_POST['job_id'] : null;
        $work_date       = trim($_POST['work_date']       ?? '');
        $hours_worked    = (float)($_POST['hours_worked']  ?? 0);
        $billable_hours  = (float)($_POST['billable_hours'] ?? 0);
        $hourly_rate     = (float)($_POST['hourly_rate']   ?? 0);
        $charge_rate     = !empty($_POST['charge_rate']) ? (float)$_POST['charge_rate'] : null;
        $service_type    = trim($_POST['service_type']    ?? '');
        $description     = trim($_POST['description']     ?? '');

        // Basic validation
        if (empty($technician_name))        $error = 'Technician name is required.';
        elseif (empty($work_date))          $error = 'Work date is required.';
        elseif ($hours_worked <= 0)         $error = 'Hours worked must be greater than 0.';
        elseif ($billable_hours > $hours_worked) $error = 'Billable hours cannot exceed hours worked.';
        else {
            $stmt = $conn->prepare(
                "INSERT INTO labour_entries
                 (technician_name, technician_id, job_id, work_date,
                  hours_worked, billable_hours, hourly_rate, charge_rate,
                  service_type, description, created_at)
                 VALUES
                 (:tn, :tid, :jid, :wd, :hw, :bh, :hr, :cr, :st, :desc, NOW())"
            );
            $stmt->execute([
                ':tn'   => $technician_name,
                ':tid'  => $technician_id ?: null,
                ':jid'  => $job_id,
                ':wd'   => $work_date,
                ':hw'   => $hours_worked,
                ':bh'   => $billable_hours,
                ':hr'   => $hourly_rate,
                ':cr'   => $charge_rate,
                ':st'   => $service_type ?: null,
                ':desc' => $description ?: null,
            ]);
            $success = 'Labour entry logged successfully!';
            // Clear POST data on success
            $_POST = [];
        }
    }
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

// Helpers (mirror index.php)
function fmt($n){ return 'UGX '.number_format($n); }

$serviceTypes = [
    'Engine Repair', 'Transmission', 'Electrical', 'Brakes',
    'Suspension', 'A/C & Cooling', 'Body Work', 'General Service',
    'Diagnostics', 'Tyres', 'Oil Change', 'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Labour | SAVANT MOTORS</title>
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

        /* ── Card ─────────────────────────────────────────────────────────── */
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
        label{font-size:.72rem;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.05em;}
        label span.req{color:var(--danger);margin-left:2px;}
        input,select,textarea{
            width:100%;padding:.6rem .85rem;
            border:1px solid var(--border);border-radius:.6rem;
            font-family:'Inter',sans-serif;font-size:.85rem;color:var(--dark);
            background:white;transition:border-color .2s,box-shadow .2s;outline:none;}
        input:focus,select:focus,textarea:focus{
            border-color:var(--primary-light);
            box-shadow:0 0 0 3px rgba(59,130,246,.15);}
        textarea{resize:vertical;min-height:90px;}
        .input-hint{font-size:.68rem;color:var(--gray);margin-top:.2rem;}

        /* ── Preview strip ────────────────────────────────────────────────── */
        .preview-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;
            background:var(--bg-light);border-radius:.75rem;padding:1rem;
            margin-top:1.25rem;border:1px solid var(--border);}
        .preview-item{text-align:center;}
        .preview-label{font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gray);}
        .preview-value{font-size:1.1rem;font-weight:800;color:var(--dark);margin-top:.2rem;}
        .preview-value.green{color:#059669;}
        .preview-value.blue{color:#1d4ed8;}
        .preview-value.amber{color:#d97706;}

        /* ── Efficiency bar ───────────────────────────────────────────────── */
        .eff-bar-wrap{margin-top:.5rem;}
        .eff-label{display:flex;justify-content:space-between;font-size:.7rem;color:var(--gray);margin-bottom:.3rem;}
        .eff-track{background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;}
        .eff-fill{height:100%;border-radius:999px;transition:width .4s ease,background .4s;}

        /* ── Buttons ──────────────────────────────────────────────────────── */
        .btn{padding:.55rem 1.2rem;border-radius:.6rem;font-weight:600;font-size:.85rem;
            cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:opacity .2s;}
        .btn:hover{opacity:.88;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-success{background:linear-gradient(135deg,#34d399,#059669);color:white;}
        .form-actions{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);}

        /* ── Badges ───────────────────────────────────────────────────────── */
        .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.62rem;font-weight:700;}
        .badge-green{background:#dcfce7;color:#166534;}
        .badge-yellow{background:#fef9c3;color:#854d0e;}
        .badge-red{background:#fee2e2;color:#991b1b;}

        @media(max-width:768px){
            .sidebar{left:-260px;}
            .main-content{margin-left:0;padding:1rem;}
            .form-grid,.form-grid.three{grid-template-columns:1fr;}
            .preview-strip{grid-template-columns:1fr;}
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
        <a href="../ledger/expenses_index.php" class="menu-item">💸 Expense Monitoring</a>
        <a href="index.php" class="menu-item active">🔧 Labour Utilization</a>
        <a href="../inventory/index.php" class="menu-item">📦 Inventory</a>
        <a href="../jobs/index.php" class="menu-item">🚗 Job Cards</a>
    </div>
</div>

<!-- ══ Main Content ══════════════════════════════════════════════════════════ -->
<div class="main-content">

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-hard-hat" style="color:#3b82f6;"></i> Log Labour Entry</h1>
            <p>Record technician hours against a job or service</p>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle" style="font-size:1.1rem;"></i>
        <?php echo htmlspecialchars($success); ?>
        &nbsp;—&nbsp;
        <a href="add.php" style="color:#166534;font-weight:700;">Log another entry</a>
        &nbsp;|&nbsp;
        <a href="index.php" style="color:#166534;font-weight:700;">View dashboard</a>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle" style="font-size:1.1rem;"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="add.php" id="labourForm">

        <!-- ── Technician & Job ──────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-user-cog" style="color:#3b82f6;"></i> Technician & Job Details</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">

                    <div class="form-group">
                        <label for="technician_name">Technician Name <span class="req">*</span></label>
                        <input type="text" id="technician_name" name="technician_name"
                               placeholder="e.g. John Okello"
                               value="<?php echo htmlspecialchars($_POST['technician_name'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="technician_id">Technician ID / Staff No.</label>
                        <input type="text" id="technician_id" name="technician_id"
                               placeholder="e.g. TECH-004"
                               value="<?php echo htmlspecialchars($_POST['technician_id'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="job_id">Linked Job Card</label>
                        <select id="job_id" name="job_id">
                            <option value="">— No job card (general work) —</option>
                            <?php foreach ($jobCards as $jc): ?>
                            <option value="<?php echo $jc['id']; ?>"
                                <?php echo (isset($_POST['job_id']) && (int)$_POST['job_id'] === (int)$jc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($jc['job_number'] . ' — ' . $jc['customer_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="input-hint">Only open / in-progress job cards are listed.</span>
                    </div>

                    <div class="form-group">
                        <label for="service_type">Service Type</label>
                        <select id="service_type" name="service_type">
                            <option value="">— Select service type —</option>
                            <?php foreach ($serviceTypes as $st): ?>
                            <option value="<?php echo $st; ?>"
                                <?php echo (($_POST['service_type'] ?? '') === $st) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($st); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="work_date">Work Date <span class="req">*</span></label>
                        <input type="date" id="work_date" name="work_date"
                               value="<?php echo htmlspecialchars($_POST['work_date'] ?? date('Y-m-d')); ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               required>
                    </div>

                    <div class="form-group full">
                        <label for="description">Work Description</label>
                        <textarea id="description" name="description"
                                  placeholder="Briefly describe the work performed…"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Hours & Rates ────────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock" style="color:#10b981;"></i> Hours & Rates</h3>
            </div>
            <div class="card-body">
                <div class="form-grid three">

                    <div class="form-group">
                        <label for="hours_worked">Hours Worked <span class="req">*</span></label>
                        <input type="number" id="hours_worked" name="hours_worked"
                               min="0.5" max="24" step="0.5" placeholder="0.0"
                               value="<?php echo htmlspecialchars($_POST['hours_worked'] ?? ''); ?>"
                               required oninput="updatePreview()">
                        <span class="input-hint">Total time on site / task</span>
                    </div>

                    <div class="form-group">
                        <label for="billable_hours">Billable Hours <span class="req">*</span></label>
                        <input type="number" id="billable_hours" name="billable_hours"
                               min="0" max="24" step="0.5" placeholder="0.0"
                               value="<?php echo htmlspecialchars($_POST['billable_hours'] ?? ''); ?>"
                               required oninput="updatePreview()">
                        <span class="input-hint">Hours charged to the customer</span>
                    </div>

                    <div class="form-group">
                        <label for="hourly_rate">Technician Hourly Rate (UGX)</label>
                        <input type="number" id="hourly_rate" name="hourly_rate"
                               min="0" step="500" placeholder="0"
                               value="<?php echo htmlspecialchars($_POST['hourly_rate'] ?? ''); ?>"
                               oninput="updatePreview()">
                        <span class="input-hint">Cost rate — what you pay the tech</span>
                    </div>

                    <div class="form-group">
                        <label for="charge_rate">Charge Rate to Customer (UGX)</label>
                        <input type="number" id="charge_rate" name="charge_rate"
                               min="0" step="500" placeholder="Same as hourly rate"
                               value="<?php echo htmlspecialchars($_POST['charge_rate'] ?? ''); ?>"
                               oninput="updatePreview()">
                        <span class="input-hint">Leave blank to use hourly rate</span>
                    </div>

                </div>

                <!-- Live Preview Strip -->
                <div class="preview-strip" id="previewStrip">
                    <div class="preview-item">
                        <div class="preview-label">Labour Cost</div>
                        <div class="preview-value blue" id="prevCost">UGX 0</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Billable Revenue</div>
                        <div class="preview-value green" id="prevRevenue">UGX 0</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Efficiency</div>
                        <div class="preview-value amber" id="prevEff">0%</div>
                    </div>
                </div>

                <!-- Efficiency bar -->
                <div class="eff-bar-wrap">
                    <div class="eff-label">
                        <span>Efficiency</span>
                        <span id="effPctLabel">0%</span>
                    </div>
                    <div class="eff-track">
                        <div class="eff-fill" id="effFill" style="width:0%;background:#dc2626;"></div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Labour Entry
                    </button>
                </div>
            </div>
        </div>

    </form>

</div><!-- /main-content -->

<script>
function fmt(n){
    return 'UGX ' + Number(n).toLocaleString('en-UG', {maximumFractionDigits:0});
}
function effColor(pct){
    return pct >= 75 ? '#059669' : (pct >= 50 ? '#d97706' : '#dc2626');
}

function updatePreview(){
    const hw  = parseFloat(document.getElementById('hours_worked').value)  || 0;
    const bh  = parseFloat(document.getElementById('billable_hours').value) || 0;
    const hr  = parseFloat(document.getElementById('hourly_rate').value)   || 0;
    const crRaw = document.getElementById('charge_rate').value;
    const cr  = crRaw !== '' ? parseFloat(crRaw) : hr;

    const cost    = hw * hr;
    const revenue = bh * cr;
    const eff     = hw > 0 ? Math.round((bh / hw) * 100) : 0;
    const col     = effColor(eff);

    document.getElementById('prevCost').textContent    = fmt(cost);
    document.getElementById('prevRevenue').textContent = fmt(revenue);
    document.getElementById('prevEff').textContent     = eff + '%';
    document.getElementById('prevEff').style.color     = col;

    const bar = document.getElementById('effFill');
    bar.style.width      = Math.min(eff, 100) + '%';
    bar.style.background = col;
    document.getElementById('effPctLabel').textContent = eff + '%';
    document.getElementById('effPctLabel').style.color = col;
}

// Auto-fill billable = worked when worked changes and billable is empty
document.getElementById('hours_worked').addEventListener('input', function(){
    const bh = document.getElementById('billable_hours');
    if (!bh.value) { bh.value = this.value; }
    updatePreview();
});

// Initialise on load (in case of re-submitted form with POST data)
updatePreview();
</script>
</body>
</html>
