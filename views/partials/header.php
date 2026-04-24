<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?? 'Savant Motors ERP'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fc 0%, #eef2f9 100%); }

        :root {
            --primary: #2563eb;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            color: white;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-header { padding: 25px 24px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .sidebar-header h2 i { color: #60a5fa; }
        .sidebar-header p { font-size: 11px; opacity: 0.7; margin-top: 5px; }
        
        .sidebar-menu { padding: 20px 0; }
        .sidebar-title { padding: 10px 24px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.5); font-weight: 600; }
        .menu-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-size: 14px; font-weight: 500; }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(255,255,255,0.1); color: white; border-left-color: var(--success); }

        /* Main Content */
        .main-content { margin-left: 280px; padding: 25px 30px; min-height: 100vh; }

        /* Top Bar */
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .page-title h1 { font-size: 28px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 12px; }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--gray); font-size: 14px; margin-top: 5px; }

        .btn { padding: 12px 24px; border: none; border-radius: 14px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; font-family: 'Inter', sans-serif; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4); }
        .btn-secondary { background: white; color: var(--gray); border: 1px solid var(--border); }
        .btn-secondary:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-2px); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-2px); }

        .alert { padding: 15px 20px; border-radius: 14px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #dcfce7; border-left: 4px solid var(--success); color: #166534; }
        .alert-error { background: #fee2e2; border-left: 4px solid var(--danger); color: #991b1b; }

        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-charging-station"></i> SAVANT MOTORS</h2>
            <p>Enterprise Resource Planning</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="/savant/dashboard" class="menu-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="/savant/customers" class="menu-item active"><i class="fas fa-users"></i> CRM</a>
            <a href="/savant/inventory" class="menu-item"><i class="fas fa-boxes"></i> Inventory</a>
            <a href="/savant/purchases" class="menu-item"><i class="fas fa-truck"></i> Purchases</a>
            <div style="margin-top: 30px;">
                <a href="/savant/logout" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">

    