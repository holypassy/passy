<?php
// views/ledger/view_journal.php - View Single Journal / Ledger Entry
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$journal_id = max(0, (int)($_GET['id'] ?? 0));
$source     = $_GET['source'] ?? 'auto'; // 'journal' | 'ledger' | 'auto'

if ($journal_id <= 0) {
    $_SESSION['error'] = 'Invalid entry ID';
    header('Location: journal_history.php');
    exit();
}

$journal     = null;
$debits      = [];
$credits     = [];
$total_debit = 0;
$total_credit = 0;
$dbError     = null;
$useJournal  = false;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables     = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasJournal = in_array('journal_entries', $tables);
    $hasJournalLines = in_array('journal_lines', $tables);
    $useJournal = ($source === 'journal') || ($source === 'auto' && $hasJournal && $hasJournalLines);

    if ($useJournal && $hasJournal) {
        // ── Fetch from journal_entries ────────────────────────────────────
        $stmt = $conn->prepare("
            SELECT je.*,
                   COALESCE(u.full_name, u.username, 'System') as created_by_name
            FROM journal_entries je
            LEFT JOIN users u ON je.created_by = u.id
            WHERE je.id = ?
        ");
        $stmt->execute([$journal_id]);
        $journal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$journal) {
            $_SESSION['error'] = 'Journal entry not found';
            header('Location: journal_history.php');
            exit();
        }

        // detect lines table
        $jlTable = in_array('journal_lines', $tables)        ? 'journal_lines'
                 : (in_array('journal_entry_details', $tables) ? 'journal_entry_details' : null);

        if ($jlTable) {
            $fkCol = ($jlTable === 'journal_lines') ? 'journal_id' : 'journal_entry_id';
            $cols  = $conn->query("SHOW COLUMNS FROM $jlTable")->fetchAll(PDO::FETCH_COLUMN);

            // determine debit/credit structure (some tables use entry_type+amount, others use debit+credit)
            $hasEntryType = in_array('entry_type', $cols);
            $hasDebitCol  = in_array('debit',      $cols);

            $dStmt = $conn->prepare("
                SELECT jl.*,
                       a.account_code, a.account_name, a.account_type
                FROM $jlTable jl
                LEFT JOIN accounts a ON jl.account_id = a.id
                WHERE jl.$fkCol = ?
                ORDER BY a.account_code
            ");
            $dStmt->execute([$journal_id]);
            $lines = $dStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lines as $line) {
                if ($hasEntryType) {
                    if ($line['entry_type'] === 'debit') {
                        $debits[]    = array_merge($line, ['amount' => $line['amount']]);
                        $total_debit += $line['amount'];
                    } else {
                        $credits[]    = array_merge($line, ['amount' => $line['amount']]);
                        $total_credit += $line['amount'];
                    }
                } elseif ($hasDebitCol) {
                    if (($line['debit'] ?? 0) > 0) {
                        $debits[]    = array_merge($line, ['amount' => $line['debit']]);
                        $total_debit += $line['debit'];
                    }
                    if (($line['credit'] ?? 0) > 0) {
                        $credits[]    = array_merge($line, ['amount' => $line['credit']]);
                        $total_credit += $line['credit'];
                    }
                }
            }
        }

    } else {
        // ── Fetch from account_ledger ─────────────────────────────────────
        $stmt = $conn->prepare("
            SELECT al.*,
                   a.account_code, a.account_name, a.account_type
            FROM account_ledger al
            LEFT JOIN accounts a ON al.account_id = a.id
            WHERE al.id = ?
        ");
        $stmt->execute([$journal_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $_SESSION['error'] = 'Ledger entry not found';
            header('Location: journal_history.php');
            exit();
        }

        // Synthesise a journal-like structure from the single ledger row
        $journal = [
            'id'              => $row['id'],
            'journal_number'  => 'AL-'.str_pad($row['id'],6,'0',STR_PAD_LEFT),
            'entry_date'      => $row['transaction_date'],
            'description'     => $row['description'],
            'reference'       => $row['reference_type'].($row['reference_id'] ? ' #'.$row['reference_id'] : ''),
            'status'          => 'posted',
            'created_at'      => $row['created_at'],
            'created_by_name' => 'System',
        ];

        if (($row['debit'] ?? 0) > 0) {
            $debits[]    = array_merge($row, ['amount' => $row['debit']]);
            $total_debit = $row['debit'];
        }
        if (($row['credit'] ?? 0) > 0) {
            $credits[]    = array_merge($row, ['amount' => $row['credit']]);
            $total_credit = $row['credit'];
        }

        // If same account appears for both debit and credit, try to find the paired entry
        // by looking at nearby entries with the same description
        if (empty($debits) || empty($credits)) {
            try {
                $pairedStmt = $conn->prepare("
                    SELECT al.*, a.account_code, a.account_name, a.account_type
                    FROM account_ledger al
                    LEFT JOIN accounts a ON al.account_id = a.id
                    WHERE al.description = ? AND al.transaction_date = ? AND al.id != ?
                    LIMIT 10
                ");
                $pairedStmt->execute([$row['description'], $row['transaction_date'], $row['id']]);
                $paired = $pairedStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($paired as $p) {
                    if (($p['debit']??0)>0 && empty($debits)) {
                        $debits[]    = array_merge($p, ['amount'=>$p['debit']]);
                        $total_debit = $p['debit'];
                    }
                    if (($p['credit']??0)>0 && empty($credits)) {
                        $credits[]    = array_merge($p, ['amount'=>$p['credit']]);
                        $total_credit = $p['credit'];
                    }
                }
            } catch (PDOException $e2) {}
        }
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$is_balanced = (abs($total_debit - $total_credit) < 0.01);

$success_message = $_SESSION['success'] ?? null;
$error_message   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Journal Entry | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;}
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
        .top-bar{background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;border:1px solid var(--border);}
        .page-title h1{font-size:1.3rem;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:.5rem;}
        .page-title p{font-size:.75rem;color:var(--gray);margin-top:.25rem;}
        .journal-card{background:white;border-radius:1rem;border:1px solid var(--border);overflow:hidden;margin-bottom:1.5rem;}
        .journal-header{background:linear-gradient(135deg,var(--primary-light),var(--primary));padding:1rem 1.5rem;color:white;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;}
        .journal-header h2{font-size:1.1rem;font-weight:600;display:flex;align-items:center;gap:.5rem;}
        .status-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .75rem;border-radius:2rem;font-size:.7rem;font-weight:600;}
        .status-posted{background:#10b98130;color:#d1fae5;}
        .status-draft{background:#f59e0b30;color:#fef3c7;}
        .journal-body{padding:1.5rem;}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border);}
        .info-item{display:flex;flex-direction:column;gap:.25rem;}
        .info-label{font-size:.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;}
        .info-value{font-size:.9rem;font-weight:600;color:var(--dark);}
        .entries-table{width:100%;border-collapse:collapse;margin-top:1rem;}
        .entries-table th{text-align:left;padding:.75rem;background:var(--bg-light);font-size:.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;border-bottom:2px solid var(--border);}
        .entries-table td{padding:.75rem;border-bottom:1px solid var(--border);font-size:.85rem;}
        .debit-row{background:#ecfdf5;}
        .credit-row{background:#fef2f2;}
        .amount-debit{color:var(--success);font-weight:700;}
        .amount-credit{color:var(--danger);font-weight:700;}
        .total-row{background:var(--bg-light);font-weight:700;border-top:2px solid var(--border);}
        .balanced{color:var(--success);font-weight:600;}
        .unbalanced{color:var(--danger);font-weight:600;}
        .action-buttons{display:flex;gap:.5rem;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);flex-wrap:wrap;}
        .btn{padding:.55rem 1.1rem;border-radius:.5rem;font-weight:600;font-size:.84rem;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:all .15s;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-secondary:hover{background:#cbd5e1;}
        .alert{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
        .alert-error{background:#fee2e2;color:#991b1b;border-left:3px solid var(--danger);}
        .alert-success{background:#dcfce7;color:#166534;border-left:3px solid var(--success);}
        .source-tag{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:2rem;font-size:.68rem;font-weight:600;background:#dbeafe;color:#1e40af;margin-left:.5rem;}
        @media print{.sidebar,.top-bar .btn,.action-buttons{display:none;}.main-content{margin-left:0;padding:0;}body{background:white;}}
        @media(max-width:768px){.sidebar{left:-260px;}.main-content{margin-left:0;padding:1rem;}}
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header"><h2>📚 SAVANT MOTORS</h2><p>General Ledger System</p></div>
    <div class="sidebar-menu">
        <div class="sidebar-title">MAIN</div>
        <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
        <a href="index.php"            class="menu-item active">📚 General Ledger</a>
        <a href="trial_balance.php"    class="menu-item">⚖️ Trial Balance</a>
        <a href="income_statement.php" class="menu-item">📈 Income Statement</a>
        <a href="balance_sheet.php"    class="menu-item">📊 Balance Sheet</a>
        <a href="journal_entry.php"    class="menu-item">✏️ New Entry</a>
        <a href="journal_history.php"  class="menu-item">📜 Journal History</a>
        <div style="margin-top:2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <h1>
                <i class="fas fa-file-alt"></i>
                <?= $journal ? htmlspecialchars($journal['journal_number'] ?? 'Entry #'.$journal_id) : 'Journal Entry' ?>
                <span class="source-tag"><i class="fas fa-<?= $useJournal?'book':'database' ?>"></i> <?= $useJournal?'Journal':'Ledger' ?></span>
            </h1>
            <p>Transaction details and accounting entries</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print</button>
            <a href="journal_history.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to History</a>
        </div>
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

    <?php if ($journal): ?>
    <div class="journal-card">
        <div class="journal-header">
            <h2><i class="fas fa-journal-whills"></i> Journal Voucher</h2>
            <span class="status-badge <?= ($journal['status']??'posted')==='posted'?'status-posted':'status-draft' ?>">
                <i class="fas <?= ($journal['status']??'posted')==='posted'?'fa-check-circle':'fa-pen' ?>"></i>
                <?= ucfirst($journal['status']??'posted') ?>
            </span>
        </div>
        <div class="journal-body">
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Entry Number</span>
                    <span class="info-value"><?= htmlspecialchars($journal['journal_number'] ?? '#'.str_pad($journal['id'],6,'0',STR_PAD_LEFT)) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Entry Date</span>
                    <span class="info-value"><?= date('F d, Y', strtotime($journal['entry_date'])) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Reference</span>
                    <span class="info-value"><?= htmlspecialchars($journal['reference'] ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created By</span>
                    <span class="info-value"><?= htmlspecialchars($journal['created_by_name'] ?? 'System') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created At</span>
                    <span class="info-value"><?= !empty($journal['created_at']) ? date('d M Y, h:i A', strtotime($journal['created_at'])) : '—' ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Balance Check</span>
                    <span class="info-value <?= $is_balanced?'balanced':'unbalanced' ?>">
                        <i class="fas <?= $is_balanced?'fa-check-circle':'fa-exclamation-triangle' ?>"></i>
                        <?= $is_balanced ? 'Balanced' : 'Unbalanced (Diff: '.number_format(abs($total_debit-$total_credit)).')' ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($journal['description'])): ?>
            <div class="info-item" style="margin-bottom:1.5rem;">
                <span class="info-label">Description</span>
                <div class="info-value" style="background:var(--bg-light);padding:.75rem;border-radius:.5rem;margin-top:.25rem;line-height:1.6;">
                    <?= nl2br(htmlspecialchars($journal['description'])) ?>
                </div>
            </div>
            <?php endif; ?>

            <h3 style="font-size:.8rem;margin-bottom:.75rem;color:var(--gray);display:flex;align-items:center;gap:.5rem;">
                <i class="fas fa-list"></i> Accounting Entries
            </h3>

            <table class="entries-table">
                <thead>
                    <tr>
                        <th>Account Code</th>
                        <th>Account Name</th>
                        <th>Account Type</th>
                        <th style="text-align:right;">Debit (UGX)</th>
                        <th style="text-align:right;">Credit (UGX)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($debits as $d): ?>
                    <tr class="debit-row">
                        <td><?= htmlspecialchars($d['account_code'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($d['account_name'] ?? '—') ?></td>
                        <td><?= ucfirst($d['account_type'] ?? '—') ?></td>
                        <td style="text-align:right;" class="amount-debit"><?= number_format($d['amount'],2) ?></td>
                        <td style="text-align:right;">—</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($credits as $c): ?>
                    <tr class="credit-row">
                        <td><?= htmlspecialchars($c['account_code'] ?? '—') ?></td>
                        <td style="padding-left:1.5rem;"><?= htmlspecialchars($c['account_name'] ?? '—') ?></td>
                        <td><?= ucfirst($c['account_type'] ?? '—') ?></td>
                        <td style="text-align:right;">—</td>
                        <td style="text-align:right;" class="amount-credit"><?= number_format($c['amount'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($debits) && empty($credits)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--gray);">
                        <i class="fas fa-info-circle"></i> No detailed line entries available for this record
                    </td></tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td colspan="3" style="text-align:right;">TOTAL</td>
                        <td style="text-align:right;"><?= number_format($total_debit,2) ?></td>
                        <td style="text-align:right;"><?= number_format($total_credit,2) ?></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align:center;padding:.75rem;">
                            <?php if ($is_balanced): ?>
                            <span class="balanced"><i class="fas fa-check-circle"></i> Entry is balanced</span>
                            <?php else: ?>
                            <span class="unbalanced"><i class="fas fa-exclamation-triangle"></i>
                                Entry is unbalanced! (Difference: UGX <?= number_format(abs($total_debit-$total_credit),2) ?>)
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="action-buttons">
                <a href="journal_history.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to History</a>
                <a href="journal_entry.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Entry</a>
                <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Entry not found or database error occurred.</div>
    <?php endif; ?>
</div>

<script>
// No JS errors — confirmDelete not needed without delete functionality on this read-only view
</script>
</body>
</html>
