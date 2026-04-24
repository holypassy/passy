<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?? 'SAVANT MOTORS ERP'; ?></title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #1e3a6f;
            --primary-dark: #0f2b4d;
            --secondary-color: #2b6e4f;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-800: #343a40;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', 'Calibri', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        /* Modern Navbar */
        .navbar-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        /* Sidebar */
        .sidebar-modern {
            background: white;
            height: calc(100vh - 70px);
            position: fixed;
            left: 0;
            top: 70px;
            width: 280px;
            box-shadow: 2px 0 20px rgba(0,0,0,0.05);
            transition: all 0.3s;
            overflow-y: auto;
            z-index: 999;
        }
        
        .sidebar-modern.collapsed {
            margin-left: -280px;
        }
        
        .nav-item-custom {
            padding: 0.75rem 1.5rem;
            margin: 0.25rem 1rem;
            border-radius: 12px;
            transition: all 0.3s;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .nav-item-custom:hover {
            background: linear-gradient(135deg, rgba(30,58,111,0.1), rgba(43,110,79,0.1));
            transform: translateX(5px);
        }
        
        .nav-item-custom.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(30,58,111,0.3);
        }
        
        .nav-item-custom i {
            width: 24px;
            font-size: 1.2rem;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 2rem;
            transition: all 0.3s;
        }
        
        .main-content.expanded {
            margin-left: 0;
        }
        
        /* Cards */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s;
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1rem;
        }
        
        /* Tables */
        .table-modern {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        
        .table-modern thead th {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 1rem;
            font-weight: 600;
        }
        
        .table-modern tbody tr {
            transition: all 0.3s;
        }
        
        .table-modern tbody tr:hover {
            background: rgba(30,58,111,0.05);
            transform: scale(1.01);
        }
        
        /* Buttons */
        .btn-modern {
            padding: 0.6rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }
        
        .btn-modern-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-modern-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30,58,111,0.3);
        }
        
        /* Forms */
        .form-modern {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        
        .form-control-modern {
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }
        
        .form-control-modern:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(30,58,111,0.25);
        }
        
        /* Badges */
        .badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
        }
        
        .badge-received {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-ordered {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: white;
        }
        
        .badge-cancelled {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        
        /* Modal */
        .modal-modern .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .modal-modern .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 20px 20px 0 0;
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar-modern {
                transform: translateX(-100%);
            }
            
            .sidebar-modern.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Loading Spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner-overlay.show {
            display: flex;
        }
        
        /* Toast Custom */
        .toast-custom {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
    </style>
    
    <?php echo $extra_css ?? ''; ?>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar-modern fixed-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-link d-md-none" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo ms-3">
                    <i class="fas fa-car"></i> SAVANT MOTORS ERP
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <span class="badge bg-danger" id="notificationCount">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" id="notificationsList">
                        <h6 class="dropdown-header">Notifications</h6>
                        <div class="dropdown-divider"></div>
                        <p class="text-center text-muted p-3">No new notifications</p>
                    </div>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                        <div class="avatar">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </div>
                        <div class="text-start d-none d-md-block">
                            <div class="fw-bold"><?php echo $_SESSION['full_name'] ?? 'User'; ?></div>
                            <small class="text-muted"><?php echo $_SESSION['role'] ?? 'user'; ?></small>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/profile"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="/settings"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Sidebar -->
    <div class="sidebar-modern" id="sidebar">
        <div class="p-3">
            <div class="nav flex-column">
                <a href="/dashboard_erp" class="nav-item-custom">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
                <a href="/purchases" class="nav-item-custom active">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Purchases</span>
                </a>
                <a href="/suppliers" class="nav-item-custom">
                    <i class="fas fa-truck"></i>
                    <span>Suppliers</span>
                </a>
                <a href="/inventory" class="nav-item-custom">
                    <i class="fas fa-boxes"></i>
                    <span>Inventory</span>
                </a>
                <a href="/reports" class="nav-item-custom">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <?php echo $content ?? ''; ?>
    </div>
    
    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner-border text-light" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Sidebar Toggle
        $('#sidebarToggle').click(function() {
            $('#sidebar').toggleClass('show');
        });
        
        // Show/Hide Loading
        function showLoading() {
            $('#loadingSpinner').addClass('show');
        }
        
        function hideLoading() {
            $('#loadingSpinner').removeClass('show');
        }
        
        // Toast Notifications
        toastr.options = {
            "closeButton": true,
            "progressBar": true,
            "positionClass": "toast-top-right",
            "timeOut": "5000"
        };
        
        // Global AJAX Setup
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            beforeSend: function() {
                showLoading();
            },
            complete: function() {
                hideLoading();
            }
        });
        
        <?php if (isset($_SESSION['success'])): ?>
        toastr.success('<?php echo $_SESSION['success']; ?>');
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        toastr.error('<?php echo $_SESSION['error']; ?>');
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    </script>
    
    <?php echo $extra_js ?? ''; ?>
</body>
</html>
