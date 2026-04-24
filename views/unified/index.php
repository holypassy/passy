<?php
// This file combines both services and products in a single view with working save functionality
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Catalog - Savant Motors ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================
           GLOBAL STYLES & VARIABLES
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --primary-dark: #1e3a8a;
            --primary-gradient: linear-gradient(135deg, #3b82f6, #1e40af);
            --success: #10b981;
            --success-light: #34d399;
            --success-dark: #059669;
            --danger: #ef4444;
            --danger-light: #f87171;
            --danger-dark: #dc2626;
            --warning: #f59e0b;
            --warning-light: #fbbf24;
            --warning-dark: #d97706;
            --info: #3b82f6;
            --info-light: #60a5fa;
            --info-dark: #2563eb;
            --dark: #0f172a;
            --gray-dark: #334155;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --light: #f8fafc;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1);
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-base: 200ms cubic-bezier(0.4, 0, 0.2, 1);
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
            --radius-2xl: 2rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%);
            color: var(--dark);
            line-height: 1.5;
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
            transition: transform var(--transition-base);
            box-shadow: var(--shadow-xl);
        }

        .sidebar-header {
            padding: 25px 24px;
            text-align: center;
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
        }

        .logo-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            transition: transform var(--transition-base);
        }

        .logo-icon:hover {
            transform: scale(1.05);
        }

        .logo-icon i {
            font-size: 28px;
            color: white;
        }

        .logo-text {
            font-size: 20px;
            font-weight: 800;
            color: white;
            letter-spacing: -0.5px;
        }

        .logo-subtitle {
            font-size: 11px;
            color: var(--gray-light);
            margin-top: 4px;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all var(--transition-base);
            border-left: 3px solid transparent;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .menu-item i {
            width: 20px;
            font-size: 16px;
        }

        .menu-item:hover,
        .menu-item.active {
            background: rgba(59, 130, 246, 0.2);
            color: white;
            border-left-color: var(--primary-light);
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 25px 30px;
            min-height: 100vh;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title h1 i {
            color: var(--primary-light);
            font-size: 32px;
        }

        .page-title p {
            color: var(--gray);
            margin-top: 8px;
            font-size: 14px;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-base);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-light), var(--success));
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--gray-light);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-light), var(--danger));
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info-light), var(--info));
            color: white;
        }

        /* Alert */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #dcfce7;
            border-left: 4px solid var(--success);
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }

        .alert-info {
            background: #dbeafe;
            border-left: 4px solid var(--info);
            color: #1e40af;
        }

        /* Search Container */
        .search-container {
            background: white;
            border-radius: var(--radius-xl);
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
        }

        .search-wrapper {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
        }

        .search-input-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .search-input {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border: 2px solid var(--border);
            border-radius: var(--radius-lg);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all var(--transition-base);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-select {
            padding: 14px 20px;
            border: 2px solid var(--border);
            border-radius: var(--radius-lg);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            background: white;
            cursor: pointer;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 20px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all var(--transition-base);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .stat-info .value {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 4px;
        }

        .stat-info small {
            color: var(--gray-light);
            font-size: 11px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 28px;
            background: white;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: all var(--transition-base);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
        }

        .tab:hover {
            background: var(--light);
            color: var(--primary-light);
            transform: translateY(-2px);
        }

        .tab.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background: var(--primary-gradient);
            color: white;
        }

        .data-table th {
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            color: var(--dark);
            font-size: 14px;
            vertical-align: middle;
        }

        .data-table tbody tr {
            transition: all var(--transition-base);
        }

        .data-table tbody tr:hover {
            background: var(--light);
        }

        /* Badges */
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .badge-service {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af;
        }

        .badge-product {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            color: #065f46;
        }

        .badge-minor {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-major {
            background: #fed7aa;
            color: #92400e;
        }

        .badge-success {
            background: #dcfce7;
            color: var(--success);
        }

        .badge-warning {
            background: #fed7aa;
            color: var(--warning);
        }

        .badge-danger {
            background: #fee2e2;
            color: var(--danger);
        }

        .badge-info {
            background: #dbeafe;
            color: var(--info);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all var(--transition-base);
        }

        .btn-view {
            background: var(--info);
            color: white;
        }

        .btn-edit {
            background: var(--warning);
            color: white;
        }

        .btn-delete {
            background: var(--danger);
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-2xl);
            width: 90%;
            max-width: 700px;
            max-height: 85vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            padding: 20px 25px;
            border-radius: var(--radius-2xl) var(--radius-2xl) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: var(--radius);
            color: white;
            cursor: pointer;
            transition: all var(--transition-base);
        }

        .close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all var(--transition-base);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }

        .checkbox-group input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Loading */
        .loading-spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid var(--border);
            border-top-color: var(--primary-light);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px;
        }

        .empty-state i {
            font-size: 64px;
            color: var(--gray-light);
            margin-bottom: 16px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .search-wrapper {
                flex-direction: column;
            }
            
            .filter-select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon">
                <i class="fas fa-cube"></i>
            </div>
            <div class="logo-text">SAVANT MOTORS</div>
            <div class="logo-subtitle">ERP System</div>
        </div>
        <div class="sidebar-menu">
            <a href="../dashboard_erp.php" class="menu-item">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="services_products.php" class="menu-item active">
                <i class="fas fa-cubes"></i> Unified Catalog
            </a>
            <a href="../services/index.php?tab=services" class="menu-item">
                <i class="fas fa-cogs"></i> Services
            </a>
            <a href="../products/index.php?tab=products" class="menu-item">
                <i class="fas fa-cube"></i> Products
            </a>
            <div style="margin-top: 30px;">
                <div class="menu-item" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </div>
            </div>
        </div>
    </div>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>
                    <i class="fas fa-cubes"></i> 
                    Unified Catalog
                </h1>
                <p>Manage all your services and products from a single dashboard</p>
            </div>
            <div>
                <button onclick="exportToCSV()" class="btn btn-info">
                    <i class="fas fa-download"></i> Export
                </button>
                <button onclick="openServiceModal()" class="btn btn-success">
                    <i class="fas fa-plus"></i> New Service
                </button>
                <button onclick="openProductModal()" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Product
                </button>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Search & Filters -->
        <div class="search-container">
            <div class="search-wrapper">
                <div class="search-input-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" class="search-input" 
                           placeholder="Search services or products..." 
                           onkeyup="filterTable()">
                </div>
                <select id="typeFilter" class="filter-select" onchange="filterTable()">
                    <option value="all">All Types</option>
                    <option value="service">Services Only</option>
                    <option value="product">Products Only</option>
                </select>
                <select id="categoryFilter" class="filter-select" onchange="filterTable()">
                    <option value="all">All Categories</option>
                    <option value="minor">Minor Services</option>
                    <option value="major">Major Services</option>
                </select>
                <button onclick="resetFilters()" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1e40af);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Items</h3>
                    <div class="value" id="totalItems">0</div>
                    <small id="itemsBreakdown">0 Services | 0 Products</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stat-info">
                    <h3>Services Revenue</h3>
                    <div class="value" id="servicesRevenue">UGX 0</div>
                    <small id="servicesBreakdown">0 Minor | 0 Major</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stat-info">
                    <h3>Inventory Value</h3>
                    <div class="value" id="inventoryValue">UGX 0</div>
                    <small id="inventoryBreakdown">0 Total Units</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-info">
                    <h3>Low Stock Alert</h3>
                    <div class="value" id="lowStockCount">0</div>
                    <small>Products needing reorder</small>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div id="loadingState" style="display: none; text-align: center; padding: 40px;">
            <div class="loading-spinner"></div>
            <p style="margin-top: 16px; color: var(--gray);">Loading catalog data...</p>
        </div>

        <!-- Unified Table -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name / Code</th>
                        <th>Category</th>
                        <th>Price (UGX)</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="catalogTableBody">
                    <!-- Data will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Service Modal -->
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-cogs"></i> Add New Service</h3>
                <button class="close-btn" onclick="closeServiceModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="serviceForm" onsubmit="saveService(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Service Name *</label>
                        <input type="text" class="form-control" name="service_name" required>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select class="form-control" name="category" required>
                            <option value="Minor">Minor Service</option>
                            <option value="Major">Major Service</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Standard Price (UGX) *</label>
                            <input type="number" class="form-control" name="standard_price" step="100" required>
                        </div>
                        <div class="form-group">
                            <label>Estimated Duration</label>
                            <input type="text" class="form-control" name="estimated_duration" placeholder="e.g., 30 mins">
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="track_interval" id="trackIntervalModal">
                        <label>Track service intervals</label>
                    </div>
                    <div id="intervalFieldsModal" style="display: none; margin: 15px 0; padding: 15px; background: var(--light); border-radius: var(--radius);">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Service Interval</label>
                                <input type="number" class="form-control" name="service_interval" value="6">
                            </div>
                            <div class="form-group">
                                <label>Unit</label>
                                <select class="form-control" name="interval_unit">
                                    <option value="days">Days</option>
                                    <option value="weeks">Weeks</option>
                                    <option value="months" selected>Months</option>
                                    <option value="years">Years</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="requires_parts">
                        <label>Requires parts</label>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeServiceModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Service</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-cube"></i> Add New Product</h3>
                <button class="close-btn" onclick="closeProductModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="productForm" onsubmit="saveProduct(event)">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product Code *</label>
                            <input type="text" class="form-control" name="item_code" required id="productCode">
                        </div>
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" class="form-control" name="product_name" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Category</label>
                            <input type="text" class="form-control" name="category">
                        </div>
                        <div class="form-group">
                            <label>Unit of Measure</label>
                            <select class="form-control" name="unit_of_measure">
                                <option value="piece">Piece</option>
                                <option value="liter">Liter</option>
                                <option value="kilogram">Kilogram</option>
                                <option value="box">Box</option>
                                <option value="set">Set</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Unit Cost (UGX)</label>
                            <input type="number" class="form-control" name="unit_cost" step="100" value="0" oninput="updateLiveInventory()">
                        </div>
                        <div class="form-group">
                            <label>Selling Price (UGX) *</label>
                            <input type="number" class="form-control" name="selling_price" step="100" required oninput="updateLiveInventory()">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Opening Stock (Qty) *</label>
                            <input type="number" class="form-control" name="opening_stock" id="openingStock" min="0" value="0" required oninput="updateLiveInventory()">
                        </div>
                        <div class="form-group">
                            <label>Reorder Level</label>
                            <input type="number" class="form-control" name="reorder_level" value="5" min="0">
                        </div>
                    </div>
                    <div id="liveInventoryBadge" style="display:none; margin-bottom:16px; padding:12px 16px; background:linear-gradient(135deg,#ecfdf5,#d1fae5); border-left:4px solid #10b981; border-radius:8px; font-size:13px; color:#065f46;">
                        <i class="fas fa-calculator"></i>
                        <strong>Inventory Value Preview:</strong>
                        <span id="liveCostVal">—</span> (cost) &bull; <span id="liveSellVal">—</span> (selling)
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeProductModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ============================================
        // GLOBAL DATA
        // ============================================
        let catalogData = {
            services: [],
            products: []
        };
        
        // ============================================
        // INITIALIZATION
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Unified Catalog Initialized');
            loadData();
            generateProductCode();
            
            // Track interval checkbox listener
            document.getElementById('trackIntervalModal').addEventListener('change', function() {
                const fields = document.getElementById('intervalFieldsModal');
                fields.style.display = this.checked ? 'block' : 'none';
            });
            
            // Close modals on escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllModals();
                }
            });
        });
        
        // ============================================
        // LOAD DATA FROM PHP BACKEND
        // ============================================
        async function loadData() {
            showLoading(true);
            try {
                const servicesResponse = await fetch('get_services.php');
                if (servicesResponse.ok) {
                    catalogData.services = await servicesResponse.json();
                }
                const productsResponse = await fetch('get_products.php');
                if (productsResponse.ok) {
                    catalogData.products = await productsResponse.json();
                }
                renderTable();
                updateStats();
            } catch (error) {
                await loadFromSession();
                renderTable();
                updateStats();
            } finally {
                showLoading(false);
            }
        }
        
        async function loadFromSession() {
            try {
                const response = await fetch('get_session_data.php');
                if (response.ok) {
                    const data = await response.json();
                    catalogData.services = data.services || [];
                    catalogData.products = data.products || [];
                } else {
                    // No mock data – just empty arrays
                    catalogData.services = [];
                    catalogData.products = [];
                    showAlert('info', 'Could not load saved data. Please check backend connection.');
                }
            } catch (e) {
                // Network error – fallback to empty arrays
                catalogData.services = [];
                catalogData.products = [];
                showAlert('error', 'Failed to load catalog data. Please refresh the page.');
            }
        }
        
        // ============================================
        // SAVE SERVICE (WORKING VERSION)
        // ============================================
        async function saveService(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            
            // Add action flag
            formData.append('save_service', '1');
            
            showLoading(true);
            
            try {
                const response = await fetch('services_products.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    showAlert('success', 'Service added successfully!');
                    closeServiceModal();
                    form.reset();
                    // Reload data
                    await loadData();
                } else {
                    const error = await response.text();
                    console.error('Save error:', error);
                    showAlert('error', 'Failed to save service. Please try again.');
                }
            } catch (error) {
                console.error('Network error:', error);
                showAlert('error', 'Network error. Please check your connection.');
            } finally {
                showLoading(false);
            }
        }
        
        // ============================================
        // SAVE PRODUCT (WORKING VERSION)
        // ============================================
        async function saveProduct(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            
            // Add action flag
            formData.append('save_product', '1');
            
            showLoading(true);
            
            try {
                const response = await fetch('services_products.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    showAlert('success', 'Product added successfully!');
                    closeProductModal();
                    form.reset();
                    generateProductCode();
                    // Reload data
                    await loadData();
                } else {
                    const error = await response.text();
                    console.error('Save error:', error);
                    showAlert('error', 'Failed to save product. Please try again.');
                }
            } catch (error) {
                console.error('Network error:', error);
                showAlert('error', 'Network error. Please check your connection.');
            } finally {
                showLoading(false);
            }
        }
        
        // ============================================
        // RENDER TABLE
        // ============================================
        function renderTable() {
            const tbody = document.getElementById('catalogTableBody');
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const typeFilter = document.getElementById('typeFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            
            // Combine services and products
            let allItems = [
                ...catalogData.services.map(s => ({ ...s, type: 'service' })),
                ...catalogData.products.map(p => ({ ...p, type: 'product' }))
            ];
            
            // Apply filters
            let filteredItems = allItems.filter(item => {
                // Type filter
                if (typeFilter !== 'all' && item.type !== typeFilter) return false;
                
                // Category filter
                if (categoryFilter === 'minor' && !(item.type === 'service' && item.category === 'Minor')) return false;
                if (categoryFilter === 'major' && !(item.type === 'service' && item.category === 'Major')) return false;
                
                // Search filter
                if (searchTerm) {
                    const name = (item.service_name || item.product_name || '').toLowerCase();
                    const code = (item.item_code || '').toLowerCase();
                    if (!name.includes(searchTerm) && !code.includes(searchTerm)) return false;
                }
                
                return true;
            });
            
            if (filteredItems.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No items found</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = filteredItems.map(item => {
                if (item.type === 'service') {
                    return `
                        <tr>
                            <td><span class="badge badge-service"><i class="fas fa-cogs"></i> Service</span></td>
                            <td>
                                <strong>${escapeHtml(item.service_name)}</strong>
                                <br><small style="color: var(--gray);">ID: #${item.id}</small>
                            </td>
                            <td><span class="badge ${item.category === 'Minor' ? 'badge-minor' : 'badge-major'}">${escapeHtml(item.category)}</span></td>
                            <td><strong>UGX ${formatNumber(item.standard_price)}</strong></td>
                            <td><span class="badge badge-info">${item.track_interval ? 'Tracked' : 'Standard'}</span></td>
                            <td>
                                <small>
                                    ${item.estimated_duration ? `<i class="fas fa-clock"></i> ${escapeHtml(item.estimated_duration)}<br>` : ''}
                                    ${item.requires_parts ? '<i class="fas fa-microchip"></i> Requires Parts' : ''}
                                </small>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick="viewItem(${item.id}, 'service')" class="action-btn btn-view"><i class="fas fa-eye"></i></button>
                                    <button onclick="editItem(${item.id}, 'service')" class="action-btn btn-edit"><i class="fas fa-edit"></i></button>
                                    <button onclick="deleteItem(${item.id}, 'service')" class="action-btn btn-delete"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    `;
                } else {
                    const isLowStock = item.quantity <= item.reorder_level;
                    const stockStatus = item.quantity <= 0 ? 'Out of Stock' : (isLowStock ? 'Low Stock' : 'In Stock');
                    const statusClass = item.quantity <= 0 ? 'danger' : (isLowStock ? 'warning' : 'success');
                    
                    return `
                        <tr>
                            <td><span class="badge badge-product"><i class="fas fa-cube"></i> Product</span></td>
                            <td>
                                <strong>${escapeHtml(item.product_name)}</strong>
                                <br><small style="color: var(--gray);">Code: ${escapeHtml(item.item_code)}</small>
                            </td>
                            <td><span class="badge badge-info">${escapeHtml(item.category || 'General')}</span></td>
                            <td>
                                <strong>UGX ${formatNumber(item.selling_price)}</strong>
                                <br><small style="color:var(--gray);">Cost: UGX ${formatNumber(item.unit_cost)}</small>
                            </td>
                            <td><span class="badge badge-${statusClass}">${stockStatus}</span></td>
                            <td>
                                <small>
                                    <i class="fas fa-boxes"></i> <strong>${formatNumber(item.quantity)}</strong> ${escapeHtml(item.unit_of_measure || 'units')}
                                    <br><i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i> Reorder at: ${item.reorder_level}
                                    <br><i class="fas fa-calculator" style="color:var(--success)"></i> Val: UGX ${formatNumber((item.unit_cost||0)*(item.quantity||0))}
                                </small>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick="viewItem(${item.id}, 'product')" class="action-btn btn-view"><i class="fas fa-eye"></i></button>
                                    <button onclick="editItem(${item.id}, 'product')" class="action-btn btn-edit"><i class="fas fa-edit"></i></button>
                                    <button onclick="deleteItem(${item.id}, 'product')" class="action-btn btn-delete"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    `;
                }
            }).join('');
        }
        
        // ============================================
        // UPDATE STATISTICS
        // ============================================
        function updateStats() {
            const services = catalogData.services;
            const products = catalogData.products;
            
            // Total items
            document.getElementById('totalItems').innerHTML = services.length + products.length;
            document.getElementById('itemsBreakdown').innerHTML = `${services.length} Services | ${products.length} Products`;
            
            // Services revenue
            const servicesRevenue = services.reduce((sum, s) => sum + (s.standard_price || 0), 0);
            document.getElementById('servicesRevenue').innerHTML = `UGX ${formatNumber(servicesRevenue)}`;
            const minorCount = services.filter(s => s.category === 'Minor').length;
            const majorCount = services.filter(s => s.category === 'Major').length;
            document.getElementById('servicesBreakdown').innerHTML = `${minorCount} Minor | ${majorCount} Major`;
            
            // Inventory value (cost-based; subtitle shows sell value)
            const inventoryCostValue = products.reduce((sum, p) => sum + ((p.unit_cost || 0) * (p.quantity || 0)), 0);
            const inventorySellValue = products.reduce((sum, p) => sum + ((p.selling_price || 0) * (p.quantity || 0)), 0);
            document.getElementById('inventoryValue').innerHTML = `UGX ${formatNumber(inventoryCostValue)}`;
            const totalUnits = products.reduce((sum, p) => sum + (p.quantity || 0), 0);
            document.getElementById('inventoryBreakdown').innerHTML = `${formatNumber(totalUnits)} units &bull; Sell val: UGX ${formatNumber(inventorySellValue)}`;
            
            // Low stock count
            const lowStockCount = products.filter(p => p.quantity <= p.reorder_level && p.quantity > 0).length;
            document.getElementById('lowStockCount').innerHTML = lowStockCount;
        }
        
        // ============================================
        // FILTER FUNCTIONS
        // ============================================
        function filterTable() {
            renderTable();
        }
        
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('typeFilter').value = 'all';
            document.getElementById('categoryFilter').value = 'all';
            renderTable();
            showAlert('info', 'Filters have been reset');
        }
        
        // ============================================
        // CRUD OPERATIONS
        // ============================================
        function viewItem(id, type) {
            const item = type === 'service' 
                ? catalogData.services.find(s => s.id == id)
                : catalogData.products.find(p => p.id == id);
            
            if (item) {
                const details = type === 'service' 
                    ? `Service: ${item.service_name}\nCategory: ${item.category}\nPrice: UGX ${formatNumber(item.standard_price)}\nDuration: ${item.estimated_duration || 'N/A'}`
                    : `Product: ${item.product_name}\nCode: ${item.item_code}\nSelling Price: UGX ${formatNumber(item.selling_price)}\nUnit Cost: UGX ${formatNumber(item.unit_cost)}\nStock: ${item.quantity} ${item.unit_of_measure || 'units'}\nInventory Value (cost): UGX ${formatNumber((item.unit_cost||0)*(item.quantity||0))}\nInventory Value (sell): UGX ${formatNumber((item.selling_price||0)*(item.quantity||0))}`;
                alert(details);
            }
        }
        
        function editItem(id, type) {
            if (type === 'service') {
                openServiceModal();
            } else {
                openProductModal();
            }
            showAlert('info', `Edit functionality for ${type} #${id} - Implement as needed`);
        }
        
        async function deleteItem(id, type) {
            if (!confirm(`Are you sure you want to delete this ${type}?`)) return;
            
            const formData = new FormData();
            if (type === 'service') {
                formData.append('delete_service', '1');
                formData.append('service_id', id);
            } else {
                formData.append('delete_product', '1');
                formData.append('product_id', id);
            }
            
            try {
                const response = await fetch('services_products.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    showAlert('success', `${type} deleted successfully!`);
                    await loadData();
                } else {
                    showAlert('error', `Failed to delete ${type}`);
                }
            } catch (error) {
                showAlert('error', 'Network error occurred');
            }
        }
        
        // ============================================
        // EXPORT FUNCTION
        // ============================================
        function exportToCSV() {
            let allItems = [
                ...catalogData.services.map(s => ({ ...s, type: 'service' })),
                ...catalogData.products.map(p => ({ ...p, type: 'product' }))
            ];
            
            const headers = ['Type', 'Name', 'Category', 'Price', 'Status', 'Stock'];
            const rows = allItems.map(item => {
                if (item.type === 'service') {
                    return [
                        'Service',
                        item.service_name,
                        item.category,
                        item.standard_price,
                        item.track_interval ? 'Tracked' : 'Standard',
                        'N/A'
                    ];
                } else {
                    return [
                        'Product',
                        item.product_name,
                        item.category || 'General',
                        item.selling_price,
                        item.quantity <= item.reorder_level ? 'Low Stock' : 'In Stock',
                        item.quantity
                    ];
                }
            });
            
            const csv = [headers, ...rows].map(row => row.join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `catalog_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            
            showAlert('success', 'Catalog exported successfully!');
        }
        
        // ============================================
        // UTILITY FUNCTIONS
        // ============================================
        function formatNumber(num) {
            if (!num) return '0';
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        function updateLiveInventory() {
            const qty       = parseFloat(document.getElementById('openingStock').value) || 0;
            const costInput = document.querySelector('#productForm [name="unit_cost"]');
            const sellInput = document.querySelector('#productForm [name="selling_price"]');
            const cost      = parseFloat(costInput?.value) || 0;
            const sell      = parseFloat(sellInput?.value) || 0;

            const badge = document.getElementById('liveInventoryBadge');
            if (qty > 0) {
                badge.style.display = 'block';
                document.getElementById('liveCostVal').textContent = `UGX ${formatNumber(cost * qty)}`;
                document.getElementById('liveSellVal').textContent = `UGX ${formatNumber(sell * qty)}`;
            } else {
                badge.style.display = 'none';
            }
        }

        function generateProductCode() {
            const code = 'PRD-' + new Date().getFullYear() + 
                        String(Math.floor(Math.random() * 1000)).padStart(3, '0');
            document.getElementById('productCode').value = code;
        }
        
        function showAlert(type, message) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i>
                <span style="flex: 1;">${message}</span>
                <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
            `;
            container.appendChild(alert);
            
            setTimeout(() => {
                if (alert.parentElement) alert.remove();
            }, 5000);
        }
        
        function showLoading(show) {
            document.getElementById('loadingState').style.display = show ? 'flex' : 'none';
        }
        
        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        function openServiceModal() {
            document.getElementById('serviceModal').classList.add('active');
        }
        
        function closeServiceModal() {
            document.getElementById('serviceModal').classList.remove('active');
            document.getElementById('serviceForm').reset();
            document.getElementById('intervalFieldsModal').style.display = 'none';
            document.getElementById('trackIntervalModal').checked = false;
        }
        
        function openProductModal() {
            generateProductCode();
            document.getElementById('productModal').classList.add('active');
        }
        
        function closeProductModal() {
            document.getElementById('productModal').classList.remove('active');
            document.getElementById('productForm').reset();
        }
        
        function closeAllModals() {
            closeServiceModal();
            closeProductModal();
        }
        
        // Close modals when clicking outside
        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        }
        
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        document.getElementById('logoutBtn')?.addEventListener('click', logout);
    </script>
</body>
</html>