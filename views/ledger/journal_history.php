<?php
// views/ledger/journal_history.php - Journal Entry History
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';

$page    = max(1, (int)($_GET['page']      ?? 1));
$limit   = 20;
$offset  = ($page - 1) * $limit;

$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to   = trim($_GET['date_to']   ?? '');
$filter_search    = trim($_GET['search']    ?? '');
$filter_ref_type  = trim($_GET['ref_type']  ?? '');

$journal_entries = [];
$total_records   = 0;
$total_pages     = 1;
$summary         = ['total_entries'=>0,'total_debit'=>0,'total_credit'=>0];
$refTypes        = [];
$dbError         = null;
$hasJournalTable = false;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables          = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasJournalTable = false;

    $conditions = [];
    $params     = [];

    if ($hasJournalTable) {
        $jlTable = in_array('journal_lines', $tables) ? 'journal_lines'
                 : (in_array('journal_entry_details', $tables) ? 'journal_entry_details' : null);
        $jlJoin  = $jlTable
            ? ($jlTable==='journal_lines'
                ? "LEFT JOIN journal_lines jl ON jl.journal_id = je.id"
                : "LEFT JOIN journal_entry_details jl ON jl.journal_entry_id = je.id")
            : "";
        $jlDebit  = $jlTable ? "COALESCE(SUM(jl.debit),0)"  : "0";
        $jlCredit = $jlTable ? "COALESCE(SUM(jl.credit),0)" : "0";
        $jlCount  = $jlTable ? "COUNT(DISTINCT jl.id)"      : "0";

        if ($filter_date_from) { $conditions[] = "DATE(je.entry_date) >= ?"; $params[] = $filter_date_from; }
        if ($filter_date_to)   { $conditions[] = "DATE(je.entry_date) <= ?"; $params[] = $filter_date_to; }
        if ($filter_search) {
            $conditions[] = "(je.description LIKE ? OR je.reference LIKE ? OR je.journal_number LIKE ?)";
            $s = "%$filter_search%"; $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $where = $conditions ? "WHERE ".implode(" AND ",$conditions) : "";

        $cnt = $conn->prepare("SELECT COUNT(*) FROM journal_entries je $where");
        $cnt->execute($params);
        $total_records = (int)$cnt->fetchColumn();
        $total_pages   = max(1,(int)ceil($total_records/$limit));

        $stmt = $conn->prepare("
            SELECT je.id, je.entry_date, je.journal_number, je.description, je.reference,
                   COALESCE(je.status,'posted') as status, je.created_at,
                   u.full_name as created_by_name,
                   $jlDebit  as total_debit,
                   $jlCredit as total_credit,
                   $jlCount  as entries_count
            FROM journal_entries je
            LEFT JOIN users u ON je.created_by = u.id
            $jlJoin $where
            GROUP BY je.id
            ORDER BY je.entry_date DESC, je.id DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sumStmt = $conn->prepare("
            SELECT COUNT(DISTINCT je.id) as total_entries,
                   $jlDebit  as total_debit,
                   $jlCredit as total_credit
            FROM journal_entries je $jlJoin $where
        ");
        $sumStmt->execute($params);
        $summary = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;

    } else {
        // Fallback: account_ledger
        if ($filter_date_from) { $conditions[] = "DATE(al.transaction_date) >= ?"; $params[] = $filter_date_from; }
        if ($filter_date_to)   { $conditions[] = "DATE(al.transaction_date) <= ?"; $params[] = $filter_date_to; }
        if ($filter_search) {
            $conditions[] = "(al.description LIKE ? OR COALESCE(al.reference_type,'') LIKE ?)";
            $s = "%$filter_search%"; $params[] = $s; $params[] = $s;
        }
        if ($filter_ref_type) { $conditions[] = "al.reference_type = ?"; $params[] = $filter_ref_type; }
        $where = $conditions ? "WHERE ".implode(" AND ",$conditions) : "";

        $cnt = $conn->prepare("SELECT COUNT(*) FROM account_ledger al $where");
        $cnt->execute($params);
        $total_records = (int)$cnt->fetchColumn();
        $total_pages   = max(1,(int)ceil($total_records/$limit));

        $stmt = $conn->prepare("
            SELECT al.id,
                   al.transaction_date as entry_date,
                   CONCAT('AL-', LPAD(al.id,6,'0')) as journal_number,
                   al.description,
                   COALESCE(al.reference_type,'—') as reference,
                   'posted' as status,
                   al.created_at,
                   '' as created_by_name,
                   al.debit  as total_debit,
                   al.credit as total_credit,
                   1 as entries_count
            FROM account_ledger al
            $where
            ORDER BY al.transaction_date DESC, al.id DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sumStmt = $conn->prepare("
            SELECT COUNT(*) as total_entries,
                   COALESCE(SUM(debit),0)  as total_debit,
                   COALESCE(SUM(credit),0) as total_credit
            FROM account_ledger al $where
        ");
        $sumStmt->execute($params);
        $summary = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;

        try {
            $refTypes = $conn->query("SELECT DISTINCT reference_type FROM account_ledger WHERE reference_type IS NOT NULL AND reference_type != '' ORDER BY reference_type")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e2) {}
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$success_message = $_SESSION['success'] ?? null;
$error_message   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

function pUrl(array $extra=[]): string {
    global $page,$filter_date_from,$filter_date_to,$filter_search,$filter_ref_type;
    return '?'.http_build_query(array_merge([
        'page'=>$page,'date_from'=>$filter_date_from,'date_to'=>$filter_date_to,
        'search'=>$filter_search,'ref_type'=>$filter_ref_type
    ],$extra));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal History | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;min-height:100vh;}
        :root{--primary:#1e40af;--primary-light:#3b82f6;--success:#10b981;--danger:#ef4444;--warning:#f59e0b;--border:#e2e8f0;--gray:#64748b;--dark:#0f172a;--bg-light:#f8fafc;}
        .sidebar{position:fixed;left:0;top:0;width:260px;height:100%;background:linear-gradient(180deg,#e0f2fe,#bae6fd);color:#0c4a6e;z-index:1000;overflow-y:auto;}
        .sidebar-header{padding:1.5rem;border-bottom:1px solid rgba(0,0,0,.08);}
        .sidebar-header h2{font-size:1.2rem;font-weight:700;color:#0369a1;}
        .sidebar-header p{font-size:.7rem;opacity:.7;margin-top:.25rem;color:#0284c7;}
        .sidebar-menu{padding:1rem 0;}
        .sidebar-title{padding:.5rem 1.5rem;font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:#0369a1;font-weight:600;}
        .menu-item{padding:.7rem 1.5rem;display:flex;align-items:center;gap:.75rem;color:#0c4a6e;text-decoration:none;transition:all .2s;border-left:3px solid transparent;font-size:.85rem;font-weight:500;}
        .menu-item:hover,.menu-item.active{background:rgba(14,165,233,.2);color:#0284c7;border-left-color:#0284c7;}
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh;}
        .top-bar{background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;border:1px solid var(--border);box-shadow:0 1px 3px rgba(0,0,0,.05);}
        .page-title h1{font-size:1.3rem;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:.5rem;}
        .page-title p{font-size:.75rem;color:var(--gray);margin-top:.25rem;}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.5rem;}
        .stat-card{background:white;border-radius:1rem;padding:1rem;border:1px solid var(--border);display:flex;align-items:center;gap:1rem;}
        .stat-icon{width:46px;height:46px;border-radius:.75rem;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
        .stat-icon.total{background:#3b82f620;color:var(--primary);}
        .stat-icon.debit{background:#10b98120;color:var(--success);}
        .stat-icon.credit{background:#ef444420;color:var(--danger);}
        .stat-icon.amount{background:#8b5cf620;color:#8b5cf6;}
        .stat-info h3{font-size:.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;}
        .stat-info p{font-size:1.1rem;font-weight:800;color:var(--dark);margin-top:.1rem;}
        .filters-card{background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;border:1px solid var(--border);}
        .filters-form{display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;}
        .filter-group{flex:1;min-width:130px;}
        .filter-group label{display:block;font-size:.68rem;font-weight:700;color:var(--gray);margin-bottom:.25rem;text-transform:uppercase;}
        .filter-group input,.filter-group select{width:100%;padding:.48rem .75rem;border:1.5px solid var(--border);border-radius:.5rem;font-size:.83rem;font-family:inherit;}
        .filter-group input:focus,.filter-group select:focus{outline:none;border-color:var(--primary-light);}
        .btn{padding:.48rem 1rem;border-radius:.5rem;font-weight:600;font-size:.84rem;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:all .15s;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-secondary:hover{background:#cbd5e1;}
        .journal-table{background:white;border-radius:1rem;border:1px solid var(--border);overflow:hidden;margin-bottom:1rem;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:.85rem 1rem;background:var(--bg-light);font-size:.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;border-bottom:2px solid var(--border);white-space:nowrap;}
        td{padding:.85rem 1rem;border-bottom:1px solid var(--border);font-size:.84rem;vertical-align:middle;}
        tr:last-child td{border-bottom:none;}
        tbody tr:hover td{background:var(--bg-light);}
        .status-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .55rem;border-radius:2rem;font-size:.68rem;font-weight:700;}
        .status-posted{background:#10b98120;color:#065f46;}
        .status-draft{background:#f59e0b20;color:#92400e;}
        .action-btn{padding:.28rem .65rem;border-radius:.375rem;font-size:.75rem;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem;transition:all .15s;font-weight:600;}
        .action-view{background:#3b82f620;color:var(--primary);}
        .action-view:hover{background:var(--primary);color:white;}
        .pagination{display:flex;justify-content:center;gap:.4rem;padding:1rem;flex-wrap:wrap;border-top:1px solid var(--border);}
        .pagination a,.pagination span{padding:.35rem .7rem;border-radius:.4rem;text-decoration:none;font-size:.8rem;border:1px solid var(--border);background:white;color:var(--dark);}
        .pagination a:hover{background:var(--primary-light);color:white;border-color:var(--primary-light);}
        .pagination .active{background:var(--primary);color:white;border-color:var(--primary);}
        .alert{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
        .alert-error{background:#fee2e2;color:#991b1b;border-left:3px solid var(--danger);}
        .alert-success{background:#dcfce7;color:#166534;border-left:3px solid var(--success);}
        .empty-state{text-align:center;padding:3rem;color:var(--gray);}
        .empty-state i{font-size:2.5rem;margin-bottom:.75rem;display:block;opacity:.4;}
        @media(max-width:768px){.sidebar{left:-260px;}.main-content{margin-left:0;padding:1rem;}.filters-form{flex-direction:column;}}
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
        <a href="journal_entry.php"    class="menu-item">✏️ New Entry</a>
        <a href="journal_history.php"  class="menu-item active">📜 Journal History</a>
        <div style="margin-top:2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-history"></i> Journal History</h1>
            <p><?= $hasJournalTable ? 'Manual journal entries' : 'Ledger transactions from account_ledger' ?></p>
        </div>
        <a href="journal_entry.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Journal Entry</a>
    </div>

    <?php if ($dbError): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon total"><i class="fas fa-book"></i></div><div class="stat-info"><h3>Total Entries</h3><p><?= number_format($summary['total_entries']??0) ?></p></div></div>
        <div class="stat-card"><div class="stat-icon debit"><i class="fas fa-arrow-down"></i></div><div class="stat-info"><h3>Total Debits</h3><p>UGX <?= number_format($summary['total_debit']??0) ?></p></div></div>
        <div class="stat-card"><div class="stat-icon credit"><i class="fas fa-arrow-up"></i></div><div class="stat-info"><h3>Total Credits</h3><p>UGX <?= number_format($summary['total_credit']??0) ?></p></div></div>
        <div class="stat-card"><div class="stat-icon amount"><i class="fas fa-filter"></i></div><div class="stat-info"><h3>Showing</h3><p><?= number_format(count($journal_entries)) ?> of <?= number_format($total_records) ?></p></div></div>
    </div>

    <div class="filters-card">
        <form method="GET" class="filters-form">
            <div class="filter-group"><label>Date From</label><input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>"></div>
            <div class="filter-group"><label>Date To</label><input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>"></div>
            <?php if (!empty($refTypes)): ?>
            <div class="filter-group">
                <label>Type</label>
                <select name="ref_type">
                    <option value="">All Types</option>
                    <?php foreach($refTypes as $rt): ?>
                    <option value="<?= htmlspecialchars($rt) ?>" <?= $filter_ref_type===$rt?'selected':'' ?>><?= htmlspecialchars(ucwords(str_replace('_',' ',$rt))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter-group"><label>Search</label><input type="text" name="search" placeholder="Description, reference…" value="<?= htmlspecialchars($filter_search) ?>"></div>
            <div class="filter-group" style="min-width:auto;display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="journal_history.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="journal-table">
        <table>
            <thead>
                <tr>
                    <th>Entry #</th>
                    <th>Date</th>
                    <th>Reference / Type</th>
                    <th>Description</th>
                    <th style="text-align:right;">Debit (UGX)</th>
                    <th style="text-align:right;">Credit (UGX)</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($journal_entries)): ?>
                <tr><td colspan="8" class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No journal entries found</p>
                    <a href="journal_entry.php" class="btn btn-primary" style="margin-top:.75rem;display:inline-flex;"><i class="fas fa-plus"></i> Create First Entry</a>
                </td></tr>
            <?php else: foreach ($journal_entries as $entry): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($entry['journal_number'] ?? '#'.$entry['id']) ?></strong></td>
                    <td><?= date('d M Y', strtotime($entry['entry_date'])) ?></td>
                    <td><span style="font-size:.75rem;color:var(--gray);"><?= htmlspecialchars($entry['reference'] ?? '—') ?></span></td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($entry['description']??'') ?>">
                        <?= htmlspecialchars(substr($entry['description']??'',0,60)).(strlen($entry['description']??'')>60?'…':'') ?>
                    </td>
                    <td style="text-align:right;color:var(--success);font-weight:600;"><?= ($entry['total_debit']??0)>0 ? number_format($entry['total_debit']) : '—' ?></td>
                    <td style="text-align:right;color:var(--danger);font-weight:600;"><?= ($entry['total_credit']??0)>0 ? number_format($entry['total_credit']) : '—' ?></td>
                    <td>
                        <span class="status-badge <?= ($entry['status']??'posted')==='posted'?'status-posted':'status-draft' ?>">
                            <i class="fas <?= ($entry['status']??'posted')==='posted'?'fa-check-circle':'fa-pen' ?>"></i>
                            <?= ucfirst($entry['status']??'posted') ?>
                        </span>
                    </td>
                    <td>
                        <a href="view_journal.php?id=<?= $entry['id'] ?>&source=<?= $hasJournalTable?'journal':'ledger' ?>" class="action-btn action-view">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page>1): ?><a href="<?= pUrl(['page'=>$page-1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
            <?php
            $s=max(1,$page-2); $e=min($total_pages,$page+2);
            if($s>1) echo '<a href="'.pUrl(['page'=>1]).'">1</a>';
            if($s>2) echo '<span>…</span>';
            for($i=$s;$i<=$e;$i++) echo ($i===$page?'<span class="active">'.$i.'</span>':'<a href="'.pUrl(['page'=>$i]).'">'.$i.'</a>');
            if($e<$total_pages-1) echo '<span>…</span>';
            if($e<$total_pages) echo '<a href="'.pUrl(['page'=>$total_pages]).'">'.$total_pages.'</a>';
            ?>
            <?php if ($page<$total_pages): ?><a href="<?= pUrl(['page'=>$page+1]) ?>">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
