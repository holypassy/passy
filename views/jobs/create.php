<?php
// jobs/create.php — Create / Edit a Job Card with costing lines
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$isEdit    = false;
$job       = [];
$jobLines  = [];  // labour lines
$partLines = [];  // parts lines
$customers = [];
$dbError   = null;
$success   = null;
$editId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load customers for dropdown
    try {
        $customers = $conn->query("SELECT id, full_name, phone FROM customers ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // Load existing job if editing
    if ($editId > 0) {
        try {
            $stmt = $conn->prepare("SELECT jc.*, c.full_name AS customer_name FROM job_cards jc LEFT JOIN customers c ON jc.customer_id = c.id WHERE jc.id = :id");
            $stmt->execute([':id' => $editId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job) {
                $isEdit = true;
                // Try to load labour lines
                try {
                    $lStmt = $conn->prepare("SELECT * FROM job_labour_lines WHERE job_id = :jid ORDER BY id");
                    $lStmt->execute([':jid' => $editId]);
                    $jobLines = $lStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {}
                // Try to load parts lines
                try {
                    $pStmt = $conn->prepare("SELECT * FROM job_parts_lines WHERE job_id = :jid ORDER BY id");
                    $pStmt->execute([':jid' => $editId]);
                    $partLines = $pStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {}
            }
        } catch (PDOException $e) {
            $dbError = $e->getMessage();
        }
    }

    // ── Handle POST ────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId   = (int)($_POST['customer_id'] ?? 0);
        $vehicleReg   = trim($_POST['vehicle_reg']   ?? '');
        $vehicleMake  = trim($_POST['vehicle_make']  ?? '');
        $vehicleModel = trim($_POST['vehicle_model'] ?? '');
        $description  = trim($_POST['description']   ?? '');
        $status       = $_POST['status']             ?? 'open';
        $completionDate = !empty($_POST['completion_date']) ? $_POST['completion_date'] : null;

        // Parse labour lines
        $labourDescs  = $_POST['labour_desc']  ?? [];
        $labourHours  = $_POST['labour_hours'] ?? [];
        $labourRates  = $_POST['labour_rate']  ?? [];

        // Parse parts lines
        $partDescs    = $_POST['part_desc']    ?? [];
        $partQtys     = $_POST['part_qty']     ?? [];
        $partPrices   = $_POST['part_price']   ?? [];

        // Compute totals
        $labourCost = 0;
        foreach ($labourHours as $i => $h) {
            $labourCost += (float)$h * (float)($labourRates[$i] ?? 0);
        }
        $partsCost = 0;
        foreach ($partQtys as $i => $q) {
            $partsCost += (float)$q * (float)($partPrices[$i] ?? 0);
        }
        $totalAmount = $labourCost + $partsCost;

        try {
            if ($isEdit && $editId > 0) {
                // Update header
                $upd = $conn->prepare("
                    UPDATE job_cards SET
                        customer_id=:cid, vehicle_reg=:vreg, vehicle_make=:vmake, vehicle_model=:vmodel,
                        description=:desc, status=:status, completion_date=:comp,
                        labour_cost=:lc, parts_cost=:pc, total_amount=:total,
                        updated_at=NOW()
                    WHERE id=:id
                ");
                $upd->execute([
                    ':cid'=>$customerId,':vreg'=>strtoupper($vehicleReg),':vmake'=>$vehicleMake,
                    ':vmodel'=>$vehicleModel,':desc'=>$description,':status'=>$status,
                    ':comp'=>$completionDate,':lc'=>$labourCost,':pc'=>$partsCost,':total'=>$totalAmount,
                    ':id'=>$editId
                ]);
                // Wipe and re-insert lines
                try { $conn->prepare("DELETE FROM job_labour_lines WHERE job_id=:j")->execute([':j'=>$editId]); } catch(PDOException $e){}
                try { $conn->prepare("DELETE FROM job_parts_lines  WHERE job_id=:j")->execute([':j'=>$editId]); } catch(PDOException $e){}
                $jobIdUsed = $editId;
            } else {
                // Generate job number
                $lastNum = (int)$conn->query("SELECT COUNT(*) FROM job_cards")->fetchColumn();
                $jobNumber = 'JC-' . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT);

                $ins = $conn->prepare("
                    INSERT INTO job_cards
                        (job_number, customer_id, vehicle_reg, vehicle_make, vehicle_model, description,
                         status, completion_date, labour_cost, parts_cost, total_amount, created_at, updated_at)
                    VALUES
                        (:jnum, :cid, :vreg, :vmake, :vmodel, :desc,
                         :status, :comp, :lc, :pc, :total, NOW(), NOW())
                ");
                $ins->execute([
                    ':jnum'=>$jobNumber,':cid'=>$customerId,':vreg'=>strtoupper($vehicleReg),
                    ':vmake'=>$vehicleMake,':vmodel'=>$vehicleModel,':desc'=>$description,
                    ':status'=>$status,':comp'=>$completionDate,':lc'=>$labourCost,':pc'=>$partsCost,
                    ':total'=>$totalAmount
                ]);
                $jobIdUsed = $conn->lastInsertId();
            }

            // Insert labour lines
            $lIns = $conn->prepare("INSERT INTO job_labour_lines (job_id, description, hours, rate, line_total) VALUES (:jid, :desc, :hrs, :rate, :tot)");
            foreach ($labourDescs as $i => $d) {
                if (trim($d) === '') continue;
                $hrs = (float)($labourHours[$i] ?? 0);
                $rte = (float)($labourRates[$i]  ?? 0);
                try { $lIns->execute([':jid'=>$jobIdUsed,':desc'=>trim($d),':hrs'=>$hrs,':rate'=>$rte,':tot'=>$hrs*$rte]); } catch(PDOException $e){}
            }

            // Insert parts lines
            $pIns = $conn->prepare("INSERT INTO job_parts_lines (job_id, description, quantity, unit_price, line_total) VALUES (:jid, :desc, :qty, :price, :tot)");
            foreach ($partDescs as $i => $d) {
                if (trim($d) === '') continue;
                $qty = (float)($partQtys[$i]   ?? 0);
                $prc = (float)($partPrices[$i] ?? 0);
                try { $pIns->execute([':jid'=>$jobIdUsed,':desc'=>trim($d),':qty'=>$qty,':price'=>$prc,':tot'=>$qty*$prc]); } catch(PDOException $e){}
            }

            header('Location: index.php?success=' . urlencode($isEdit ? 'Job card updated successfully.' : 'Job card created successfully.'));
            exit();

        } catch (PDOException $e) {
            $dbError = $e->getMessage();
        }
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$pageTitle = $isEdit ? 'Edit Job Card' : 'New Job Card';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f0f2f5; min-height:100vh; }
        :root {
            --primary:#1e40af; --primary-light:#3b82f6; --success:#10b981;
            --danger:#ef4444; --warning:#f59e0b; --border:#e2e8f0;
            --gray:#64748b; --dark:#0f172a; --bg-light:#f8fafc;
            --shadow-sm:0 1px 2px rgba(0,0,0,.05); --shadow-md:0 4px 6px -1px rgba(0,0,0,.1);
        }
        .sidebar { position:fixed;left:0;top:0;width:260px;height:100%;background:linear-gradient(180deg,#e0f2fe 0%,#bae6fd 100%);color:#0c4a6e;z-index:1000;overflow-y:auto; }
        .sidebar-header { padding:1.5rem;border-bottom:1px solid rgba(0,0,0,.08); }
        .sidebar-header h2 { font-size:1.2rem;font-weight:700;color:#0369a1; }
        .sidebar-header p { font-size:.7rem;opacity:.7;margin-top:.25rem;color:#0284c7; }
        .sidebar-menu { padding:1rem 0; }
        .sidebar-title { padding:.5rem 1.5rem;font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:#0369a1;font-weight:600; }
        .menu-item { padding:.7rem 1.5rem;display:flex;align-items:center;gap:.75rem;color:#0c4a6e;text-decoration:none;transition:all .2s;border-left:3px solid transparent;font-size:.85rem;font-weight:500; }
        .menu-item:hover,.menu-item.active { background:rgba(14,165,233,.2);color:#0284c7;border-left-color:#0284c7; }
        .main-content { margin-left:260px;padding:1.5rem;min-height:100vh; }
        .top-bar { background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;box-shadow:var(--shadow-sm);border:1px solid var(--border); }
        .page-title h1 { font-size:1.3rem;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:.5rem; }
        .page-title p { font-size:.75rem;color:var(--gray);margin-top:.25rem; }

        /* Form layout */
        .form-grid { display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem; }
        .form-grid-3 { display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem; }
        .card { background:white;border-radius:1rem;border:1px solid var(--border);margin-bottom:1.5rem;overflow:hidden; }
        .card-header { padding:1rem 1.25rem;background:var(--bg-light);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center; }
        .card-header h3 { font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem; }
        .card-body { padding:1.25rem; }

        label { display:block;font-size:.78rem;font-weight:600;color:var(--dark);margin-bottom:.35rem; }
        input[type=text],input[type=date],input[type=number],select,textarea {
            width:100%;padding:.55rem .75rem;border:1px solid var(--border);border-radius:.5rem;
            font-family:'Inter',sans-serif;font-size:.82rem;color:var(--dark);background:white;
            transition:border-color .15s;
        }
        input:focus,select:focus,textarea:focus { outline:none;border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(59,130,246,.15); }
        textarea { resize:vertical;min-height:80px; }
        .form-group { margin-bottom:1rem; }

        /* Line items table */
        .lines-table { width:100%;border-collapse:collapse;font-size:.8rem; }
        .lines-table th { background:var(--bg-light);padding:.6rem .75rem;text-align:left;font-weight:600;font-size:.68rem;color:var(--gray);border-bottom:1px solid var(--border); }
        .lines-table td { padding:.5rem .75rem;border-bottom:1px solid var(--border); }
        .lines-table td input { padding:.4rem .5rem;font-size:.8rem; }
        .lines-table .line-total { font-weight:700;color:var(--dark);text-align:right; }
        .lines-table .del-btn { background:#fee2e2;color:#991b1b;border:none;border-radius:.4rem;padding:.3rem .55rem;cursor:pointer;font-size:.7rem; }

        /* Totals box */
        .totals-box { background:var(--bg-light);border-radius:.75rem;padding:1.25rem;border:1px solid var(--border);min-width:280px; }
        .totals-row { display:flex;justify-content:space-between;padding:.4rem 0;font-size:.85rem;border-bottom:1px solid var(--border); }
        .totals-row:last-child { border-bottom:none;font-weight:800;font-size:1rem;color:var(--primary);padding-top:.6rem; }

        /* Buttons */
        .btn { padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.8rem;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:all .15s; }
        .btn-primary { background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white; }
        .btn-primary:hover { opacity:.9; }
        .btn-secondary { background:#e2e8f0;color:var(--dark); }
        .btn-success { background:linear-gradient(135deg,#34d399,#059669);color:white; }
        .btn-outline { background:transparent;border:1px solid var(--border);color:var(--dark); }
        .btn-sm { padding:.3rem .6rem;font-size:.7rem; }

        .alert { padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;font-size:.85rem; }
        .alert-error { background:#fee2e2;color:#991b1b;border-left:3px solid #ef4444; }

        .add-line-btn { background:transparent;border:1px dashed var(--primary-light);color:var(--primary);border-radius:.5rem;padding:.5rem 1rem;cursor:pointer;font-size:.78rem;font-family:'Inter',sans-serif;font-weight:600;width:100%;margin-top:.5rem;transition:all .15s; }
        .add-line-btn:hover { background:#eff6ff; }

        .bottom-bar { display:flex;justify-content:space-between;align-items:flex-start;gap:1.5rem;flex-wrap:wrap; }

        @media(max-width:768px) {
            .sidebar { left:-260px; }
            .main-content { margin-left:0;padding:1rem; }
            .form-grid,.form-grid-3 { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-header">
        <h2>📚 SAVANT MOTORS</h2>
        <p>General Ledger System</p>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-title">LEDGER</div>
        <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
        <a href="../ledger/index.php" class="menu-item">📚 General Ledger</a>
        <div class="sidebar-title" style="margin-top:1rem;">OPERATIONS</div>
        <a href="../ledger/expenses_index.php" class="menu-item">💸 Expense Monitoring</a>
        <a href="../ledger/labour_index.php" class="menu-item">🔧 Labour Utilization</a>
        <a href="index.php" class="menu-item active">🗂️ Job Costing &amp; Invoicing</a>
        <div style="margin-top:2rem;">
            <a href="../logout.php" class="menu-item">🚪 Logout</a>
        </div>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">

    <?php if ($dbError): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($dbError); ?></div>
    <?php endif; ?>

    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus-circle'; ?>" style="color:var(--primary-light);"></i>
                <?php echo $pageTitle; ?>
            </h1>
            <p><?php echo $isEdit ? 'Update job details, costs and status.' : 'Create a new job card with labour and parts costing.'; ?></p>
        </div>
        <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Jobs</a>
    </div>

    <form method="POST" id="jobForm">

        <!-- ── Job Details ──────────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-info-circle"></i> Job Details</h3></div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Customer *</label>
                        <select name="customer_id" required>
                            <option value="">— Select Customer —</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php if(($job['customer_id'] ?? 0) == $c['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($c['full_name']); ?> <?php if($c['phone']) echo '('.$c['phone'].')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <?php foreach(['open'=>'Open','in_progress'=>'In Progress','completed'=>'Completed','invoiced'=>'Invoiced','cancelled'=>'Cancelled'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php if(($job['status'] ?? 'open')===$v) echo 'selected'; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Vehicle Reg *</label>
                        <input type="text" name="vehicle_reg" value="<?php echo htmlspecialchars($job['vehicle_reg'] ?? ''); ?>" placeholder="e.g. UAA 123B" required style="text-transform:uppercase;">
                    </div>
                    <div class="form-group">
                        <label>Vehicle Make</label>
                        <input type="text" name="vehicle_make" value="<?php echo htmlspecialchars($job['vehicle_make'] ?? ''); ?>" placeholder="e.g. Toyota">
                    </div>
                    <div class="form-group">
                        <label>Vehicle Model</label>
                        <input type="text" name="vehicle_model" value="<?php echo htmlspecialchars($job['vehicle_model'] ?? ''); ?>" placeholder="e.g. Corolla">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Job Description / Work Required</label>
                        <textarea name="description" placeholder="Describe the work to be carried out…"><?php echo htmlspecialchars($job['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Completion Date</label>
                        <input type="date" name="completion_date" value="<?php echo htmlspecialchars($job['completion_date'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Labour Lines ─────────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-tools"></i> Labour Costing</h3></div>
            <div class="card-body" style="padding:0;">
                <div style="overflow-x:auto;">
                <table class="lines-table" id="labourTable">
                    <thead>
                        <tr>
                            <th style="width:45%;">Description</th>
                            <th style="width:15%;">Hours</th>
                            <th style="width:20%;">Rate (UGX/hr)</th>
                            <th style="width:15%;">Total (UGX)</th>
                            <th style="width:5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="labourBody">
                    <?php
                    $labourRows = !empty($jobLines) ? $jobLines : [['description'=>'','hours'=>'','rate'=>'']];
                    foreach ($labourRows as $l):
                    ?>
                    <tr class="labour-row">
                        <td><input type="text" name="labour_desc[]" value="<?php echo htmlspecialchars($l['description'] ?? ''); ?>" placeholder="e.g. Engine oil change"></td>
                        <td><input type="number" name="labour_hours[]" value="<?php echo htmlspecialchars($l['hours'] ?? ''); ?>" step="0.5" min="0" class="hours-input" placeholder="2.0"></td>
                        <td><input type="number" name="labour_rate[]" value="<?php echo htmlspecialchars($l['rate'] ?? ''); ?>" step="500" min="0" class="rate-input" placeholder="50000"></td>
                        <td class="line-total labour-line-total">—</td>
                        <td><button type="button" class="del-btn" onclick="removeRow(this)">✕</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div style="padding:.75rem 1rem;">
                    <button type="button" class="add-line-btn" onclick="addLabourRow()">＋ Add Labour Line</button>
                </div>
            </div>
        </div>

        <!-- ── Parts Lines ──────────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-cog"></i> Parts &amp; Materials</h3></div>
            <div class="card-body" style="padding:0;">
                <div style="overflow-x:auto;">
                <table class="lines-table" id="partsTable">
                    <thead>
                        <tr>
                            <th style="width:45%;">Part / Material</th>
                            <th style="width:15%;">Qty</th>
                            <th style="width:20%;">Unit Price (UGX)</th>
                            <th style="width:15%;">Total (UGX)</th>
                            <th style="width:5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="partsBody">
                    <?php
                    $partRows = !empty($partLines) ? $partLines : [['description'=>'','quantity'=>'','unit_price'=>'']];
                    foreach ($partRows as $p):
                    ?>
                    <tr class="part-row">
                        <td><input type="text" name="part_desc[]" value="<?php echo htmlspecialchars($p['description'] ?? ''); ?>" placeholder="e.g. Engine oil 5L"></td>
                        <td><input type="number" name="part_qty[]" value="<?php echo htmlspecialchars($p['quantity'] ?? ''); ?>" step="1" min="0" class="qty-input" placeholder="1"></td>
                        <td><input type="number" name="part_price[]" value="<?php echo htmlspecialchars($p['unit_price'] ?? ''); ?>" step="500" min="0" class="price-input" placeholder="35000"></td>
                        <td class="line-total part-line-total">—</td>
                        <td><button type="button" class="del-btn" onclick="removeRow(this)">✕</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div style="padding:.75rem 1rem;">
                    <button type="button" class="add-line-btn" onclick="addPartRow()">＋ Add Parts Line</button>
                </div>
            </div>
        </div>

        <!-- ── Bottom bar: totals + submit ─────────────────────────────── -->
        <div class="bottom-bar">
            <div style="flex:1; min-width:240px;">
                <a href="index.php" class="btn btn-secondary" style="margin-right:.5rem;"><i class="fas fa-times"></i> Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $isEdit ? 'Update Job Card' : 'Save Job Card'; ?></button>
            </div>
            <div class="totals-box">
                <div class="totals-row">
                    <span>Labour Subtotal</span>
                    <span id="totalLabour">UGX 0</span>
                </div>
                <div class="totals-row">
                    <span>Parts Subtotal</span>
                    <span id="totalParts">UGX 0</span>
                </div>
                <div class="totals-row">
                    <span>TOTAL</span>
                    <span id="grandTotal">UGX 0</span>
                </div>
            </div>
        </div>

    </form>
</div><!-- /main-content -->

<script>
// ─── Live calculation ────────────────────────────────────────────────────────
function fmt(n) { return 'UGX ' + Math.round(n).toLocaleString(); }

function recalc() {
    let labourTotal = 0;
    document.querySelectorAll('#labourBody tr.labour-row').forEach(row => {
        const h = parseFloat(row.querySelector('.hours-input').value) || 0;
        const r = parseFloat(row.querySelector('.rate-input').value)  || 0;
        const t = h * r;
        row.querySelector('.labour-line-total').textContent = t > 0 ? fmt(t) : '—';
        labourTotal += t;
    });

    let partsTotal = 0;
    document.querySelectorAll('#partsBody tr.part-row').forEach(row => {
        const q = parseFloat(row.querySelector('.qty-input').value)   || 0;
        const p = parseFloat(row.querySelector('.price-input').value) || 0;
        const t = q * p;
        row.querySelector('.part-line-total').textContent = t > 0 ? fmt(t) : '—';
        partsTotal += t;
    });

    document.getElementById('totalLabour').textContent = fmt(labourTotal);
    document.getElementById('totalParts').textContent  = fmt(partsTotal);
    document.getElementById('grandTotal').textContent  = fmt(labourTotal + partsTotal);
}

// Delegate input events
document.addEventListener('input', e => {
    if (e.target.matches('.hours-input,.rate-input,.qty-input,.price-input')) recalc();
});

// ─── Add/remove rows ─────────────────────────────────────────────────────────
function labourRowHTML() {
    return `<tr class="labour-row">
        <td><input type="text"   name="labour_desc[]"  placeholder="e.g. Engine oil change"></td>
        <td><input type="number" name="labour_hours[]" step="0.5" min="0" class="hours-input" placeholder="2.0"></td>
        <td><input type="number" name="labour_rate[]"  step="500" min="0" class="rate-input"  placeholder="50000"></td>
        <td class="line-total labour-line-total">—</td>
        <td><button type="button" class="del-btn" onclick="removeRow(this)">✕</button></td>
    </tr>`;
}
function partRowHTML() {
    return `<tr class="part-row">
        <td><input type="text"   name="part_desc[]"  placeholder="e.g. Engine oil 5L"></td>
        <td><input type="number" name="part_qty[]"   step="1"   min="0" class="qty-input"   placeholder="1"></td>
        <td><input type="number" name="part_price[]" step="500" min="0" class="price-input" placeholder="35000"></td>
        <td class="line-total part-line-total">—</td>
        <td><button type="button" class="del-btn" onclick="removeRow(this)">✕</button></td>
    </tr>`;
}
function addLabourRow() {
    document.getElementById('labourBody').insertAdjacentHTML('beforeend', labourRowHTML());
}
function addPartRow() {
    document.getElementById('partsBody').insertAdjacentHTML('beforeend', partRowHTML());
}
function removeRow(btn) {
    btn.closest('tr').remove();
    recalc();
}

// Initial calc on page load
recalc();
</script>
</body>
</html>
