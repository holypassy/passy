<div class="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-charging-station"></i> SAVANT MOTORS</h2>
        <p>Enterprise Resource Planning</p>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-section">
            <div class="sidebar-title">MAIN</div>
            <a href="dashboard_erp.php" class="menu-item <?php echo isActive('dashboard'); ?>">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>
            <a href="customers.php" class="menu-item <?php echo isActive('customers'); ?>">
                <i class="fas fa-users"></i> Customers
            </a>
            <a href="inventory.php" class="menu-item <?php echo isActive('inventory'); ?>">
                <i class="fas fa-boxes"></i> Inventory
            </a>
            <a href="job_cards.php" class="menu-item <?php echo isActive('job_cards'); ?>">
                <i class="fas fa-clipboard-list"></i> Job Cards
            </a>
            <a href="cash_management.php" class="menu-item <?php echo isActive('cash_management'); ?>">
                <i class="fas fa-money-bill-wave"></i> Cash Management
            </a>
            <a href="reminders.php" class="menu-item <?php echo isActive('reminders'); ?>">
                <i class="fas fa-bell"></i> Reminders
            </a>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-title">ACCOUNTING</div>
            <a href="bank_accounts.php" class="menu-item">
                <i class="fas fa-university"></i> Bank Accounts
            </a>
            <a href="general_ledger.php" class="menu-item">
                <i class="fas fa-book"></i> General Ledger
            </a>
            <a href="reports.php" class="menu-item">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-title">SYSTEM</div>
            <a href="users.php" class="menu-item">
                <i class="fas fa-user-cog"></i> Users
            </a>
            <a href="settings.php" class="menu-item">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>
        <div style="margin-top: 30px;">
            <div class="menu-item" id="logoutBtn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </div>
        </div>
    </div>
</div>

<style>
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
    transition: all 0.3s;
}

.sidebar-header {
    padding: 28px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}

.sidebar-header h2 {
    font-size: 22px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 12px;
}

.sidebar-header h2 i {
    color: #60a5fa;
    font-size: 28px;
}

.sidebar-header p {
    font-size: 11px;
    opacity: 0.6;
    margin-top: 6px;
    letter-spacing: 0.5px;
}

.sidebar-menu {
    padding: 20px 0;
}

.sidebar-section {
    margin-bottom: 25px;
}

.sidebar-title {
    padding: 10px 24px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255,255,255,0.4);
    font-weight: 600;
}

.menu-item {
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 14px;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    transition: all 0.3s;
    border-left: 3px solid transparent;
    font-size: 14px;
    font-weight: 500;
    margin: 2px 0;
    cursor: pointer;
}

.menu-item i {
    width: 22px;
    font-size: 16px;
}

.menu-item:hover, .menu-item.active {
    background: rgba(255,255,255,0.08);
    color: white;
    border-left-color: var(--success);
}

.main-content {
    margin-left: 280px;
    padding: 28px 32px;
    min-height: 100vh;
}
</style>