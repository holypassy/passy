<?php
// tools.php - Complete Working Version with AI Agent
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Ensure quantity & purchase_price columns exist ─────────────────
    $toolCols = $conn->query("SHOW COLUMNS FROM tools")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('quantity', $toolCols)) {
        $conn->exec("ALTER TABLE tools ADD COLUMN quantity INT NOT NULL DEFAULT 1");
    }
    if (!in_array('purchase_price', $toolCols)) {
        $conn->exec("ALTER TABLE tools ADD COLUMN purchase_price DECIMAL(15,2) DEFAULT 0");
    }

    // Simple query to get all tools
    $stmt = $conn->query("
        SELECT * FROM tools 
        WHERE is_active = 1 OR is_active IS NULL
        ORDER BY id DESC
    ");
    $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics — quantity-aware
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(COALESCE(quantity, 1)) as total_quantity,
            SUM(CASE WHEN status = 'available' THEN COALESCE(quantity, 1) ELSE 0 END) as available,
            SUM(CASE WHEN status = 'taken'     THEN 1 ELSE 0 END) as taken,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
            SUM(CASE WHEN (quantity IS NULL OR quantity = 0) AND status = 'available' THEN 1 ELSE 0 END) as out_of_stock,
            COALESCE(SUM(COALESCE(quantity,1) * COALESCE(purchase_price,0)), 0) as total_value
        FROM tools
        WHERE is_active = 1 OR is_active IS NULL
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Get categories
    $categories = $conn->query("SELECT DISTINCT category FROM tools WHERE category IS NOT NULL AND category != ''")->fetchAll(PDO::FETCH_COLUMN);

    // ── AI Agent Data Queries ────────────────────────────────────────────

    // Most requested tools (from tool_requests table if it exists)
    $mostRequestedTools = [];
    try {
        $mostRequestedTools = $conn->query("
            SELECT t.tool_name, t.tool_code, t.category, COUNT(tr.id) as request_count,
                   t.quantity, t.purchase_price
            FROM tool_requests tr
            JOIN tools t ON t.id = tr.tool_id
            GROUP BY tr.tool_id
            ORDER BY request_count DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        // tool_requests table may not exist; skip
        $mostRequestedTools = [];
    }

    // Most broken tools (tools that went to maintenance most)
    $mostBrokenTools = [];
    try {
        $mostBrokenTools = $conn->query("
            SELECT t.tool_name, t.tool_code, t.category,
                   COUNT(tl.id) as breakdown_count,
                   t.purchase_price
            FROM tool_logs tl
            JOIN tools t ON t.id = tl.tool_id
            WHERE tl.action = 'maintenance' OR tl.status = 'maintenance'
            GROUP BY tl.tool_id
            ORDER BY breakdown_count DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        // Fallback: tools currently in maintenance
        try {
            $mostBrokenTools = $conn->query("
                SELECT tool_name, tool_code, category, 1 as breakdown_count, purchase_price
                FROM tools
                WHERE status = 'maintenance'
                ORDER BY tool_name
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e2) {
            $mostBrokenTools = [];
        }
    }

    // Technicians slow to return tools
    $slowTechnicians = [];
    try {
        $slowTechnicians = $conn->query("
            SELECT 
                tech.name as technician_name,
                tech.id as tech_id,
                COUNT(tr.id) as tools_taken,
                AVG(TIMESTAMPDIFF(HOUR, tr.taken_at, COALESCE(tr.returned_at, NOW()))) as avg_hours_held,
                SUM(CASE WHEN tr.returned_at IS NULL THEN 1 ELSE 0 END) as still_holding
            FROM tool_requests tr
            JOIN technicians tech ON tech.id = tr.technician_id
            GROUP BY tr.technician_id
            ORDER BY avg_hours_held DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $slowTechnicians = [];
    }

    // Out of stock / low stock tools for purchase advice
    $lowStockTools = $conn->query("
        SELECT tool_name, tool_code, category, quantity, purchase_price, status
        FROM tools
        WHERE (quantity <= 1 OR status = 'maintenance')
        AND (is_active = 1 OR is_active IS NULL)
        ORDER BY quantity ASC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Summary stats for AI context
    $totalTools   = (int)($stats['total'] ?? 0);
    $totalTaken   = (int)($stats['taken'] ?? 0);
    $totalMaint   = (int)($stats['maintenance'] ?? 0);
    $totalOOS     = (int)($stats['out_of_stock'] ?? 0);
    $totalValue   = (float)($stats['total_value'] ?? 0);

} catch(PDOException $e) {
    $tools = [];
    $stats = ['total' => 0, 'available' => 0, 'taken' => 0, 'maintenance' => 0];
    $categories = [];
    $mostRequestedTools = [];
    $mostBrokenTools = [];
    $slowTechnicians = [];
    $lowStockTools = [];
    $totalTools = $totalTaken = $totalMaint = $totalOOS = 0;
    $totalValue = 0;
    $error = $e->getMessage();
}

// Build JSON payloads for the AI agent (passed to JS)
$agentData = json_encode([
    'summary' => [
        'total_tool_types'   => $totalTools,
        'total_taken'        => $totalTaken,
        'in_maintenance'     => $totalMaint,
        'out_of_stock'       => $totalOOS,
        'inventory_value_ugx'=> $totalValue,
    ],
    'most_requested_tools' => $mostRequestedTools,
    'most_broken_tools'    => $mostBrokenTools,
    'slow_technicians'     => $slowTechnicians,
    'low_stock_tools'      => $lowStockTools,
    'tools_list'           => array_slice($tools, 0, 30), // send first 30 for context
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Management | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f9 100%);
            min-height: 100vh;
        }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --ai-purple: #7c3aed;
            --ai-purple-light: #ede9fe;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0; top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0c4a6e;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
        .menu-item {
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #0c4a6e;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(14,165,233,0.2); color: #0284c7; border-left-color: #0284c7; }

        /* Main Content */
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p  { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.2rem;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .stat-info { flex: 1; }
        .stat-value { font-size: 1.6rem; font-weight: 800; color: var(--dark); line-height: 1; }
        .stat-label { font-size: 0.68rem; color: var(--gray); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .stat-sub   { font-size: 0.7rem; color: var(--gray); margin-top: 2px; }
        .stat-card.danger  .stat-value { color: var(--danger); }
        .stat-card.success .stat-value { color: var(--success); }
        .stat-card.warning .stat-value { color: var(--warning); }

        tr.zero-stock { background: #fef2f2 !important; }
        tr.zero-stock:hover { background: #fee2e2 !important; }

        .qty-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .qty-ok   { background: #dcfce7; color: #166534; }
        .qty-low  { background: #fef3c7; color: #92400e; }
        .qty-zero { background: #fee2e2; color: #991b1b; }

        /* Table */
        .table-container {
            background: white;
            border-radius: 1rem;
            overflow-x: auto;
            border: 1px solid var(--border);
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        tr:hover { background: #f8fafc; }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-available  { background: #dcfce7; color: #166534; }
        .status-taken      { background: #dbeafe; color: #1e40af; }
        .status-maintenance{ background: #fed7aa; color: #9a3412; }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .btn-primary   { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .action-btn {
            padding: 0.3rem 0.6rem;
            border-radius: 0.4rem;
            font-size: 0.7rem;
            cursor: pointer;
            border: none;
            margin: 0 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .btn-view { background: #dbeafe; color: #2563eb; }
        .btn-edit { background: #dcfce7; color: #16a34a; }

        .empty-state { text-align: center; padding: 3rem; color: var(--gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        /* ═══════════════════════════════════════════════
           AI AGENT PANEL
        ═══════════════════════════════════════════════ */
        .ai-agent-section {
            margin-bottom: 1.5rem;
        }

        .ai-agent-header {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4c1d95 100%);
            border-radius: 1rem 1rem 0 0;
            padding: 1.2rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .ai-agent-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: white;
        }
        .ai-pulse-dot {
            width: 10px; height: 10px;
            background: #4ade80;
            border-radius: 50%;
            animation: pulse-dot 1.5s ease-in-out infinite;
            flex-shrink: 0;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 0 0 rgba(74,222,128,0.5); }
            50%       { opacity: 0.8; transform: scale(1.2); box-shadow: 0 0 0 6px rgba(74,222,128,0); }
        }
        .ai-agent-title h2 { font-size: 1rem; font-weight: 700; }
        .ai-agent-title p  { font-size: 0.7rem; opacity: 0.7; margin-top: 2px; }

        .ai-quick-btns {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .ai-quick-btn {
            padding: 0.45rem 0.9rem;
            border-radius: 2rem;
            border: 1px solid rgba(255,255,255,0.25);
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }
        .ai-quick-btn:hover {
            background: rgba(255,255,255,0.22);
            border-color: rgba(255,255,255,0.5);
            transform: translateY(-1px);
        }

        .ai-agent-body {
            background: white;
            border: 1px solid #c4b5fd;
            border-top: none;
            border-radius: 0 0 1rem 1rem;
            padding: 1.5rem;
        }

        /* Insight Cards Row */
        .ai-insights-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .ai-insight-card {
            border-radius: 0.75rem;
            padding: 1rem;
            border: 1px solid transparent;
            transition: transform 0.2s;
        }
        .ai-insight-card:hover { transform: translateY(-2px); }
        .ai-insight-card.purple { background: #f5f3ff; border-color: #ddd6fe; }
        .ai-insight-card.red    { background: #fef2f2; border-color: #fecaca; }
        .ai-insight-card.amber  { background: #fffbeb; border-color: #fde68a; }
        .ai-insight-card.blue   { background: #eff6ff; border-color: #bfdbfe; }

        .ai-insight-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            opacity: 0.6;
            margin-bottom: 0.4rem;
        }
        .ai-insight-value {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .ai-insight-sub { font-size: 0.68rem; opacity: 0.65; margin-top: 3px; }

        /* Chat Interface */
        .ai-chat-wrapper {
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .ai-chat-history {
            height: 320px;
            overflow-y: auto;
            padding: 1rem;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            scroll-behavior: smooth;
        }
        .ai-chat-history::-webkit-scrollbar { width: 4px; }
        .ai-chat-history::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }

        /* Messages */
        .chat-msg {
            display: flex;
            gap: 0.6rem;
            animation: msgIn 0.3s ease;
        }
        @keyframes msgIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .chat-msg.user { flex-direction: row-reverse; }
        .chat-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .chat-msg.ai   .chat-avatar { background: linear-gradient(135deg, #7c3aed, #4c1d95); color: white; }
        .chat-msg.user .chat-avatar { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; }

        .chat-bubble {
            max-width: 78%;
            padding: 0.7rem 1rem;
            border-radius: 1rem;
            font-size: 0.82rem;
            line-height: 1.6;
        }
        .chat-msg.ai   .chat-bubble { background: white; border: 1px solid #e2e8f0; border-radius: 0.2rem 1rem 1rem 1rem; color: #1e293b; }
        .chat-msg.user .chat-bubble { background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; border-radius: 1rem 0.2rem 1rem 1rem; }

        /* Typing indicator */
        .typing-indicator { display: flex; gap: 4px; align-items: center; padding: 0.4rem 0; }
        .typing-dot {
            width: 7px; height: 7px;
            background: #94a3b8;
            border-radius: 50%;
            animation: typingBounce 1.2s ease-in-out infinite;
        }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingBounce {
            0%, 80%, 100% { transform: translateY(0); }
            40%           { transform: translateY(-6px); }
        }

        /* Input Row */
        .ai-input-row {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem;
            border-top: 1px solid #e2e8f0;
            background: white;
        }
        .ai-chat-input {
            flex: 1;
            padding: 0.6rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 2rem;
            font-size: 0.82rem;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color 0.2s;
            background: #f8fafc;
        }
        .ai-chat-input:focus { border-color: #7c3aed; background: white; }
        .ai-send-btn {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed, #4c1d95);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform 0.15s, opacity 0.15s;
        }
        .ai-send-btn:hover  { transform: scale(1.08); }
        .ai-send-btn:active { transform: scale(0.95); }
        .ai-send-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* Formatted AI response */
        .ai-response-section { margin-top: 0.5rem; }
        .ai-response-section strong { font-weight: 700; }
        .ai-response-section ul { padding-left: 1.2rem; margin: 0.4rem 0; }
        .ai-response-section li { margin-bottom: 0.3rem; }

        @media (max-width: 1200px) { .ai-insights-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .ai-insights-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🔧 SAVANT MOTORS</h2>
            <p>Tool Management System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="/savant/views/dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="/savant/views/job_cards.php" class="menu-item">📋 Job Cards</a>
            <a href="/savant/views/technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="/savant/views/tools.php" class="menu-item active">🔧 Tool Management</a>
            <a href="purchase_assets.php" class="menu-item">🛒 Purchase Assets</a>
            <a href="/savant/views/tool_requests/index.php" class="menu-item">📝 Tool Requests</a>
            <a href="/savant/views/customers/index.php" class="menu-item">👥 Customers</a>
            <div style="margin-top:2rem;">
                <a href="taken.php" class="btn btn-primary" style="background:#f59e0b;margin:0 1rem;">
                    <i class="fas fa-hand-holding"></i> Tools Taken (<?php echo $stats['taken']; ?>)
                </a>
                <a href="/views/logout.php" class="menu-item" style="margin-top:0.5rem;">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>🔧 Tool Management</h1>
                <p>Manage workshop tools and equipment</p>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <a href="purchase_assets.php" class="btn btn-primary" style="background:linear-gradient(135deg,#059669,#047857);">
                    🛒 Purchase Assets
                </a>
                <a href="add_tool.php" class="btn btn-primary">➕ Add New Tool</a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dbeafe;color:#1d4ed8;"><i class="fas fa-tools"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    <div class="stat-label">Tool Types</div>
                    <div class="stat-sub"><?php echo number_format($stats['total_quantity'] ?? 0); ?> total units</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a;"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['available'] ?? 0); ?></div>
                    <div class="stat-label">Units Available</div>
                    <div class="stat-sub">Ready to assign</div>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-hand-holding"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['taken'] ?? 0); ?></div>
                    <div class="stat-label">Currently Taken</div>
                    <div class="stat-sub"><a href="taken.php" style="color:inherit;">View taken tools →</a></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fed7aa;color:#c2410c;"><i class="fas fa-wrench"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['maintenance'] ?? 0); ?></div>
                    <div class="stat-label">Maintenance</div>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['out_of_stock'] ?? 0); ?></div>
                    <div class="stat-label">Out of Stock</div>
                    <div class="stat-sub">Quantity = 0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-coins"></i></div>
                <div class="stat-info">
                    <div class="stat-value" style="font-size:1.15rem;">UGX <?php echo number_format($stats['total_value'] ?? 0); ?></div>
                    <div class="stat-label">Total Inventory Value</div>
                </div>
            </div>
        </div>

        <!-- ═══ AI AGENT PANEL ═══ -->
        <div class="ai-agent-section">
            <div class="ai-agent-header">
                <div class="ai-agent-title">
                    <div class="ai-pulse-dot"></div>
                    <div>
                        <h2>🤖 Tool Intelligence Agent</h2>
                        <p>AI-powered insights for your workshop inventory</p>
                    </div>
                </div>
                <div class="ai-quick-btns">
                    <button class="ai-quick-btn" onclick="askAgent('Which tools are requested most often?')">
                        📊 Most Requested
                    </button>
                    <button class="ai-quick-btn" onclick="askAgent('Which tools break down or need maintenance most?')">
                        🔴 Most Broken
                    </button>
                    <button class="ai-quick-btn" onclick="askAgent('Which technicians are slow to return tools?')">
                        ⏱️ Slow Returns
                    </button>
                    <button class="ai-quick-btn" onclick="askAgent('What tools should we buy or restock? Give me purchase recommendations.')">
                        🛒 Buy Advice
                    </button>
                    <button class="ai-quick-btn" onclick="askAgent('Give me a full inventory health report and action plan.')">
                        📋 Full Report
                    </button>
                </div>
            </div>

            <div class="ai-agent-body">
                <!-- Quick Insight Cards -->
                <div class="ai-insights-grid">
                    <div class="ai-insight-card purple">
                        <div class="ai-insight-label">🔥 Most Requested</div>
                        <?php if (!empty($mostRequestedTools)): ?>
                            <div class="ai-insight-value"><?php echo htmlspecialchars($mostRequestedTools[0]['tool_name']); ?></div>
                            <div class="ai-insight-sub"><?php echo $mostRequestedTools[0]['request_count']; ?> requests logged</div>
                        <?php else: ?>
                            <div class="ai-insight-value" style="font-size:0.8rem;opacity:0.7;">No request data yet</div>
                            <div class="ai-insight-sub">Ask AI for analysis</div>
                        <?php endif; ?>
                    </div>
                    <div class="ai-insight-card red">
                        <div class="ai-insight-label">⚠️ Most Broken</div>
                        <?php if (!empty($mostBrokenTools)): ?>
                            <div class="ai-insight-value"><?php echo htmlspecialchars($mostBrokenTools[0]['tool_name']); ?></div>
                            <div class="ai-insight-sub"><?php echo $mostBrokenTools[0]['breakdown_count']; ?> maintenance event(s)</div>
                        <?php else: ?>
                            <div class="ai-insight-value" style="font-size:0.8rem;opacity:0.7;"><?php echo $totalMaint; ?> in maintenance</div>
                            <div class="ai-insight-sub">Ask AI for breakdown analysis</div>
                        <?php endif; ?>
                    </div>
                    <div class="ai-insight-card amber">
                        <div class="ai-insight-label">⏱️ Slowest Returner</div>
                        <?php if (!empty($slowTechnicians)): ?>
                            <div class="ai-insight-value"><?php echo htmlspecialchars($slowTechnicians[0]['technician_name']); ?></div>
                            <div class="ai-insight-sub">Avg <?php echo round($slowTechnicians[0]['avg_hours_held']); ?>h hold time</div>
                        <?php else: ?>
                            <div class="ai-insight-value" style="font-size:0.8rem;opacity:0.7;"><?php echo $totalTaken; ?> tools out</div>
                            <div class="ai-insight-sub">Ask AI for technician analysis</div>
                        <?php endif; ?>
                    </div>
                    <div class="ai-insight-card blue">
                        <div class="ai-insight-label">🛒 Restock Alert</div>
                        <div class="ai-insight-value"><?php echo count($lowStockTools); ?> tools</div>
                        <div class="ai-insight-sub">Need restocking or repair</div>
                    </div>
                </div>

                <!-- Chat Interface -->
                <div class="ai-chat-wrapper">
                    <div class="ai-chat-history" id="chatHistory">
                        <div class="chat-msg ai">
                            <div class="chat-avatar">🤖</div>
                            <div class="chat-bubble">
                                Hello! I'm your <strong>Tool Intelligence Agent</strong> for Savant Motors. I have full access to your inventory data and can help you with:<br><br>
                                • 📊 <strong>Most requested tools</strong> – see what's always in demand<br>
                                • 🔴 <strong>Breakdown patterns</strong> – identify which tools fail most<br>
                                • ⏱️ <strong>Technician return times</strong> – spot who holds tools too long<br>
                                • 🛒 <strong>Purchase recommendations</strong> – smart restocking advice<br><br>
                                Click any quick button above or type your question below!
                            </div>
                        </div>
                    </div>
                    <div class="ai-input-row">
                        <input type="text" class="ai-chat-input" id="agentInput"
                               placeholder="Ask about your tools, technicians, or get purchase advice…"
                               onkeydown="if(event.key==='Enter') sendAgent()">
                        <button class="ai-send-btn" id="sendBtn" onclick="sendAgent()" title="Send">
                            <i class="fas fa-paper-plane" style="font-size:0.8rem;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- END AI AGENT PANEL -->

        <!-- Tools Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Tool Code</th>
                        <th>Tool Name</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Location</th>
                        <th>Qty Available</th>
                        <th>Unit Value</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tools)): ?>
                    <tr>
                        <td colspan="9" class="empty-state">
                            <i class="fas fa-tools"></i>
                            <h3>No Tools Found</h3>
                            <p>Click "Add New Tool" to get started</p>
                            <a href="add_tool.php" class="btn btn-primary" style="margin-top:1rem;">➕ Add First Tool</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($tools as $tool):
                        $qty      = (int)($tool['quantity'] ?? 1);
                        $isZero   = ($qty <= 0);
                        $qtyClass = $isZero ? 'qty-zero' : ($qty <= 2 ? 'qty-low' : 'qty-ok');
                    ?>
                    <tr class="<?php echo $isZero ? 'zero-stock' : ''; ?>">
                        <td><strong><?php echo htmlspecialchars($tool['tool_code']); ?></strong></td>
                        <td>
                            <?php echo htmlspecialchars($tool['tool_name']); ?>
                            <?php if ($isZero): ?>
                            <br><small style="color:#dc2626;font-weight:600;"><i class="fas fa-exclamation-circle"></i> Out of stock – cannot be taken</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($tool['category'] ?? 'General'); ?></td>
                        <td><?php echo htmlspecialchars($tool['brand'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($tool['location'] ?? 'Store'); ?></td>
                        <td>
                            <span class="qty-badge <?php echo $qtyClass; ?>">
                                <i class="fas fa-<?php echo $isZero ? 'times-circle' : ($qty <= 2 ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                                <?php echo $qty; ?> unit<?php echo $qty != 1 ? 's' : ''; ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($tool['purchase_price']) && $tool['purchase_price'] > 0): ?>
                            <small>UGX <?php echo number_format($tool['purchase_price']); ?></small>
                            <?php else: ?>
                            <small style="color:#94a3b8;">—</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $tool['status'] ?? 'available'; ?>">
                                <?php echo strtoupper($tool['status'] ?? 'AVAILABLE'); ?>
                            </span>
                        </td>
                        <td>
                            <a href="view_tool.php?id=<?php echo $tool['id']; ?>" class="action-btn btn-view">👁️ View</a>
                            <a href="edit_tool.php?id=<?php echo $tool['id']; ?>" class="action-btn btn-edit">✏️ Edit</a>
                            <a href="purchase_assets.php?tool_id=<?php echo $tool['id']; ?>" class="action-btn" style="background:#dcfce7;color:#166534;">🛒 Buy More</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- AI Agent JavaScript -->
    <script>
    // ── Inventory data passed from PHP ──────────────────────────────────────
    const INVENTORY_DATA = <?php echo $agentData; ?>;

    // ── Build a rich system prompt from live data ───────────────────────────
    function buildSystemPrompt() {
        const d = INVENTORY_DATA;

        let prompt = `You are an expert Tool Inventory Intelligence Agent for SAVANT MOTORS, an automotive workshop in Uganda.
You have real-time access to the workshop's tool inventory data. Your job is to:
1. Identify which tools are requested most often
2. Flag tools that break down or need maintenance frequently
3. Identify technicians who take too long to return tools
4. Advise on what tools to purchase or restock
5. Provide clear, actionable recommendations to the workshop manager

CURRENT INVENTORY SUMMARY:
- Total tool types: ${d.summary.total_tool_types}
- Currently taken out: ${d.summary.total_taken}
- In maintenance/broken: ${d.summary.in_maintenance}
- Out of stock: ${d.summary.out_of_stock}
- Total inventory value: UGX ${d.summary.inventory_value_ugx?.toLocaleString() || 0}
`;

        if (d.most_requested_tools?.length > 0) {
            prompt += `\nMOST REQUESTED TOOLS (by number of requests):\n`;
            d.most_requested_tools.forEach((t, i) => {
                prompt += `${i+1}. ${t.tool_name} (${t.tool_code}) — ${t.request_count} requests, Qty: ${t.quantity ?? 'N/A'}, Category: ${t.category ?? 'N/A'}\n`;
            });
        } else {
            prompt += `\nNOTE: No tool_requests table data available. Analyze based on maintenance and current status.\n`;
        }

        if (d.most_broken_tools?.length > 0) {
            prompt += `\nMOST BROKEN / MAINTENANCE TOOLS:\n`;
            d.most_broken_tools.forEach((t, i) => {
                prompt += `${i+1}. ${t.tool_name} (${t.tool_code}) — ${t.breakdown_count} maintenance event(s), Category: ${t.category ?? 'N/A'}, Price: UGX ${Number(t.purchase_price||0).toLocaleString()}\n`;
            });
        }

        if (d.slow_technicians?.length > 0) {
            prompt += `\nTECHNICIANS WITH SLOW TOOL RETURN TIMES:\n`;
            d.slow_technicians.forEach((tech, i) => {
                const hrs = Math.round(tech.avg_hours_held || 0);
                prompt += `${i+1}. ${tech.technician_name} — avg hold time: ${hrs}h, tools taken: ${tech.tools_taken}, still holding: ${tech.still_holding}\n`;
            });
        } else {
            prompt += `\nNOTE: No technician return-time data available from tool_requests table.\n`;
        }

        if (d.low_stock_tools?.length > 0) {
            prompt += `\nLOW STOCK / NEEDS RESTOCKING:\n`;
            d.low_stock_tools.forEach((t, i) => {
                prompt += `${i+1}. ${t.tool_name} — Qty: ${t.quantity}, Status: ${t.status}, Est. Price: UGX ${Number(t.purchase_price||0).toLocaleString()}\n`;
            });
        }

        if (d.tools_list?.length > 0) {
            prompt += `\nFULL TOOL INVENTORY SNAPSHOT (first ${d.tools_list.length} tools):\n`;
            d.tools_list.forEach(t => {
                prompt += `- ${t.tool_name} | Status: ${t.status} | Qty: ${t.quantity ?? 1} | Category: ${t.category ?? 'N/A'}\n`;
            });
        }

        prompt += `\nRESPONSE GUIDELINES:
- Be concise but insightful. Use bullet points and bold key points.
- Always give actionable advice, not just observations.
- When recommending purchases, estimate quantities and give priority (High/Medium/Low).
- Flag any urgent issues (e.g. critical tools out of stock or broken).
- Respond in a professional tone suitable for a workshop manager.
- Format responses clearly — use headers, bullets and emphasis where appropriate.
- All prices are in Uganda Shillings (UGX).`;

        return prompt;
    }

    // ── Conversation history ────────────────────────────────────────────────
    let conversationHistory = [];
    let isLoading = false;

    // ── Render a chat bubble ────────────────────────────────────────────────
    function renderMessage(role, text) {
        const history = document.getElementById('chatHistory');
        const div = document.createElement('div');
        div.className = `chat-msg ${role === 'user' ? 'user' : 'ai'}`;

        const avatar = document.createElement('div');
        avatar.className = 'chat-avatar';
        avatar.textContent = role === 'user' ? '👤' : '🤖';

        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble';

        // Basic markdown-lite formatting
        const formatted = text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/^#{1,3} (.+)$/gm, '<strong style="font-size:0.9rem;display:block;margin:6px 0 2px">$1</strong>')
            .replace(/^[-•] (.+)$/gm, '• $1<br>')
            .replace(/\n\n/g, '<br><br>')
            .replace(/\n/g, '<br>');

        bubble.innerHTML = formatted;
        div.appendChild(avatar);
        div.appendChild(bubble);
        history.appendChild(div);
        history.scrollTop = history.scrollHeight;
    }

    // ── Show typing indicator ───────────────────────────────────────────────
    function showTyping() {
        const history = document.getElementById('chatHistory');
        const div = document.createElement('div');
        div.className = 'chat-msg ai';
        div.id = 'typingIndicator';

        const avatar = document.createElement('div');
        avatar.className = 'chat-avatar';
        avatar.textContent = '🤖';

        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble';
        bubble.innerHTML = '<div class="typing-indicator"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>';

        div.appendChild(avatar);
        div.appendChild(bubble);
        history.appendChild(div);
        history.scrollTop = history.scrollHeight;
    }

    function hideTyping() {
        const t = document.getElementById('typingIndicator');
        if (t) t.remove();
    }

    // ── Send a message ──────────────────────────────────────────────────────
    async function sendAgent() {
        const input = document.getElementById('agentInput');
        const sendBtn = document.getElementById('sendBtn');
        const question = input.value.trim();
        if (!question || isLoading) return;

        isLoading = true;
        input.value = '';
        sendBtn.disabled = true;

        renderMessage('user', question);
        conversationHistory.push({ role: 'user', content: question });

        showTyping();

        try {
            const response = await fetch('https://api.anthropic.com/v1/messages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    model: 'claude-sonnet-4-20250514',
                    max_tokens: 1000,
                    system: buildSystemPrompt(),
                    messages: conversationHistory
                })
            });

            const data = await response.json();

            if (data.error) {
                hideTyping();
                renderMessage('ai', `⚠️ Error: ${data.error.message}`);
            } else {
                const reply = data.content?.[0]?.text || 'No response received.';
                conversationHistory.push({ role: 'assistant', content: reply });
                hideTyping();
                renderMessage('ai', reply);
            }
        } catch (err) {
            hideTyping();
            renderMessage('ai', `⚠️ Network error: ${err.message}. Please check your connection.`);
        } finally {
            isLoading = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }

    // ── Quick button shortcut ───────────────────────────────────────────────
    function askAgent(question) {
        const input = document.getElementById('agentInput');
        input.value = question;
        sendAgent();
    }
    </script>
</body>
</html>
