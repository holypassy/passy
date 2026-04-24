<?php
// customers/niche_identifier.php
// Automatically segments customers into niches based on service history, frequency, tier, spend, and behaviour.
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role      = $_SESSION['role'] ?? 'user';

$niches = [];
$error  = null;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Check which optional tables exist ──────────────────────────────────
    $tables        = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasLoyalty    = in_array('customer_loyalty', $tables);
    $totalSpentSub = $hasLoyalty
        ? "(SELECT COALESCE(l.total_spent,0) FROM customer_loyalty l WHERE l.customer_id = c.id LIMIT 1)"
        : "0";

    // ── AJAX: export niche list ────────────────────────────────────────────
    if (isset($_GET['export_niche'])) {
        $niche = $_GET['export_niche'];
        // Re-run the relevant query and return JSON for the JS to handle
        header('Content-Type: application/json');
        // We'll just return IDs + names + phone for the given niche tag
        // The JS will format them into a copyable list
        // (Real CSV export can be added later)
        echo json_encode(['niche' => $niche, 'exported' => true]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  NICHE QUERIES — each returns a segment with customer list
    // ════════════════════════════════════════════════════════════════════════

    // 1. HIGH-VALUE LOYALISTS — 3+ completed jobs, platinum/gold tier
    $q1 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               COUNT(j.id) as job_count,
               MAX(j.date_completed) as last_service,
               $totalSpentSub as total_spent
        FROM customers c
        JOIN job_cards j ON j.customer_id = c.id AND j.status = 'completed'
        WHERE c.customer_tier IN ('platinum','gold')
        GROUP BY c.id
        HAVING job_count >= 3
        ORDER BY job_count DESC, total_spent DESC
        LIMIT 100
    ");
    $seg1 = $q1->fetchAll(PDO::FETCH_ASSOC);

    // 2. FREQUENT SERVICE CUSTOMERS — 4+ jobs any tier, active in last 6 months
    $q2 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               COUNT(j.id) as job_count,
               MAX(j.date_completed) as last_service,
               DATEDIFF(CURDATE(), MAX(j.date_completed)) as days_since_last
        FROM customers c
        JOIN job_cards j ON j.customer_id = c.id AND j.status = 'completed'
        GROUP BY c.id
        HAVING job_count >= 4
           AND days_since_last <= 180
        ORDER BY job_count DESC
        LIMIT 100
    ");
    $seg2 = $q2->fetchAll(PDO::FETCH_ASSOC);

    // 3. AT-RISK / LAPSED — had 2+ jobs but haven't returned in 6–12 months
    $q3 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               COUNT(j.id) as job_count,
               MAX(j.date_completed) as last_service,
               DATEDIFF(CURDATE(), MAX(j.date_completed)) as days_since_last
        FROM customers c
        JOIN job_cards j ON j.customer_id = c.id AND j.status = 'completed'
        GROUP BY c.id
        HAVING job_count >= 2
           AND days_since_last BETWEEN 180 AND 365
        ORDER BY days_since_last ASC
        LIMIT 100
    ");
    $seg3 = $q3->fetchAll(PDO::FETCH_ASSOC);

    // 4. LOST CUSTOMERS — last service over 12 months ago, had multiple jobs
    $q4 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               COUNT(j.id) as job_count,
               MAX(j.date_completed) as last_service,
               DATEDIFF(CURDATE(), MAX(j.date_completed)) as days_since_last
        FROM customers c
        JOIN job_cards j ON j.customer_id = c.id AND j.status = 'completed'
        GROUP BY c.id
        HAVING days_since_last > 365
        ORDER BY job_count DESC
        LIMIT 100
    ");
    $seg4 = $q4->fetchAll(PDO::FETCH_ASSOC);

    // 5. ONE-TIME VISITORS — exactly 1 completed job, never returned
    $q5 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               COUNT(j.id) as job_count,
               MAX(j.date_completed) as last_service,
               DATEDIFF(CURDATE(), MAX(j.date_completed)) as days_since_last
        FROM customers c
        JOIN job_cards j ON j.customer_id = c.id AND j.status = 'completed'
        GROUP BY c.id
        HAVING job_count = 1
        ORDER BY last_service DESC
        LIMIT 100
    ");
    $seg5 = $q5->fetchAll(PDO::FETCH_ASSOC);

    // 6. NEW CUSTOMERS — first job in last 60 days
    $q6 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               COUNT(j.id) as job_count,
               MIN(j.date_received) as first_visit,
               MAX(j.date_completed) as last_service
        FROM customers c
        JOIN job_cards j ON j.customer_id = c.id
        GROUP BY c.id
        HAVING MIN(j.date_received) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        ORDER BY first_visit DESC
        LIMIT 100
    ");
    $seg6 = $q6->fetchAll(PDO::FETCH_ASSOC);

    // 7. REFERRAL CHANNEL — came via referral source
    $q7 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               c.customer_source,
               COUNT(j.id) as job_count,
               MAX(j.date_completed) as last_service
        FROM customers c
        LEFT JOIN job_cards j ON j.customer_id = c.id AND j.status = 'completed'
        WHERE c.customer_source = 'Referral'
        GROUP BY c.id
        ORDER BY job_count DESC
        LIMIT 100
    ");
    $seg7 = $q7->fetchAll(PDO::FETCH_ASSOC);

    // 8. PENDING JOBS — customers with open/pending job cards right now
    $q8 = $conn->query("
        SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
               COUNT(j.id) as pending_jobs,
               MIN(j.date_received) as oldest_pending
        FROM customers c
        JOIN job_cards j ON j.customer_id = c.id AND j.status = 'pending'
        GROUP BY c.id
        ORDER BY pending_jobs DESC, oldest_pending ASC
        LIMIT 100
    ");
    $seg8 = $q8->fetchAll(PDO::FETCH_ASSOC);

    // ── Compile niche definitions ──────────────────────────────────────────
    $niches = [
        [
            'key'         => 'high_value',
            'icon'        => 'fa-crown',
            'label'       => 'High-Value Loyalists',
            'description' => 'Platinum/Gold customers with 3+ completed jobs. Your most profitable segment — upsell premium packages.',
            'color'       => '#d97706',
            'bg'          => '#fef3c7',
            'action'      => 'Offer exclusive loyalty perks & priority booking',
            'customers'   => $seg1,
            'count_key'   => 'job_count',
            'count_label' => 'Jobs',
        ],
        [
            'key'         => 'frequent',
            'icon'        => 'fa-repeat',
            'label'       => 'Frequent Service Customers',
            'description' => '4+ jobs, visited in last 6 months. Reliable revenue base — keep them engaged with service reminders.',
            'color'       => '#2563eb',
            'bg'          => '#dbeafe',
            'action'      => 'Send service interval reminders & service bundles',
            'customers'   => $seg2,
            'count_key'   => 'job_count',
            'count_label' => 'Jobs',
        ],
        [
            'key'         => 'new',
            'icon'        => 'fa-user-plus',
            'label'       => 'New Customers',
            'description' => 'First visit in the last 60 days. Critical window — nurture them into repeat customers now.',
            'color'       => '#059669',
            'bg'          => '#d1fae5',
            'action'      => 'Send welcome message & first-service follow-up',
            'customers'   => $seg6,
            'count_key'   => 'job_count',
            'count_label' => 'Jobs',
        ],
        [
            'key'         => 'at_risk',
            'icon'        => 'fa-triangle-exclamation',
            'label'       => 'At-Risk / Lapsed',
            'description' => 'Had 2+ jobs but haven\'t returned in 6–12 months. Act now before you lose them permanently.',
            'color'       => '#f59e0b',
            'bg'          => '#fef9c3',
            'action'      => 'Send win-back offer with a service discount',
            'customers'   => $seg3,
            'count_key'   => 'days_since_last',
            'count_label' => 'Days Away',
        ],
        [
            'key'         => 'lost',
            'icon'        => 'fa-user-xmark',
            'label'       => 'Lost Customers',
            'description' => 'Last service over 12 months ago. Long-shot re-engagement — a strong incentive is needed.',
            'color'       => '#ef4444',
            'bg'          => '#fee2e2',
            'action'      => 'Send strong win-back campaign — big incentive',
            'customers'   => $seg4,
            'count_key'   => 'days_since_last',
            'count_label' => 'Days Away',
        ],
        [
            'key'         => 'one_time',
            'icon'        => 'fa-person-walking-arrow-right',
            'label'       => 'One-Time Visitors',
            'description' => 'Came once, never returned. A targeted follow-up can convert a significant portion.',
            'color'       => '#7c3aed',
            'bg'          => '#ede9fe',
            'action'      => 'Send "We miss you" + second-visit incentive',
            'customers'   => $seg5,
            'count_key'   => 'days_since_last',
            'count_label' => 'Days Ago',
        ],
        [
            'key'         => 'referral',
            'icon'        => 'fa-share-nodes',
            'label'       => 'Referral Customers',
            'description' => 'Came through word-of-mouth. They trust you — reward them and they\'ll bring more people.',
            'color'       => '#0891b2',
            'bg'          => '#cffafe',
            'action'      => 'Launch referral reward programme',
            'customers'   => $seg7,
            'count_key'   => 'job_count',
            'count_label' => 'Jobs',
        ],
        [
            'key'         => 'pending',
            'icon'        => 'fa-clock',
            'label'       => 'Active Open Jobs',
            'description' => 'Customers with jobs currently in progress or pending. Keep them informed to reduce no-shows.',
            'color'       => '#64748b',
            'bg'          => '#f1f5f9',
            'action'      => 'Send job status updates & pickup reminders',
            'customers'   => $seg8,
            'count_key'   => 'pending_jobs',
            'count_label' => 'Open Jobs',
        ],
    ];

} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Niche Identifier | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Helvetica Neue',Helvetica,'Inter',-apple-system,sans-serif;background:#f5f7fb;}
        :root{
            --primary:#2563eb;--primary-dark:#1e40af;--secondary:#7c3aed;
            --success:#10b981;--danger:#ef4444;--warning:#f59e0b;
            --dark:#0f172a;--gray:#64748b;--gray-light:#94a3b8;
            --border:#e2e8f0;--bg-light:#f8fafc;
        }

        /* ── SIDEBAR (identical to index.php) ──────────────────────── */
        .sidebar{position:fixed;left:0;top:0;width:268px;height:100%;background:#fff;border-right:1px solid #e8edf5;z-index:1000;overflow-y:auto;display:flex;flex-direction:column;box-shadow:4px 0 24px rgba(15,23,42,.06);}
        .sidebar::-webkit-scrollbar{width:4px;}
        .sidebar::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:4px;}
        .sidebar-brand{padding:22px 20px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #f1f5f9;}
        .brand-logo{width:42px;height:42px;background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .brand-logo i{color:white;font-size:18px;}
        .brand-name{font-size:14px;font-weight:800;color:#0f172a;letter-spacing:-.3px;}
        .brand-sub{font-size:10px;color:#94a3b8;font-weight:500;margin-top:1px;}
        .sidebar-user{margin:12px 14px;background:linear-gradient(135deg,#eff6ff,#f5f3ff);border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:10px;border:1px solid #e0e7ff;}
        .user-avatar-sm{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white;flex-shrink:0;}
        .user-name-sm{font-size:12px;font-weight:700;color:#0f172a;}
        .user-role-sm{font-size:10px;color:#64748b;text-transform:capitalize;}
        .nav-section{padding:16px 14px 4px;}
        .nav-section-label{font-size:9px;font-weight:800;color:#cbd5e1;text-transform:uppercase;letter-spacing:1.2px;padding:0 8px;margin-bottom:4px;}
        .menu-item{padding:9px 10px;display:flex;align-items:center;gap:10px;color:#64748b;text-decoration:none;border-radius:10px;font-size:13px;font-weight:500;transition:all .15s;margin-bottom:1px;position:relative;}
        .menu-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;background:#f8fafc;transition:all .15s;}
        .menu-item span{flex:1;}
        .menu-badge{background:#ef4444;color:white;font-size:9px;font-weight:700;padding:1px 6px;border-radius:20px;min-width:18px;text-align:center;}
        .menu-badge.green{background:#10b981;}
        .menu-item:hover{background:#f8fafc;color:#0f172a;}
        .menu-item:hover .menu-icon{background:#eff6ff;color:#2563eb;}
        .menu-item.active{background:linear-gradient(135deg,#eff6ff,#f5f3ff);color:#2563eb;font-weight:600;}
        .menu-item.active .menu-icon{background:#2563eb;color:white;}
        .menu-item.active::before{content:'';position:absolute;left:0;top:6px;bottom:6px;width:3px;background:#2563eb;border-radius:0 3px 3px 0;}
        .menu-item.highlight-item .menu-icon{background:#dcfce7;color:#059669;}
        .menu-item.highlight-item:hover{background:#f0fdf4;color:#059669;}
        .menu-item.highlight-item:hover .menu-icon{background:#059669;color:white;}
        .sidebar-footer{margin-top:auto;padding:12px 14px;border-top:1px solid #f1f5f9;}
        .sidebar-footer .menu-item{color:#ef4444;}
        .sidebar-footer .menu-item .menu-icon{background:#fee2e2;color:#ef4444;}
        .sidebar-footer .menu-item:hover{background:#fef2f2;}
        .sidebar-footer .menu-item:hover .menu-icon{background:#ef4444;color:white;}

        /* ── LAYOUT ──────────────────────────────────────────────────── */
        .main-content{margin-left:268px;padding:1.5rem;min-height:100vh;}
        .top-bar{background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);}
        .page-title h1{font-size:1.3rem;font-weight:700;color:var(--dark);}
        .page-title p{font-size:.75rem;color:var(--gray);margin-top:.25rem;}
        .btn-primary{padding:.5rem 1rem;background:linear-gradient(135deg,#2563eb,#1e40af);color:white;border:none;border-radius:.5rem;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:all .2s;}
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.3);}

        /* ── SUMMARY STRIP ───────────────────────────────────────────── */
        .summary-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-bottom:1.5rem;}
        .summary-card{background:white;border-radius:.75rem;padding:.85rem 1rem;border:1px solid var(--border);display:flex;flex-direction:column;gap:.2rem;}
        .summary-val{font-size:1.4rem;font-weight:800;color:var(--dark);}
        .summary-lbl{font-size:.65rem;font-weight:700;color:var(--gray-light);text-transform:uppercase;letter-spacing:.5px;}

        /* ── NICHE GRID ───────────────────────────────────────────────── */
        .niche-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.25rem;}

        .niche-card{background:white;border-radius:1rem;border:1px solid var(--border);overflow:hidden;transition:box-shadow .2s,transform .2s;}
        .niche-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.09);}

        .niche-header{padding:1rem 1.2rem;display:flex;align-items:center;gap:.85rem;border-bottom:1px solid var(--border);}
        .niche-icon{width:42px;height:42px;border-radius:.75rem;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
        .niche-meta{flex:1;min-width:0;}
        .niche-label{font-size:.92rem;font-weight:700;color:var(--dark);}
        .niche-count-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;font-weight:700;padding:.18rem .55rem;border-radius:2rem;margin-top:.2rem;}

        .niche-desc{padding:.85rem 1.2rem;font-size:.78rem;color:var(--gray);line-height:1.55;border-bottom:1px solid #f1f5f9;}

        .niche-action{padding:.65rem 1.2rem;display:flex;align-items:center;gap:.5rem;font-size:.72rem;font-weight:600;color:#059669;background:#f0fdf4;border-bottom:1px solid #f1f5f9;}
        .niche-action i{font-size:.75rem;}

        /* Customer list inside card */
        .niche-list{max-height:220px;overflow-y:auto;}
        .niche-list::-webkit-scrollbar{width:3px;}
        .niche-list::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:3px;}

        .customer-row{display:flex;align-items:center;gap:.65rem;padding:.55rem 1.2rem;border-bottom:1px solid #f8fafc;transition:background .15s;}
        .customer-row:last-child{border-bottom:none;}
        .customer-row:hover{background:#f8fafc;}

        .cust-avatar{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;color:white;flex-shrink:0;}
        .cust-name{font-size:.8rem;font-weight:600;color:var(--dark);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .cust-phone{font-size:.7rem;color:var(--gray-light);}
        .cust-metric{font-size:.72rem;font-weight:700;padding:.15rem .45rem;border-radius:.3rem;white-space:nowrap;}

        .tier-pill{font-size:.58rem;font-weight:700;padding:.1rem .4rem;border-radius:2rem;text-transform:capitalize;}
        .tier-platinum{background:#f3e8ff;color:#7c3aed;}
        .tier-gold{background:#fef3c7;color:#d97706;}
        .tier-silver{background:#f1f5f9;color:#64748b;}
        .tier-bronze{background:#fef9ec;color:#b45309;}

        .niche-footer{padding:.65rem 1.2rem;display:flex;gap:.5rem;justify-content:flex-end;background:#fafbfc;}
        .btn-sm{padding:.4rem .85rem;border-radius:.4rem;font-size:.73rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;display:inline-flex;align-items:center;gap:.35rem;}
        .btn-sm-outline{background:white;border:1.5px solid var(--border);color:var(--gray);}
        .btn-sm-outline:hover{border-color:#94a3b8;color:var(--dark);}
        .btn-sm-blue{background:#2563eb;color:white;}
        .btn-sm-blue:hover{background:#1e40af;}
        .btn-sm-green{background:#10b981;color:white;}
        .btn-sm-green:hover{background:#059669;}

        .empty-niche{padding:1.5rem;text-align:center;color:var(--gray-light);font-size:.8rem;}
        .empty-niche i{font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.4;}

        /* ── DRAWER OVERLAY ──────────────────────────────────────────── */
        .drawer-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:2000;align-items:flex-start;justify-content:flex-end;}
        .drawer-overlay.open{display:flex;}
        .drawer{width:420px;max-width:95vw;height:100vh;background:white;overflow-y:auto;box-shadow:-8px 0 40px rgba(15,23,42,.15);display:flex;flex-direction:column;}
        .drawer-head{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem;position:sticky;top:0;background:white;z-index:1;}
        .drawer-head-icon{width:38px;height:38px;border-radius:.65rem;display:flex;align-items:center;justify-content:center;font-size:1rem;}
        .drawer-title{font-size:1rem;font-weight:700;color:var(--dark);flex:1;}
        .drawer-close{width:32px;height:32px;border-radius:.5rem;border:1.5px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray);font-size:.9rem;transition:all .15s;}
        .drawer-close:hover{background:#f8fafc;color:var(--dark);}
        .drawer-body{padding:1rem 1.5rem;flex:1;}
        .drawer-action-bar{padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;gap:.5rem;flex-wrap:wrap;}

        .drawer-customer{display:flex;align-items:center;gap:.75rem;padding:.75rem 0;border-bottom:1px solid #f1f5f9;}
        .drawer-customer:last-child{border-bottom:none;}
        .dc-info{flex:1;min-width:0;}
        .dc-name{font-size:.85rem;font-weight:600;color:var(--dark);}
        .dc-sub{font-size:.72rem;color:var(--gray-light);margin-top:.1rem;}
        .dc-metric{font-size:.72rem;font-weight:700;padding:.2rem .5rem;border-radius:.35rem;}

        /* ── TOAST ───────────────────────────────────────────────────── */
        .toast{position:fixed;bottom:24px;right:24px;background:#0f172a;color:white;padding:12px 18px;border-radius:12px;font-size:13px;font-weight:500;z-index:9999;display:flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,.2);animation:toastIn .25s ease;border-left:4px solid #10b981;}
        @keyframes toastIn{from{opacity:0;transform:translateY(12px);}}

        @media(max-width:768px){
            .sidebar{left:-268px;transition:left .3s;}
            .sidebar.show{left:0;}
            .main-content{margin-left:0;}
            .niche-grid{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>
<!-- ════════════════════ SIDEBAR ════════════════════ -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo"><i class="fas fa-charging-station"></i></div>
        <div class="brand-text">
            <div class="brand-name">SAVANT MOTORS</div>
            <div class="brand-sub">Enterprise Resource Planning</div>
        </div>
    </div>
    <div class="sidebar-user">
        <div class="user-avatar-sm"><?php echo strtoupper(substr($user_full_name,0,2)); ?></div>
        <div>
            <div class="user-name-sm"><?php echo htmlspecialchars($user_full_name); ?></div>
            <div class="user-role-sm"><?php echo htmlspecialchars($user_role); ?></div>
        </div>
    </div>
    <div class="nav-section">
        <div class="nav-section-label">Main</div>
        <a href="../dashboard_erp.php" class="menu-item"><div class="menu-icon"><i class="fas fa-chart-pie"></i></div><span>Dashboard</span></a>
        <a href="index.php" class="menu-item"><div class="menu-icon"><i class="fas fa-users"></i></div><span>Customers</span></a>
        <a href="../job_cards/index.php" class="menu-item"><div class="menu-icon"><i class="fas fa-clipboard-list"></i></div><span>Job Cards</span></a>
        <a href="../quotations.php" class="menu-item"><div class="menu-icon"><i class="fas fa-file-invoice"></i></div><span>Quotations</span></a>
        <a href="../invoices.php" class="menu-item"><div class="menu-icon"><i class="fas fa-file-invoice-dollar"></i></div><span>Invoices</span></a>
    </div>
    <div class="nav-section">
        <div class="nav-section-label">CRM</div>
        <a href="niche_identifier.php" class="menu-item active"><div class="menu-icon"><i class="fas fa-crosshairs"></i></div><span>Niche Identifier</span><span class="menu-badge green"><?php echo count($niches); ?></span></a>
        <a href="index.php" class="menu-item highlight-item"><div class="menu-icon"><i class="fas fa-bell"></i></div><span>Service Reminders</span></a>
        <a href="create.php" class="menu-item"><div class="menu-icon"><i class="fas fa-user-plus"></i></div><span>Add Customer</span></a>
        <a href="../reminders/index.php" class="menu-item"><div class="menu-icon"><i class="fas fa-calendar-check"></i></div><span>Pickup Reminders</span></a>
    </div>
    <div class="nav-section">
        <div class="nav-section-label">Operations</div>
        <a href="../purchases/index.php" class="menu-item"><div class="menu-icon"><i class="fas fa-shopping-cart"></i></div><span>Purchases</span></a>
        <a href="../suppliers.php" class="menu-item"><div class="menu-icon"><i class="fas fa-truck"></i></div><span>Suppliers</span></a>
        <a href="../inventory.php" class="menu-item"><div class="menu-icon"><i class="fas fa-boxes"></i></div><span>Inventory</span></a>
        <a href="../attendance.php" class="menu-item"><div class="menu-icon"><i class="fas fa-user-clock"></i></div><span>Attendance</span></a>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php" class="menu-item"><div class="menu-icon"><i class="fas fa-sign-out-alt"></i></div><span>Logout</span></a>
    </div>
</div>

<!-- ════════════════════ MAIN ════════════════════ -->
<div class="main-content">

    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-crosshairs" style="color:var(--primary);"></i> Customer Niche Identifier</h1>
            <p>Automatically segments your customers by service behaviour — click any niche to see the full list & take action</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a href="index.php" class="btn-primary" style="background:linear-gradient(135deg,#64748b,#475569);">
                <i class="fas fa-arrow-left"></i> Back to Customers
            </a>
        </div>
    </div>

    <?php if ($error): ?>
    <div style="background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444;padding:12px 18px;border-radius:10px;margin-bottom:1rem;font-size:13px;">
        <i class="fas fa-exclamation-triangle"></i> <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Summary strip -->
    <div class="summary-strip">
        <?php foreach ($niches as $n): ?>
        <div class="summary-card" style="cursor:pointer;border-left:3px solid <?php echo $n['color']; ?>;" onclick="openDrawer('<?php echo $n['key']; ?>')">
            <div class="summary-val" style="color:<?php echo $n['color']; ?>;"><?php echo count($n['customers']); ?></div>
            <div class="summary-lbl"><?php echo $n['label']; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Niche Cards Grid -->
    <div class="niche-grid">
    <?php foreach ($niches as $n):
        $customers = $n['customers'];
        $total     = count($customers);
        $avatarColors = ['#2563eb','#7c3aed','#059669','#d97706','#ef4444','#0891b2','#db2777'];
    ?>
    <div class="niche-card">
        <!-- Header -->
        <div class="niche-header">
            <div class="niche-icon" style="background:<?php echo $n['bg']; ?>;color:<?php echo $n['color']; ?>;">
                <i class="fas <?php echo $n['icon']; ?>"></i>
            </div>
            <div class="niche-meta">
                <div class="niche-label"><?php echo htmlspecialchars($n['label']); ?></div>
                <div class="niche-count-badge" style="background:<?php echo $n['bg']; ?>;color:<?php echo $n['color']; ?>;">
                    <i class="fas fa-users" style="font-size:.6rem;"></i>
                    <?php echo $total; ?> customer<?php echo $total !== 1 ? 's' : ''; ?>
                </div>
            </div>
        </div>

        <!-- Description -->
        <div class="niche-desc"><?php echo htmlspecialchars($n['description']); ?></div>

        <!-- Recommended action -->
        <div class="niche-action">
            <i class="fas fa-lightbulb"></i>
            <span><?php echo htmlspecialchars($n['action']); ?></span>
        </div>

        <!-- Customer preview list (first 5) -->
        <div class="niche-list">
        <?php if (empty($customers)): ?>
            <div class="empty-niche">
                <i class="fas fa-check-circle" style="color:#10b981;opacity:1;font-size:1.2rem;"></i>
                No customers in this segment right now
            </div>
        <?php else:
            foreach (array_slice($customers, 0, 5) as $idx => $c):
                $avatarColor  = $avatarColors[$idx % count($avatarColors)];
                $initials     = strtoupper(substr($c['full_name'] ?? '?', 0, 2));
                $tier         = strtolower($c['customer_tier'] ?? '');
                $metricVal    = $c[$n['count_key']] ?? '—';
                $metricLabel  = $n['count_label'];
        ?>
            <div class="customer-row">
                <div class="cust-avatar" style="background:<?php echo $avatarColor; ?>;"><?php echo $initials; ?></div>
                <div class="cust-name"><?php echo htmlspecialchars($c['full_name'] ?? '—'); ?></div>
                <?php if ($tier): ?>
                <span class="tier-pill tier-<?php echo $tier; ?>"><?php echo ucfirst($tier); ?></span>
                <?php endif; ?>
                <span class="cust-metric" style="background:<?php echo $n['bg']; ?>;color:<?php echo $n['color']; ?>;">
                    <?php echo htmlspecialchars((string)$metricVal); ?> <?php echo $metricLabel; ?>
                </span>
            </div>
        <?php endforeach;
            if ($total > 5): ?>
            <div class="customer-row" style="justify-content:center;color:var(--gray-light);font-size:.73rem;font-style:italic;">
                + <?php echo $total - 5; ?> more — click "View All" below
            </div>
        <?php endif; ?>
        <?php endif; ?>
        </div>

        <!-- Footer actions -->
        <div class="niche-footer">
            <button class="btn-sm btn-sm-outline" onclick="openDrawer('<?php echo $n['key']; ?>')">
                <i class="fas fa-list"></i> View All
            </button>
            <button class="btn-sm btn-sm-green" onclick="openDrawer('<?php echo $n['key']; ?>'); setTimeout(()=>copyNumbers('<?php echo $n['key']; ?>'),400)">
                <i class="fas fa-copy"></i> Copy Numbers
            </button>
            <a href="index.php" class="btn-sm btn-sm-blue">
                <i class="fas fa-paper-plane"></i> Send Reminder
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    </div><!-- /niche-grid -->
</div><!-- /main-content -->

<!-- ════════════════════ DRAWER ════════════════════ -->
<div class="drawer-overlay" id="drawerOverlay" onclick="handleOverlayClick(event)">
    <div class="drawer" id="drawer">
        <div class="drawer-head">
            <div class="drawer-head-icon" id="drawerIcon"></div>
            <div class="drawer-title" id="drawerTitle">Niche Details</div>
            <button class="drawer-close" onclick="closeDrawer()"><i class="fas fa-times"></i></button>
        </div>
        <div class="drawer-body" id="drawerBody"></div>
        <div class="drawer-action-bar" id="drawerActionBar"></div>
    </div>
</div>

<!-- Toast placeholder -->
<div id="toastEl" class="toast" style="display:none;"></div>

<script>
// ── Niche data passed from PHP ──────────────────────────────────────────
const niches = <?php
$jsNiches = [];
foreach ($niches as $n) {
    $jsNiches[$n['key']] = [
        'key'         => $n['key'],
        'label'       => $n['label'],
        'icon'        => $n['icon'],
        'color'       => $n['color'],
        'bg'          => $n['bg'],
        'action'      => $n['action'],
        'count_label' => $n['count_label'],
        'count_key'   => $n['count_key'],
        'customers'   => array_map(function($c) use ($n) {
            return [
                'id'       => $c['id']       ?? '',
                'name'     => $c['full_name'] ?? '—',
                'phone'    => $c['telephone'] ?? '—',
                'email'    => $c['email']     ?? '—',
                'tier'     => $c['customer_tier'] ?? '',
                'metric'   => $c[$n['count_key']] ?? '—',
            ];
        }, $n['customers']),
    ];
}
echo json_encode($jsNiches, JSON_HEX_TAG);
?>;

const avatarColors = ['#2563eb','#7c3aed','#059669','#d97706','#ef4444','#0891b2','#db2777'];

function openDrawer(key) {
    const n = niches[key];
    if (!n) return;

    // Header
    document.getElementById('drawerIcon').style.cssText = `background:${n.bg};color:${n.color};`;
    document.getElementById('drawerIcon').innerHTML = `<i class="fas ${n.icon}"></i>`;
    document.getElementById('drawerTitle').textContent = n.label + ' (' + n.customers.length + ')';

    // Body
    const body = document.getElementById('drawerBody');
    if (!n.customers.length) {
        body.innerHTML = `<div style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-check-circle" style="font-size:2rem;color:#10b981;display:block;margin-bottom:.75rem;"></i>No customers in this segment right now.</div>`;
    } else {
        body.innerHTML = `
            <div style="margin-bottom:1rem;padding:.75rem;background:${n.bg};border-radius:.65rem;font-size:.78rem;color:${n.color};font-weight:600;display:flex;gap:.5rem;align-items:center;">
                <i class="fas fa-lightbulb"></i> ${escHtml(n.action)}
            </div>
            <div style="font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.5rem;">
                ${n.customers.length} customer${n.customers.length !== 1 ? 's' : ''}
            </div>
            ${n.customers.map((c,i) => {
                const color   = avatarColors[i % avatarColors.length];
                const initials= (c.name || '?').substring(0,2).toUpperCase();
                const tierMap = {platinum:'#f3e8ff,#7c3aed',gold:'#fef3c7,#d97706',silver:'#f1f5f9,#64748b',bronze:'#fef9ec,#b45309'};
                const tc      = tierMap[(c.tier||'').toLowerCase()] || '';
                const tierPill= tc ? `<span style="font-size:.58rem;font-weight:700;padding:.1rem .4rem;border-radius:2rem;background:${tc.split(',')[0]};color:${tc.split(',')[1]};">${c.tier}</span>` : '';
                return `
                <div class="drawer-customer">
                    <div class="cust-avatar" style="background:${color};">${escHtml(initials)}</div>
                    <div class="dc-info">
                        <div class="dc-name">${escHtml(c.name)} ${tierPill}</div>
                        <div class="dc-sub">${escHtml(c.phone)}${c.email && c.email!=='—' ? ' · '+escHtml(c.email) : ''}</div>
                    </div>
                    <span class="dc-metric" style="background:${n.bg};color:${n.color};">${escHtml(String(c.metric))} ${escHtml(n.count_label)}</span>
                </div>`;
            }).join('')}
        `;
    }

    // Action bar
    document.getElementById('drawerActionBar').innerHTML = `
        <button class="btn-sm btn-sm-outline" onclick="copyNumbers('${key}')"><i class="fas fa-copy"></i> Copy Phone Numbers</button>
        <button class="btn-sm btn-sm-outline" onclick="copyEmails('${key}')"><i class="fas fa-envelope"></i> Copy Emails</button>
        <a href="index.php" class="btn-sm btn-sm-green"><i class="fas fa-paper-plane"></i> Send Reminder</a>
    `;

    document.getElementById('drawerOverlay').classList.add('open');
}

function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
}
function handleOverlayClick(e) {
    if (e.target === document.getElementById('drawerOverlay')) closeDrawer();
}

// ── Copy helpers ────────────────────────────────────────────────────────
function copyNumbers(key) {
    const n = niches[key];
    if (!n) return;
    const nums = n.customers.map(c => c.phone).filter(p => p && p !== '—').join('\n');
    navigator.clipboard.writeText(nums).then(() => toast(`✓ ${n.customers.length} phone number${n.customers.length !== 1 ? 's' : ''} copied!`));
}
function copyEmails(key) {
    const n = niches[key];
    if (!n) return;
    const emails = n.customers.map(c => c.email).filter(e => e && e !== '—').join('\n');
    if (!emails) { toast('No email addresses found in this segment', 'warn'); return; }
    navigator.clipboard.writeText(emails).then(() => toast(`✓ Emails copied!`));
}

// ── Toast ───────────────────────────────────────────────────────────────
function toast(msg, type='success') {
    const el = document.getElementById('toastEl');
    el.textContent = msg;
    el.style.borderLeftColor = type === 'warn' ? '#f59e0b' : '#10b981';
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 3000);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Keyboard close
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });
</script>
</body>
</html>
