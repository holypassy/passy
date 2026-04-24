<?php
// company_vehicles.php – Company Vehicle Ledger (debt owed to Savant Motors by companies)
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}
$user_id        = $_SESSION['user_id']   ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
date_default_timezone_set('Africa/Kampala');

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure table exists with all needed columns
    $conn->exec("
        CREATE TABLE IF NOT EXISTS debtor_company_vehicles (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            date              DATE NOT NULL,
            vehicle_make      VARCHAR(100) NOT NULL,
            number_plate      VARCHAR(30)  NOT NULL,
            company_in_charge VARCHAR(150) NOT NULL,
            work_done         TEXT,
            amount_owed       DECIMAL(15,2) DEFAULT 0,
            amount_paid       DECIMAL(15,2) DEFAULT 0,
            balance           DECIMAL(15,2) DEFAULT 0,
            status            ENUM('open','partial','settled') DEFAULT 'open',
            notes             TEXT,
            created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add missing columns for older installs
    $existingCols = $conn->query("SHOW COLUMNS FROM debtor_company_vehicles")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['amount_paid','balance','status','notes','updated_at'] as $col) {
        if (!in_array($col, $existingCols)) {
            $defs = [
                'amount_paid' => "DECIMAL(15,2) DEFAULT 0",
                'balance'     => "DECIMAL(15,2) DEFAULT 0",
                'status'      => "ENUM('open','partial','settled') DEFAULT 'open'",
                'notes'       => "TEXT",
                'updated_at'  => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            ];
            $conn->exec("ALTER TABLE debtor_company_vehicles ADD COLUMN $col {$defs[$col]}");
        }
    }
    // Sync balance = amount_owed - amount_paid for existing rows missing balance
    $conn->exec("UPDATE debtor_company_vehicles SET balance = amount_owed - amount_paid WHERE balance = 0 AND amount_owed > 0 AND amount_paid = 0");

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add new vehicle record
    if (isset($_POST['add_vehicle'])) {
        try {
            $owed = (float)$_POST['cv_amount_owed'];
            $conn->prepare("
                INSERT INTO debtor_company_vehicles
                    (date, vehicle_make, number_plate, company_in_charge, work_done, amount_owed, balance, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?)
            ")->execute([
                $_POST['cv_date'],
                trim($_POST['cv_vehicle_make']),
                strtoupper(trim($_POST['cv_number_plate'])),
                trim($_POST['cv_company_in_charge']),
                trim($_POST['cv_work_done'] ?? ''),
                $owed,
                $owed,
                trim($_POST['cv_notes'] ?? ''),
            ]);
            $_SESSION['cv_success'] = "Vehicle debt record added successfully!";
        } catch (Exception $e) {
            $_SESSION['cv_error'] = "Error: " . $e->getMessage();
        }
        header('Location: company_vehicles.php');
        exit();
    }

    // Edit vehicle record
    if (isset($_POST['edit_vehicle'])) {
        try {
            $conn->beginTransaction();
            $cv_id = (int)$_POST['edit_id'];
            $owed = (float)$_POST['edit_amount_owed'];
            $paid = (float)$_POST['edit_amount_paid'];
            $balance = $owed - $paid;
            
            // Determine status based on balance
            if ($balance <= 0) {
                $status = 'settled';
            } elseif ($paid > 0) {
                $status = 'partial';
            } else {
                $status = 'open';
            }
            
            $conn->prepare("
                UPDATE debtor_company_vehicles
                SET date = ?,
                    vehicle_make = ?,
                    number_plate = ?,
                    company_in_charge = ?,
                    work_done = ?,
                    amount_owed = ?,
                    amount_paid = ?,
                    balance = ?,
                    status = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $_POST['edit_date'],
                trim($_POST['edit_vehicle_make']),
                strtoupper(trim($_POST['edit_number_plate'])),
                trim($_POST['edit_company_in_charge']),
                trim($_POST['edit_work_done'] ?? ''),
                $owed,
                $paid,
                $balance,
                $status,
                trim($_POST['edit_notes'] ?? ''),
                $cv_id
            ]);
            
            $conn->commit();
            $_SESSION['cv_success'] = "Vehicle record updated successfully!";
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['cv_error'] = "Error: " . $e->getMessage();
        }
        header('Location: company_vehicles.php');
        exit();
    }

    // Record payment
    if (isset($_POST['record_cv_payment'])) {
        try {
            $conn->beginTransaction();
            $cv_id   = (int)$_POST['cv_id'];
            $payment = (float)$_POST['cv_payment_amount'];

            $row = $conn->prepare("SELECT * FROM debtor_company_vehicles WHERE id = ? FOR UPDATE");
            $row->execute([$cv_id]);
            $cv = $row->fetch(PDO::FETCH_ASSOC);
            if (!$cv) throw new Exception("Record not found");
            if ($payment <= 0)               throw new Exception("Payment must be greater than zero");
            if ($payment > $cv['balance'])   throw new Exception("Payment (UGX " . number_format($payment) . ") exceeds balance (UGX " . number_format($cv['balance']) . ")");

            $new_paid    = $cv['amount_paid'] + $payment;
            $new_balance = $cv['balance']      - $payment;
            $new_status  = $new_balance <= 0 ? 'settled' : 'partial';

            $conn->prepare("
                UPDATE debtor_company_vehicles
                SET amount_paid = ?, balance = ?, status = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$new_paid, $new_balance, $new_status, $cv_id]);

            $conn->commit();
            $_SESSION['cv_success'] = "Payment of UGX " . number_format($payment) . " recorded for " . htmlspecialchars($cv['number_plate']) . "!";
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['cv_error'] = "Error: " . $e->getMessage();
        }
        header('Location: company_vehicles.php');
        exit();
    }

    // Delete record - FIXED VERSION
    if (isset($_POST['delete_cv'])) {
        try {
            $cv_id = (int)$_POST['cv_id'];
            
            // First check if record exists
            $checkStmt = $conn->prepare("SELECT id, number_plate FROM debtor_company_vehicles WHERE id = ?");
            $checkStmt->execute([$cv_id]);
            $record = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                throw new Exception("Record not found.");
            }
            
            // Perform deletion
            $deleteStmt = $conn->prepare("DELETE FROM debtor_company_vehicles WHERE id = ?");
            $deleteStmt->execute([$cv_id]);
            
            if ($deleteStmt->rowCount() > 0) {
                $_SESSION['cv_success'] = "Record for " . htmlspecialchars($record['number_plate']) . " deleted successfully!";
            } else {
                throw new Exception("Failed to delete the record.");
            }
        } catch (Exception $e) {
            $_SESSION['cv_error'] = "Delete failed: " . $e->getMessage();
        }
        header('Location: company_vehicles.php');
        exit();
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$filter_status  = $_GET['status']  ?? 'all';
$filter_company = $_GET['company'] ?? '';
$search         = trim($_GET['search'] ?? '');

$where_parts = [];
$params      = [];
if ($filter_status !== 'all') {
    $where_parts[] = "status = ?";
    $params[]      = $filter_status;
}
if ($filter_company !== '') {
    $where_parts[] = "company_in_charge = ?";
    $params[]      = $filter_company;
}
if ($search !== '') {
    $where_parts[] = "(number_plate LIKE ? OR vehicle_make LIKE ? OR company_in_charge LIKE ? OR work_done LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
$where = !empty($where_parts) ? "WHERE " . implode(" AND ", $where_parts) : "";

$stmt = $conn->prepare("SELECT * FROM debtor_company_vehicles $where ORDER BY date DESC, created_at DESC");
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats — computed from the filtered $vehicles so they ALWAYS match the table
$stats = [
    'total'             => count($vehicles),
    'total_owed'        => array_sum(array_column($vehicles, 'amount_owed')),
    'total_collected'   => array_sum(array_column($vehicles, 'amount_paid')),
    'total_outstanding' => array_sum(array_column($vehicles, 'balance')),
    'open_count'        => count(array_filter($vehicles, fn($v) => $v['status'] === 'open')),
    'partial_count'     => count(array_filter($vehicles, fn($v) => $v['status'] === 'partial')),
    'settled_count'     => count(array_filter($vehicles, fn($v) => $v['status'] === 'settled')),
    'companies_count'   => count(array_unique(array_column($vehicles, 'company_in_charge'))),
];

// Filter bar counts always reflect the FULL dataset (for status pills) — separate unfiltered query
$allStats = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status='open'    THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END) as partial_count,
        SUM(CASE WHEN status='settled' THEN 1 ELSE 0 END) as settled_count
    FROM debtor_company_vehicles
")->fetch(PDO::FETCH_ASSOC);

// Distinct companies for filter
$companies = $conn->query("
    SELECT DISTINCT company_in_charge FROM debtor_company_vehicles ORDER BY company_in_charge
")->fetchAll(PDO::FETCH_COLUMN);

$success_message = $_SESSION['cv_success'] ?? null;
$error_message   = $_SESSION['cv_error']   ?? null;
unset($_SESSION['cv_success'], $_SESSION['cv_error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Vehicle Ledger | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Inter','Segoe UI',sans-serif; background:linear-gradient(135deg,#f3e8ff,#ede9fe); padding:2rem; font-size:14px; }
    .page-wrap { max-width:1300px; margin:0 auto; }

    /* Toolbar */
    .toolbar { background:linear-gradient(135deg,#7c3aed,#4c1d95); padding:1rem 1.5rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap; border-radius:16px; margin-bottom:1.5rem; }
    .toolbar button, .toolbar a { background:rgba(255,255,255,.15); border:none; color:white; padding:.5rem 1.2rem; border-radius:8px; font-weight:600; cursor:pointer; font-size:.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:.5rem; transition:all .2s; }
    .toolbar button:hover, .toolbar a:hover { background:rgba(255,255,255,.28); transform:translateY(-1px); }
    .btn-add { background:linear-gradient(135deg,#10b981,#059669) !important; }
    .btn-add:hover { filter:brightness(1.1) !important; }

    /* Alerts */
    .alert { padding:12px 18px; border-radius:12px; margin-bottom:1rem; display:flex; align-items:center; gap:10px; font-size:13px; }
    .alert-success { background:#d1fae5; color:#065f46; border-left:4px solid #10b981; }
    .alert-danger   { background:#fee2e2; color:#991b1b; border-left:4px solid #ef4444; }

    /* Stats */
    .stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.5rem; }
    @media(max-width:900px){ .stats-row{ grid-template-columns:1fr 1fr; } }
    .stat-card { background:white; border-radius:16px; padding:1.2rem 1.5rem; box-shadow:0 2px 8px rgba(0,0,0,.07); border-left:4px solid transparent; }
    .stat-card.purple { border-left-color:#7c3aed; }
    .stat-card.red    { border-left-color:#ef4444; }
    .stat-card.green  { border-left-color:#10b981; }
    .stat-card.orange { border-left-color:#f59e0b; }
    .stat-card .label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.3rem; display:flex; align-items:center; gap:5px; }
    .stat-card .value { font-size:20px; font-weight:800; color:#0f172a; }
    .stat-card .value.red    { color:#dc2626; }
    .stat-card .value.green  { color:#059669; }
    .stat-card .value.purple { color:#7c3aed; }

    /* Filter / search bar */
    .filter-row { display:flex; gap:.75rem; margin-bottom:1rem; flex-wrap:wrap; align-items:center; }
    .filter-btn { padding:6px 16px; border-radius:20px; border:1.5px solid #ddd8fe; background:white; cursor:pointer; font-size:13px; font-weight:600; color:#6d28d9; text-decoration:none; transition:all .2s; }
    .filter-btn.active, .filter-btn:hover { background:#7c3aed; color:white; border-color:#7c3aed; }
    .search-box { flex:1; min-width:200px; max-width:320px; padding:7px 14px; border:1.5px solid #e2e8f0; border-radius:20px; font-size:13px; font-family:inherit; outline:none; }
    .search-box:focus { border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.1); }
    .company-filter { padding:7px 14px; border:1.5px solid #e2e8f0; border-radius:20px; font-size:13px; font-family:inherit; outline:none; background:white; color:#374151; cursor:pointer; }

    /* Card / table */
    .card { background:white; border-radius:20px; box-shadow:0 4px 16px rgba(0,0,0,.08); overflow:hidden; margin-bottom:1.5rem; }
    .card-header { background:linear-gradient(135deg,#7c3aed,#4c1d95); color:white; padding:1rem 1.5rem; font-size:15px; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:.6rem; }
    .card-header .right { font-size:12px; font-weight:500; opacity:.8; }

    .data-table { width:100%; border-collapse:collapse; font-size:13px; }
    .data-table th { background:#faf5ff; color:#6d28d9; padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid #ede9fe; text-align:left; white-space:nowrap; }
    .data-table td { padding:11px 12px; border-bottom:1px solid #f5f3ff; vertical-align:middle; }
    .data-table tr:last-child td { border-bottom:none; }
    .data-table tr:hover td { background:#faf5ff; }

    /* Plate badge */
    .plate-badge { display:inline-block; background:#1e293b; color:white; font-family:monospace; font-weight:800; font-size:13px; letter-spacing:1.5px; padding:4px 12px; border-radius:6px; border:2px solid #334155; }

    /* Status badge */
    .badge { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
    .badge-open    { background:#fee2e2; color:#991b1b; }
    .badge-partial { background:#fef3c7; color:#92400e; }
    .badge-settled { background:#d1fae5; color:#065f46; }

    /* Progress */
    .progress-wrap { background:#e2e8f0; border-radius:20px; height:6px; width:90px; display:inline-block; vertical-align:middle; margin-left:5px; }
    .progress-fill { height:6px; border-radius:20px; background:#7c3aed; }

    /* Work done cell */
    .work-text { max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#475569; font-size:12px; }
    .work-text:hover { white-space:normal; overflow:visible; }

    /* Action buttons */
    .action-btn { padding:5px 10px; border-radius:6px; border:none; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:.3rem; margin:1px; }
    .btn-pay  { background:#ede9fe; color:#6d28d9; }
    .btn-pay:hover { background:#7c3aed; color:white; }
    .btn-edit { background:#fef3c7; color:#92400e; }
    .btn-edit:hover { background:#f59e0b; color:white; }
    .btn-del  { background:#fee2e2; color:#991b1b; }
    .btn-del:hover { background:#ef4444; color:white; }
    .btn-print { background:#e0f2fe; color:#0369a1; }
    .btn-print:hover { background:#0369a1; color:white; }

    /* Modals */
    .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:2000; align-items:center; justify-content:center; }
    .modal.active { display:flex; }
    .modal-box { background:white; border-radius:24px; width:90%; max-width:520px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,.25); }
    .modal-header { background:linear-gradient(135deg,#7c3aed,#4c1d95); color:white; padding:1.1rem 1.5rem; border-radius:24px 24px 0 0; display:flex; justify-content:space-between; align-items:center; font-weight:700; font-size:15px; }
    .modal-body { padding:1.5rem; }
    .close-btn { background:rgba(255,255,255,.2); border:none; width:32px; height:32px; border-radius:50%; color:white; cursor:pointer; font-size:18px; line-height:1; display:flex; align-items:center; justify-content:center; }
    .close-btn:hover { background:rgba(255,255,255,.35); }
    .form-group { margin-bottom:1rem; }
    .form-group label { display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.4rem; }
    .form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 13px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; outline:none; transition:all .2s; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.1); }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .btn-submit { background:#7c3aed; color:white; border:none; padding:11px 30px; border-radius:40px; font-weight:700; font-size:14px; cursor:pointer; transition:all .2s; display:inline-flex; align-items:center; gap:.5rem; }
    .btn-submit:hover { background:#6d28d9; transform:translateY(-1px); }
    .btn-cancel-modal { background:#f1f5f9; color:#475569; border:none; padding:11px 22px; border-radius:40px; font-weight:600; font-size:14px; cursor:pointer; margin-right:8px; }

    .print-page-header { display:none; }

    /* Empty state */
    .empty-state { text-align:center; padding:3rem; color:#94a3b8; }
    .empty-state i { font-size:3rem; display:block; margin-bottom:1rem; color:#c4b5fd; }

    /* Print */
    .print-only { display:none; }
    .print-summary { display:none; }

    /* Print summary styles */
    .ps-header { text-align:center; border-bottom:2px solid #7c3aed; padding-bottom:12px; margin-bottom:16px; }
    .ps-title   { font-size:15px; font-weight:800; color:#4c1d95; }
    .ps-subtitle{ font-size:11px; color:#64748b; margin-top:3px; }
    .ps-table   { width:100%; border-collapse:collapse; font-size:12px; }
    .ps-table thead tr { background:#f5f3ff; }
    .ps-table th { padding:9px 11px; text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6d28d9; border-bottom:2px solid #ddd8fe; border-top:1px solid #ddd8fe; }
    .ps-table td { padding:9px 11px; border-bottom:1px solid #f0effe; vertical-align:middle; }
    .ps-num     { color:#94a3b8; font-size:11px; width:28px; }
    .ps-company { font-weight:700; color:#1e293b; }
    .ps-center  { text-align:center; }
    .ps-money   { text-align:right; font-family:monospace; font-size:12px; }
    .ps-green   { color:#059669; }
    .ps-red     { color:#dc2626; }
    .ps-open    td { background:#fff; }
    .ps-partial td { background:#fffbeb; }
    .ps-settled td { background:#f0fdf4; }
    .ps-totals  td { font-weight:800; background:#faf5ff; border-top:2px solid #7c3aed; padding:10px 11px; }
    .ps-total-label { color:#6d28d9; font-size:12px; text-align:right; }
    .ps-pct-pill { display:inline-block; font-size:10px; font-weight:700; color:#6d28d9; margin-bottom:2px; }
    .ps-bar      { background:#e2e8f0; border-radius:10px; height:5px; width:80px; }
    .ps-bar-fill { height:5px; border-radius:10px; background:#7c3aed; }
    .ps-footer   { text-align:center; font-size:10px; color:#94a3b8; margin-top:16px; padding-top:10px; border-top:1px solid #e2e8f0; }

    /* Print mode control */
    body.print-mode-detailed .print-summary { display:none !important; }
    body.print-mode-summary  .card          { display:none !important; }
    body.print-mode-summary  .stats-row     { display:none !important; }
    body.print-mode-summary  .print-page-header { display:block !important; }

    /* Print mode chooser modal */
    #printChooseModal {
        display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
        z-index:9999; align-items:center; justify-content:center;
    }
    #printChooseModal.active { display:flex; }
    .print-choose-box {
        background:white; border-radius:20px; padding:2rem 2.2rem; max-width:420px; width:90%;
        box-shadow:0 20px 60px rgba(0,0,0,.25); text-align:center;
    }
    .print-choose-box h3 { color:#4c1d95; font-size:17px; margin-bottom:.4rem; }
    .print-choose-box p  { color:#64748b; font-size:13px; margin-bottom:1.5rem; }
    .pmode-btn {
        display:flex; align-items:center; gap:1rem; width:100%; padding:14px 18px;
        border:2px solid #ede9fe; border-radius:14px; background:#faf5ff;
        cursor:pointer; margin-bottom:.9rem; text-align:left; transition:all .2s;
        font-family:inherit;
    }
    .pmode-btn:hover { border-color:#7c3aed; background:#ede9fe; }
    .pmode-btn .pmode-icon { font-size:24px; width:36px; flex-shrink:0; }
    .pmode-btn .pmode-label { font-weight:700; color:#1e293b; font-size:14px; }
    .pmode-btn .pmode-desc  { font-size:11px; color:#64748b; margin-top:2px; }
    .pmode-cancel { background:none; border:none; color:#94a3b8; cursor:pointer; font-size:13px; margin-top:.4rem; font-family:inherit; }
    .pmode-cancel:hover { color:#475569; }

    @media print {
        * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
        .no-print { display:none !important; }
        .print-only { display:block !important; }
        body { background:white !important; padding:8px !important; font-size:11px !important; }
        .page-wrap { max-width:100% !important; }
        .print-page-header { display:block !important; }
        .stats-row {
            display:grid !important;
            grid-template-columns: repeat(4,1fr) !important;
            gap:6px !important;
            margin-bottom:10px !important;
            page-break-inside:avoid;
        }
        .stat-card {
            border-radius:6px !important;
            padding:6px 10px !important;
            box-shadow:none !important;
            border:1px solid #e2e8f0 !important;
            border-left-width:4px !important;
        }
        .stat-card .label { font-size:8px !important; }
        .stat-card .value { font-size:13px !important; }
        .card { box-shadow:none !important; border:1px solid #ccc !important; border-radius:6px !important; margin-bottom:12px !important; overflow:visible !important; }
        .card-header { background:#4c1d95 !important; color:white !important; padding:7px 12px !important; font-size:12px !important; border-radius:0 !important; }
        .data-table { font-size:10px !important; }
        .data-table th { font-size:9px !important; padding:6px 8px !important; background:#f5f3ff !important; color:#4c1d95 !important; }
        .data-table td { padding:6px 8px !important; border-bottom:1px solid #ede9fe !important; }
        .data-table tr:hover td { background:transparent !important; }
        .work-text {
            max-width:none !important;
            overflow:visible !important;
            white-space:normal !important;
            text-overflow:unset !important;
            word-break:break-word !important;
        }
        .plate-badge { background:#1e293b !important; color:white !important; font-size:10px !important; padding:2px 7px !important; }
        .badge-open    { background:#fee2e2 !important; color:#991b1b !important; }
        .badge-partial { background:#fef3c7 !important; color:#92400e !important; }
        .badge-settled { background:#d1fae5 !important; color:#065f46 !important; }
        .progress-wrap { width:60px !important; }
        .progress-fill { background:#7c3aed !important; }
        .print-summary {
            display:block !important;
            page-break-before:always;
            margin-top:0 !important;
        }
        /* Detailed mode: hide summary page */
        body.print-mode-detailed .print-summary { display:none !important; }
        /* Summary mode: hide the ledger table and stats, only show header + summary */
        body.print-mode-summary .card     { display:none !important; }
        body.print-mode-summary .stats-row { display:none !important; }
        body.print-mode-summary .print-summary { page-break-before:avoid !important; }
        .ps-table thead tr { background:#f5f3ff !important; }
        .ps-table th { color:#4c1d95 !important; }
        .ps-open    td { background:#fff !important; }
        .ps-partial td { background:#fffbeb !important; }
        .ps-settled td { background:#f0fdf4 !important; }
        .ps-totals  td { background:#f5f3ff !important; border-top:2px solid #7c3aed !important; }
        .ps-bar-fill { background:#7c3aed !important; }
    }
    </style>
</head>
<body>

<div class="page-wrap">

<!-- Toolbar -->
<div class="toolbar no-print">
    <button class="btn-add" onclick="document.getElementById('addModal').classList.add('active')">
        <i class="fas fa-plus"></i> Add Vehicle Debt
    </button>
    <a href="debtors.php"><i class="fas fa-user-clock"></i> Debtors Ledger</a>
    <a href="receipt.php"><i class="fas fa-receipt"></i> Receipts</a>
    <a href="sales_ledger.php"><i class="fas fa-book-open"></i> Sales Ledger</a>
    <a href="creditors.php"><i class="fas fa-user-tie"></i> Creditors</a>
    <a href="../dashboard_erp.php"><i class="fas fa-home"></i> Dashboard</a>
    <button onclick="printDetailed()" style="margin-left:auto;background:rgba(255,255,255,.15);"><i class="fas fa-print"></i> Print Detailed</button>
    <button onclick="printSummaryOnly()" style="background:rgba(255,255,255,.15);"><i class="fas fa-list-alt"></i> Print Summary</button>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>

<!-- Print-only page header -->
<div class="print-page-header">
    <div style="text-align:center;border-bottom:3px solid #7c3aed;padding-bottom:10px;margin-bottom:14px;">
        <div style="font-size:18px;font-weight:900;color:#4c1d95;letter-spacing:.5px;">SAVANT MOTORS</div>
        <div style="font-size:13px;font-weight:700;color:#374151;margin-top:2px;">Company Vehicle Ledger — Debt Report</div>
        <div style="font-size:10px;color:#64748b;margin-top:3px;">
            Printed: <?= date('d F Y, H:i') ?> &nbsp;|&nbsp;
            <?= $stats['total'] ?> record(s)
            <?php if ($filter_company): ?> &nbsp;|&nbsp; Company: <strong><?= htmlspecialchars($filter_company) ?></strong><?php endif; ?>
            <?php if ($search): ?> &nbsp;|&nbsp; Search: <strong>"<?= htmlspecialchars($search) ?>"</strong><?php endif; ?>
            <?php if ($filter_status !== 'all'): ?> &nbsp;|&nbsp; Status: <strong><?= ucfirst($filter_status) ?></strong><?php endif; ?>
            &nbsp;|&nbsp; Prepared by: <?= htmlspecialchars($user_full_name) ?>
        </div>
        <?php if ($filter_company || $search || $filter_status !== 'all'): ?>
        <div style="font-size:10px;color:#7c3aed;margin-top:5px;font-weight:600;">
            ⚠ Filtered view — totals reflect the <?= $stats['total'] ?> record(s) shown below only
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-card purple">
        <div class="label"><i class="fas fa-building"></i> Companies</div>
        <div class="value purple"><?= number_format($stats['companies_count']) ?></div>
    </div>
    <div class="stat-card red">
        <div class="label"><i class="fas fa-file-invoice-dollar"></i> Total Owed</div>
        <div class="value red">UGX <?= number_format($stats['total_owed']) ?></div>
    </div>
    <div class="stat-card green">
        <div class="label"><i class="fas fa-coins"></i> Collected</div>
        <div class="value green">UGX <?= number_format($stats['total_collected']) ?></div>
    </div>
    <div class="stat-card orange">
        <div class="label"><i class="fas fa-clock"></i> Outstanding</div>
        <div class="value <?= $stats['total_outstanding'] > 0 ? 'red' : 'green' ?>">
            UGX <?= number_format($stats['total_outstanding']) ?>
        </div>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-row no-print">
    <a href="?status=all<?= $filter_company ? '&company=' . urlencode($filter_company) : '' ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
       class="filter-btn <?= $filter_status === 'all' ? 'active' : '' ?>">
        All (<?= $allStats['total'] ?>)
    </a>
    <a href="?status=open<?= $filter_company ? '&company=' . urlencode($filter_company) : '' ?>"
       class="filter-btn <?= $filter_status === 'open' ? 'active' : '' ?>">
        Open (<?= $allStats['open_count'] ?>)
    </a>
    <a href="?status=partial<?= $filter_company ? '&company=' . urlencode($filter_company) : '' ?>"
       class="filter-btn <?= $filter_status === 'partial' ? 'active' : '' ?>">
        Partial (<?= $allStats['partial_count'] ?>)
    </a>
    <a href="?status=settled<?= $filter_company ? '&company=' . urlencode($filter_company) : '' ?>"
       class="filter-btn <?= $filter_status === 'settled' ? 'active' : '' ?>">
        Settled (<?= $allStats['settled_count'] ?>)
    </a>
    <select class="company-filter" onchange="window.location='?status=<?= $filter_status ?>&company='+encodeURIComponent(this.value)+'&search=<?= urlencode($search) ?>'">
        <option value="">All Companies</option>
        <?php foreach ($companies as $co): ?>
        <option value="<?= htmlspecialchars($co) ?>" <?= $filter_company === $co ? 'selected' : '' ?>>
            <?= htmlspecialchars($co) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <form method="GET" style="display:flex;gap:.5rem;flex:1;">
        <input type="hidden" name="status"  value="<?= htmlspecialchars($filter_status) ?>">
        <input type="hidden" name="company" value="<?= htmlspecialchars($filter_company) ?>">
        <input type="text" name="search" class="search-box"
               placeholder="🔍 Search plate, company, work..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="filter-btn active" style="white-space:nowrap;">Search</button>
        <?php if ($search): ?>
        <a href="?status=<?= $filter_status ?>" class="filter-btn">✕ Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Ledger table -->
<div class="card">
    <div class="card-header">
        <span><i class="fas fa-car"></i> Company Vehicle Ledger</span>
        <span class="right"><?= count($vehicles) ?> record(s) shown</span>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Number Plate</th>
                <th>Vehicle Make</th>
                <th>Company in Charge</th>
                <th>Work Done</th>
                <th>Amount Owed</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Progress</th>
                <th>Status</th>
                <th class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($vehicles)): ?>
            <tr>
                <td colspan="12" class="empty-state">
                    <i class="fas fa-car"></i>
                    <strong>No company vehicle records found</strong><br>
                    <span style="font-size:13px;">Click "Add Vehicle Debt" to record a company vehicle that owes Savant Motors.</span>
                </td>
            </tr>
        <?php else:
            $grandOwed = $grandPaid = $grandBal = 0;
            foreach ($vehicles as $i => $v):
                $pct = $v['amount_owed'] > 0 ? round(($v['amount_paid'] / $v['amount_owed']) * 100) : 0;
                $grandOwed += $v['amount_owed'];
                $grandPaid += $v['amount_paid'];
                $grandBal  += $v['balance'];
        ?>
            <tr>
                <td style="color:#94a3b8;font-size:12px;"><?= $i + 1 ?></td>
                <td style="white-space:nowrap;"><?= date('d M Y', strtotime($v['date'])) ?></td>
                <td><span class="plate-badge"><?= htmlspecialchars($v['number_plate']) ?></span></td>
                <td><strong><?= htmlspecialchars($v['vehicle_make']) ?></strong></td>
                <td>
                    <strong><?= htmlspecialchars($v['company_in_charge']) ?></strong>
                </td>
                <td>
                    <div class="work-text" title="<?= htmlspecialchars($v['work_done'] ?? '') ?>">
                        <?= htmlspecialchars($v['work_done'] ?? '—') ?>
                    </div>
                </td>
                <td><strong>UGX <?= number_format($v['amount_owed']) ?></strong></td>
                <td style="color:#059669;">UGX <?= number_format($v['amount_paid']) ?></td>
                <td><strong style="color:#dc2626;">UGX <?= number_format($v['balance']) ?></strong></td>
                <td>
                    <div style="font-size:11px;margin-bottom:2px;"><?= $pct ?>%</div>
                    <div class="progress-wrap"><div class="progress-fill" style="width:<?= $pct ?>%;"></div></div>
                </td>
                <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                <td class="no-print">
                    <?php if ($v['status'] !== 'settled'): ?>
                    <a class="action-btn btn-pay"
                       href="receipt.php?source_type=company_vehicle&source_id=<?= $v['id'] ?>"
                       title="Pay via Receipt">
                        <i class="fas fa-receipt"></i> Pay via Receipt
                    </a>
                    <?php endif; ?>
                    <button class="action-btn btn-edit"
                            onclick="openEditModal(<?= $v['id'] ?>, '<?= htmlspecialchars($v['date'], ENT_QUOTES) ?>', '<?= htmlspecialchars($v['number_plate'], ENT_QUOTES) ?>', '<?= htmlspecialchars($v['vehicle_make'], ENT_QUOTES) ?>', '<?= htmlspecialchars($v['company_in_charge'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($v['work_done'] ?? ''), ENT_QUOTES) ?>', <?= $v['amount_owed'] ?>, <?= $v['amount_paid'] ?>, '<?= htmlspecialchars(addslashes($v['notes'] ?? ''), ENT_QUOTES) ?>')">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <button class="action-btn btn-del"
                            onclick="confirmDelete(<?= $v['id'] ?>, '<?= htmlspecialchars($v['number_plate'], ENT_QUOTES) ?>')">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <button class="action-btn btn-print"
                            onclick="printSingle(<?= $v['id'] ?>, '<?= htmlspecialchars($v['number_plate'], ENT_QUOTES) ?>', '<?= htmlspecialchars($v['vehicle_make'], ENT_QUOTES) ?>', '<?= htmlspecialchars($v['company_in_charge'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($v['work_done'] ?? ''), ENT_QUOTES) ?>', '<?= $v['date'] ?>', <?= $v['amount_owed'] ?>, <?= $v['amount_paid'] ?>, <?= $v['balance'] ?>, '<?= $v['status'] ?>', '<?= htmlspecialchars(addslashes($v['notes'] ?? ''), ENT_QUOTES) ?>')">
                        <i class="fas fa-print"></i> Print
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>

        <!-- Totals row -->
        <tr style="background:#faf5ff;font-weight:700;border-top:2px solid #ddd8fe;">
            <td colspan="6" style="text-align:right;color:#6d28d9;font-size:13px;padding:12px;">TOTALS</td>
            <td style="color:#dc2626;">UGX <?= number_format($grandOwed) ?></td>
            <td style="color:#059669;">UGX <?= number_format($grandPaid) ?></td>
            <td style="color:#dc2626;">UGX <?= number_format($grandBal) ?></td>
            <td colspan="3"></td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
// Build per-company summary for print — based on filtered $vehicles
$companySummary = [];
foreach ($vehicles as $v) {
    $co = $v['company_in_charge'];
    if (!isset($companySummary[$co])) {
        $companySummary[$co] = ['owed' => 0, 'paid' => 0, 'balance' => 0, 'count' => 0];
    }
    $companySummary[$co]['owed']    += $v['amount_owed'];
    $companySummary[$co]['paid']    += $v['amount_paid'];
    $companySummary[$co]['balance'] += $v['balance'];
    $companySummary[$co]['count']++;
}
// When no filter is active, only show companies that still have an outstanding balance
// When a filter IS active, show all matching companies regardless of balance
$isFiltered = ($filter_company !== '' || $search !== '' || $filter_status !== 'all');
if (!$isFiltered) {
    $companySummary = array_filter($companySummary, fn($s) => $s['balance'] > 0);
}
// Sort by outstanding balance descending
uasort($companySummary, fn($a,$b) => $b['balance'] <=> $a['balance']);
?>

<!-- ── Print-only Summary Section ───────────────────────────────────────── -->
<?php if (!empty($companySummary)): ?>
<div class="print-summary" id="printSummary">

    <div class="ps-header">
        <div class="ps-title">
            <?php if ($filter_company): ?>
                Summary: <?= htmlspecialchars($filter_company) ?>
            <?php elseif ($search): ?>
                Summary — Search: "<?= htmlspecialchars($search) ?>"
            <?php else: ?>
                Outstanding Balance Summary — By Company
            <?php endif; ?>
        </div>
        <div class="ps-subtitle">
            <?php if ($isFiltered): ?>
                Filtered view (<?= $stats['total'] ?> records) &mdash; totals match the table above &mdash; <?= date('d F Y') ?>
            <?php else: ?>
                Companies with amounts still owed to Savant Motors as of <?= date('d F Y') ?>
            <?php endif; ?>
        </div>
    </div>

    <table class="ps-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Company in Charge</th>
                <th>Vehicles</th>
                <th>Total Charged</th>
                <th>Total Paid</th>
                <th>Outstanding Balance</th>
                <th>% Paid</th>
            </tr>
        </thead>
        <tbody>
        <?php $rank = 1; foreach ($companySummary as $co => $s):
            $pct = $s['owed'] > 0 ? round(($s['paid'] / $s['owed']) * 100) : 0;
        ?>
            <tr class="<?= $pct > 0 ? 'ps-partial' : 'ps-open' ?>">
                <td class="ps-num"><?= $rank++ ?></td>
                <td class="ps-company"><?= htmlspecialchars($co) ?></td>
                <td class="ps-center"><?= $s['count'] ?></td>
                <td class="ps-money">UGX <?= number_format($s['owed']) ?></td>
                <td class="ps-money ps-green">UGX <?= number_format($s['paid']) ?></td>
                <td class="ps-money ps-red"><strong>UGX <?= number_format($s['balance']) ?></strong></td>
                <td class="ps-center">
                    <span class="ps-pct-pill"><?= $pct ?>%</span>
                    <div class="ps-bar"><div class="ps-bar-fill" style="width:<?= $pct ?>%"></div></div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="ps-totals">
                <td colspan="3" class="ps-total-label">GRAND TOTALS</td>
                <td class="ps-money">UGX <?= number_format(array_sum(array_column($companySummary,'owed'))) ?></td>
                <td class="ps-money ps-green">UGX <?= number_format(array_sum(array_column($companySummary,'paid'))) ?></td>
                <td class="ps-money ps-red"><strong>UGX <?= number_format(array_sum(array_column($companySummary,'balance'))) ?></strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="ps-footer">
        Savant Motors POS &bull; Company Vehicle Ledger &bull; Printed: <?= date('d F Y, H:i') ?> &bull; <?= $stats['total'] ?> record(s) shown
        <?php if ($filter_company): ?> &bull; Company: <?= htmlspecialchars($filter_company) ?><?php endif; ?>
        <?php if ($search): ?> &bull; Search: "<?= htmlspecialchars($search) ?>"<?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- .page-wrap -->

<!-- ── Print Mode Chooser Modal ───────────────────────────────────────── -->
<div id="printChooseModal" class="no-print">
  <div class="print-choose-box">
    <h3><i class="fas fa-print" style="color:#7c3aed;margin-right:6px;"></i> Choose Print Format</h3>
    <p>Select how you'd like to print the Company Vehicle Ledger</p>
    <button class="pmode-btn" onclick="doPrint('detailed')">
      <span class="pmode-icon">📋</span>
      <span>
        <div class="pmode-label">Detailed Report</div>
        <div class="pmode-desc">Full table with all vehicles, work done, progress bars &amp; totals</div>
      </span>
    </button>
    <button class="pmode-btn" onclick="doPrint('summary')">
      <span class="pmode-icon">📊</span>
      <span>
        <div class="pmode-label">Summary Report</div>
        <div class="pmode-desc">Condensed per-company totals — owed, paid, outstanding &amp; % paid</div>
      </span>
    </button>
    <button class="pmode-cancel" onclick="document.getElementById('printChooseModal').classList.remove('active')">✕ Cancel</button>
  </div>
</div>

<!-- ── Add Vehicle Debt Modal ───────────────────────────────────────────── -->
<div id="addModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header">
        <span><i class="fas fa-car"></i> Add Company Vehicle Debt</span>
        <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="form-row">
            <div class="form-group">
                <label>Date *</label>
                <input type="date" name="cv_date" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label>Number Plate *</label>
                <input type="text" name="cv_number_plate" placeholder="e.g. UAA 123B" required
                       style="text-transform:uppercase;" oninput="this.value=this.value.toUpperCase()">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Vehicle Make *</label>
                <input type="text" name="cv_vehicle_make" placeholder="e.g. Toyota Land Cruiser" required>
            </div>
            <div class="form-group">
                <label>Company in Charge *</label>
                <input type="text" name="cv_company_in_charge" placeholder="e.g. Stanbic Bank Uganda"
                       list="companyList" required>
                <datalist id="companyList">
                    <?php foreach ($companies as $co): ?>
                    <option value="<?= htmlspecialchars($co) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <div class="form-group">
            <label>Work Done *</label>
            <textarea name="cv_work_done" rows="3" required
                      placeholder="Describe the service / repair work carried out on the vehicle..."></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Amount Owed (UGX) *</label>
                <input type="number" name="cv_amount_owed" min="1" step="0.01" placeholder="0" required>
            </div>
            <div class="form-group">
                <label>Notes (optional)</label>
                <input type="text" name="cv_notes" placeholder="Any extra info...">
            </div>
        </div>
        <div style="text-align:right;margin-top:1.2rem;">
            <button type="button" class="btn-cancel-modal" onclick="closeModal('addModal')">Cancel</button>
            <button type="submit" name="add_vehicle" class="btn-submit">
                <i class="fas fa-save"></i> Save Record
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Vehicle Debt Modal ───────────────────────────────────────────── -->
<div id="editModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
        <span><i class="fas fa-edit"></i> Edit Vehicle Debt Record</span>
        <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="edit_id" id="editId">
        <div class="form-row">
            <div class="form-group">
                <label>Date *</label>
                <input type="date" name="edit_date" id="editDate" required>
            </div>
            <div class="form-group">
                <label>Number Plate *</label>
                <input type="text" name="edit_number_plate" id="editPlate" required
                       style="text-transform:uppercase;" oninput="this.value=this.value.toUpperCase()">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Vehicle Make *</label>
                <input type="text" name="edit_vehicle_make" id="editMake" required>
            </div>
            <div class="form-group">
                <label>Company in Charge *</label>
                <input type="text" name="edit_company_in_charge" id="editCompany" required
                       list="companyListEdit">
                <datalist id="companyListEdit">
                    <?php foreach ($companies as $co): ?>
                    <option value="<?= htmlspecialchars($co) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <div class="form-group">
            <label>Work Done *</label>
            <textarea name="edit_work_done" id="editWorkDone" rows="3" required></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Amount Owed (UGX) *</label>
                <input type="number" name="edit_amount_owed" id="editAmountOwed" min="0" step="0.01" required>
            </div>
            <div class="form-group">
                <label>Amount Paid (UGX)</label>
                <input type="number" name="edit_amount_paid" id="editAmountPaid" min="0" step="0.01" value="0">
            </div>
        </div>
        <div class="form-group">
            <label>Notes (optional)</label>
            <input type="text" name="edit_notes" id="editNotes" placeholder="Any extra info...">
        </div>
        <div class="alert alert-warning" style="background:#fef3c7;color:#92400e;font-size:12px;margin-bottom:1rem;">
            <i class="fas fa-info-circle"></i> Note: Changing Amount Owed/Paid will automatically recalculate Balance and Status.
        </div>
        <div style="text-align:right;margin-top:1.2rem;">
            <button type="button" class="btn-cancel-modal" onclick="closeModal('editModal')">Cancel</button>
            <button type="submit" name="edit_vehicle" class="btn-submit" style="background:#f59e0b;">
                <i class="fas fa-save"></i> Update Record
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Record Payment Modal (now redirects to receipt.php) ──────────────── -->
<!-- Payment is handled via receipt.php for full double-entry accounting.    -->
<!-- The Pay via Receipt button links directly to receipt.php?source_type=   -->
<!-- company_vehicle&source_id=X, which auto-opens a pre-filled receipt form -->

<!-- Delete Form - FIXED VERSION -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="cv_id" id="deleteId">
    <input type="hidden" name="delete_cv" value="1">
</form>

<script>
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Payment now handled via receipt.php — redirect helper kept for compatibility
function openPayModal(id, plate, company, balance) {
    window.location.href = 'receipt.php?source_type=company_vehicle&source_id=' + id;
}

function openEditModal(id, date, plate, make, company, workDone, amountOwed, amountPaid, notes) {
    document.getElementById('editId').value = id;
    document.getElementById('editDate').value = date;
    document.getElementById('editPlate').value = plate;
    document.getElementById('editMake').value = make;
    document.getElementById('editCompany').value = company;
    document.getElementById('editWorkDone').value = workDone;
    document.getElementById('editAmountOwed').value = amountOwed;
    document.getElementById('editAmountPaid').value = amountPaid;
    document.getElementById('editNotes').value = notes || '';
    document.getElementById('editModal').classList.add('active');
}

// FIXED: Improved delete confirmation function
function confirmDelete(id, plate) {
    if (confirm('⚠️ DELETE RECORD\n\nDelete the record for ' + plate + '?\n\nThis action cannot be undone!\n\nAre you sure?')) {
        document.getElementById('deleteId').value = id;
        // Directly submit the form
        document.getElementById('deleteForm').submit();
    }
}

function printDetailed() {
    document.getElementById('printChooseModal').classList.add('active');
}
function printSummaryOnly() {
    document.getElementById('printChooseModal').classList.add('active');
}
function doPrint(mode) {
    document.getElementById('printChooseModal').classList.remove('active');
    // Remove any previous mode classes
    document.body.classList.remove('print-mode-detailed','print-mode-summary');
    document.body.classList.add('print-mode-' + mode);
    // Also make sure summary section is visible for print if needed
    const summary = document.getElementById('printSummary');
    if (summary) {
        summary.style.display = mode === 'summary' ? 'block' : '';
    }
    setTimeout(() => {
        window.print();
        // Cleanup after print dialog closes
        setTimeout(() => {
            document.body.classList.remove('print-mode-detailed','print-mode-summary');
            if (summary) summary.style.display = '';
        }, 1000);
    }, 150);
}

function printSingle(id, plate, make, company, work, date, owed, paid, balance, status, notes) {
    const fmt = n => 'UGX ' + parseInt(n).toLocaleString();
    const statusColors = {open:'#991b1b', partial:'#92400e', settled:'#065f46'};
    const statusBg    = {open:'#fee2e2', partial:'#fef3c7', settled:'#d1fae5'};
    const pct = owed > 0 ? Math.round((paid / owed) * 100) : 0;
    const dateFormatted = new Date(date).toLocaleDateString('en-UG', {day:'2-digit', month:'long', year:'numeric'});
    const win = window.open('', '_blank', 'width=720,height=700');
    win.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Vehicle Record – ${plate}</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Segoe UI',sans-serif;padding:32px;background:#fff;color:#1e293b;font-size:13px;}
  .header{text-align:center;border-bottom:3px solid #7c3aed;padding-bottom:16px;margin-bottom:24px;}
  .header h1{color:#4c1d95;font-size:20px;margin-bottom:4px;}
  .header p{color:#64748b;font-size:11px;}
  .plate{display:inline-block;background:#1e293b;color:#fff;font-family:monospace;font-size:18px;font-weight:900;letter-spacing:2px;padding:6px 20px;border-radius:8px;border:2px solid #334155;margin:10px 0;}
  table{width:100%;border-collapse:collapse;margin-top:12px;}
  th,td{padding:10px 14px;border:1px solid #e2e8f0;font-size:13px;}
  th{background:#f5f3ff;color:#6d28d9;font-size:11px;text-transform:uppercase;letter-spacing:.5px;width:35%;}
  .badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;background:${statusBg[status] || '#e2e8f0'};color:${statusColors[status] || '#374151'};}
  .amount-owed{color:#dc2626;font-weight:700;}
  .amount-paid{color:#059669;font-weight:700;}
  .balance{color:#dc2626;font-weight:700;}
  .progress-bar{background:#e2e8f0;border-radius:20px;height:10px;width:100%;margin-top:6px;}
  .progress-fill{height:10px;border-radius:20px;background:#7c3aed;width:${pct}%;}
  .footer{margin-top:28px;text-align:center;color:#94a3b8;font-size:11px;border-top:1px solid #e2e8f0;padding-top:12px;}
  @media print{body{padding:16px;}}
</style>
</head>
<body>
<div class="header">
  <h1>Savant Motors – Vehicle Debt Record</h1>
  <p>Printed: ${new Date().toLocaleString('en-UG', {dateStyle:'long', timeStyle:'short'})}</p>
</div>
<div style="text-align:center;margin-bottom:16px;">
  <div class="plate">${plate}</div>
</div>
<table>
  <tr><th>Date</th><td>${dateFormatted}</td></tr>
  <tr><th>Number Plate</th><td><strong>${plate}</strong></td></tr>
  <tr><th>Vehicle Make</th><td>${make}</td></tr>
  <tr><th>Company in Charge</th><td><strong>${company}</strong></td></tr>
  <tr><th>Work Done</th><td>${work || '—'}</td></tr>
  <tr><th>Amount Owed</th><td class="amount-owed">${fmt(owed)}</td></tr>
  <tr><th>Amount Paid</th><td class="amount-paid">${fmt(paid)}</td></tr>
  <tr><th>Balance</th><td class="balance">${fmt(balance)}</td></tr>
  <tr><th>Payment Progress</th>
      <td>${pct}% paid<div class="progress-bar"><div class="progress-fill"></div></div></td>
  </tr>
  <tr><th>Status</th><td><span class="badge">${status.charAt(0).toUpperCase()+status.slice(1)}</span></td></tr>
  ${notes ? `<tr><th>Notes</th><td>${notes}</td></tr>` : ''}
</table>
<div class="footer">Savant Motors POS &bull; Confidential Vehicle Debt Record &bull; Record #${id}</div>
<script>window.onload=()=>{window.print();}<\/script>
</body>
</html>`);
    win.document.close();
}

// Close modals when clicking outside
window.onclick = e => {
    ['addModal','payModal','editModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m && e.target === m) m.classList.remove('active');
    });
};
</script>
</body>
</html>