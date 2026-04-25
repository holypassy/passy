<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$page_title = 'Time Management';
$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$view = isset($_GET['view']) ? $_GET['view'] : 'daily';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Management - Savant Motors ERP</title>
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

        .navbar {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-area { display: flex; align-items: center; gap: 15px; }
        .logo { font-size: 24px; font-weight: bold; }
        .garage-name { font-size: 20px; border-left: 2px solid rgba(255,255,255,0.3); padding-left: 15px; }
        .user-menu { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-name { font-weight: 600; }
        .user-role { font-size: 12px; opacity: 0.8; }
        .logout-btn { background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 5px; text-decoration: none; color: white; transition: all 0.3s; cursor: pointer; }
        .logout-btn:hover { background: rgba(255,255,255,0.3); }

        .main-container { display: flex; min-height: calc(100vh - 70px); }

        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid var(--border);
            padding: 25px 0;
        }

        .sidebar-title { padding: 10px 25px; font-size: 11px; text-transform: uppercase; color: var(--gray); font-weight: 700; }
        .menu-item { padding: 12px 25px; display: flex; align-items: center; gap: 12px; color: var(--gray); text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-size: 14px; font-weight: 500; cursor: pointer; }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: var(--light); border-left-color: var(--primary); color: var(--primary); }

        .content-area { flex: 1; padding: 30px; overflow-y: auto; }

        .page-title {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-title h1 { font-size: 28px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 12px; }
        .page-title h1 i { color: var(--primary); }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(37,99,235,0.4); }
        .btn-secondary { background: white; color: var(--gray); border: 1px solid var(--border); }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }

        .date-nav {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border: 1px solid var(--border);
        }
        .date-selector { display: flex; align-items: center; gap: 15px; }
        .date-nav-btn {
            background: var(--light);
            border: 1px solid var(--border);
            padding: 8px 15px;
            border-radius: 10px;
            cursor: pointer;
        }
        .current-date { font-size: 18px; font-weight: 600; color: var(--primary); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.blue { background: #dbeafe; color: var(--primary); }
        .stat-icon.green { background: #dcfce7; color: var(--success); }
        .stat-icon.orange { background: #fed7aa; color: var(--warning); }
        .stat-icon.red { background: #fee2e2; color: var(--danger); }
        .stat-info h3 { font-size: 12px; color: var(--gray); margin-bottom: 5px; text-transform: uppercase; }
        .stat-info .value { font-size: 28px; font-weight: 800; color: var(--dark); }

        .quick-actions {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid var(--border);
        }
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .action-card {
            padding: 20px;
            background: var(--light);
            border-radius: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .action-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .action-card i { font-size: 32px; color: var(--primary); margin-bottom: 10px; }

        .attendance-section {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid var(--border);
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .attendance-table { width: 100%; border-collapse: collapse; }
        .attendance-table th {
            text-align: left;
            padding: 12px;
            background: var(--light);
            font-size: 12px;
            font-weight: 700;
            color: var(--gray);
            border-bottom: 2px solid var(--border);
        }
        .attendance-table td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 13px; }
        .attendance-table tr:hover { background: var(--light); }
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; display: inline-block; }
        .status-present { background: #dcfce7; color: #166534; }
        .status-late { background: #fed7aa; color: #9a3412; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .action-btn { padding: 5px 10px; border: none; border-radius: 8px; cursor: pointer; margin: 0 2px; }

        .weekly-summary {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-bottom: 30px;
        }
        .day-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            text-align: center;
            border: 1px solid var(--border);
        }
        .day-name { font-weight: 700; color: var(--primary); margin-bottom: 5px; }
        .day-stats { font-size: 12px; }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white;
            border-radius: 28px;
            width: 90%;
            max-width: 500px;
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .close-btn { background: rgba(255,255,255,0.2); border: none; width: 36px; height: 36px; border-radius: 12px; color: white; cursor: pointer; }
        .modal-body { padding: 25px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--gray); margin-bottom: 6px; text-transform: uppercase; }
        .form-control { width: 100%; padding: 12px 14px; border: 2px solid var(--border); border-radius: 14px; font-size: 14px; }
        .modal-footer { padding: 20px 25px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; }

        .loading { text-align: center; padding: 40px; }
        .loading-spinner { display: inline-block; width: 30px; height: 30px; border: 3px solid var(--border); border-radius: 50%; border-top-color: var(--primary); animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 768px) {
            .main-container { flex-direction: column; }
            .sidebar { width: 100%; }
            .weekly-summary { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="logo-area">
            <div class="logo">🔧 SAVANT MOTORS</div>
            <div class="garage-name">UGANDA - Time Management</div>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                <div class="user-role"><?php echo strtoupper(htmlspecialchars($user_role)); ?></div>
            </div>
            <div class="logout-btn" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div>
        </div>
    </div>

    <div class="main-container">
        <div class="sidebar">
            <div class="sidebar-title">MAIN</div>
            <a href="dashboard_erp.php" class="menu-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="time_management.php" class="menu-item active"><i class="fas fa-clock"></i> Time Management</a>
            <a href="technicians.php" class="menu-item"><i class="fas fa-users-cog"></i> Technicians</a>
        </div>

        <div class="content-area">
            <div class="page-title">
                <h1><i class="fas fa-clock"></i> Time Management Dashboard</h1>
                <div>
                    <button class="btn btn-primary" onclick="openCheckinModal()"><i class="fas fa-user-check"></i> Check In</button>
                    <button class="btn btn-success" onclick="exportReport()"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>

            <div class="date-nav">
                <div class="date-selector">
                    <button class="date-nav-btn" onclick="changeDate('prev')"><i class="fas fa-chevron-left"></i></button>
                    <span class="current-date" id="currentDate"><?php echo date('l, F j, Y', strtotime($selected_date)); ?></span>
                    <button class="date-nav-btn" onclick="changeDate('next')"><i class="fas fa-chevron-right"></i></button>
                    <button class="date-nav-btn" onclick="changeDate('today')"><i class="fas fa-calendar-day"></i> Today</button>
                </div>
                <div class="view-toggle">
                    <button class="btn btn-secondary" onclick="changeView('daily')">Daily</button>
                    <button class="btn btn-secondary" onclick="changeView('weekly')">Weekly</button>
                    <button class="btn btn-secondary" onclick="changeView('monthly')">Monthly</button>
                </div>
            </div>

            <div class="stats-grid" id="statsGrid">
                <div class="loading"><div class="loading-spinner"></div> Loading...</div>
            </div>

            <div class="quick-actions">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="action-buttons">
                    <div class="action-card" onclick="openCheckinModal()"><i class="fas fa-sign-in-alt"></i><h4>Quick Check-In</h4><p>Check in a technician</p></div>
                    <div class="action-card" onclick="openCheckoutModal()"><i class="fas fa-sign-out-alt"></i><h4>Quick Check-Out</h4><p>Check out a technician</p></div>
                    <div class="action-card" onclick="openReportModal()"><i class="fas fa-file-alt"></i><h4>Daily Report</h4><p>Submit work report</p></div>
                    <div class="action-card" onclick="openOvertimeModal()"><i class="fas fa-hourglass-start"></i><h4>Request Overtime</h4><p>Submit overtime request</p></div>
                </div>
            </div>

            <div class="attendance-section">
                <div class="section-header">
                    <h3><i class="fas fa-clipboard-list"></i> Today's Attendance</h3>
                </div>
                <div id="attendanceTable" class="loading"><div class="loading-spinner"></div> Loading...</div>
            </div>

            <div id="weeklySummary" class="weekly-summary"></div>
        </div>
    </div>

    <!-- Check-In Modal -->
    <div id="checkinModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sign-in-alt"></i> Technician Check-In</h3>
                <button class="close-btn" onclick="closeModal('checkinModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Select Technician</label>
                    <select class="form-control" id="checkinTechnician" required>
                        <option value="">-- Select Technician --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Check-In Time</label>
                    <input type="time" class="form-control" id="checkinTime" value="<?php echo date('H:i'); ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" id="checkinStatus">
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea class="form-control" id="checkinNotes" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('checkinModal')">Cancel</button>
                <button class="btn btn-success" onclick="submitCheckIn()">Check In</button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '/api';
        let currentDate = '<?php echo $selected_date; ?>';
        let currentView = '<?php echo $view; ?>';

        async function apiCall(endpoint, options = {}) {
            try {
                const response = await fetch(`${API_BASE}/time.php?action=${endpoint}`, {
                    headers: { 'Content-Type': 'application/json' },
                    ...options
                });
                return await response.json();
            } catch (error) {
                console.error('API Error:', error);
                showAlert('Network error', 'error');
                return null;
            }
        }

        async function loadTechnicians() {
            const response = await apiCall('technicians');
            if (response && response.success) {
                const select = document.getElementById('checkinTechnician');
                select.innerHTML = '<option value="">-- Select Technician --</option>' + 
                    response.data.map(t => `<option value="${t.id}">${escapeHtml(t.full_name)} (${t.technician_code})</option>`).join('');
                return response.data;
            }
            return [];
        }

        async function loadAttendance() {
            const response = await apiCall(`attendance&date=${currentDate}`);
            if (response && response.success) {
                renderAttendance(response.data);
            }
        }

        async function loadStats() {
            if (currentView === 'weekly') {
                const response = await apiCall('weekly-summary');
                if (response && response.success) {
                    renderWeeklySummary(response.data);
                }
            } else if (currentView === 'monthly') {
                const response = await apiCall('monthly-stats');
                if (response && response.success) {
                    renderMonthlyStats(response.data);
                }
            } else {
                const response = await apiCall('technicians');
                if (response && response.success) {
                    renderStats(response.statistics);
                }
            }
        }

        function renderAttendance(attendance) {
            const container = document.getElementById('attendanceTable');
            if (!attendance || attendance.length === 0) {
                container.innerHTML = '<div class="empty-state" style="padding: 40px; text-align: center;">No attendance records for today</div>';
                return;
            }

            container.innerHTML = `
                <table class="attendance-table">
                    <thead><tr><th>Technician</th><th>Department</th><th>Check In</th><th>Check Out</th><th>Hours</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        ${attendance.map(a => `
                            <tr>
                                <td><strong>${escapeHtml(a.full_name)}</strong><br><small>${a.technician_code}</small></td>
                                <td>${escapeHtml(a.department || 'General')}</td>
                                <td>${a.check_in_formatted || '<span style="color:#999;">Not checked in</span>'}</td>
                                <td>${a.check_out_formatted || '<span style="color:#999;">Not checked out</span>'}</td>
                                <td>${a.total_hours ? a.total_hours + ' hrs' : '-'}</td>
                                <td><span class="status-badge status-${a.status}">${(a.status || 'pending').toUpperCase()}</span></td>
                                <td>
                                    ${!a.check_out_time && a.check_in_time ? `<button class="action-btn btn-warning" onclick="quickCheckout(${a.technician_id})">Check Out</button>` : ''}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        function renderStats(stats) {
            const grid = document.getElementById('statsGrid');
            grid.innerHTML = `
                <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-users"></i></div><div class="stat-info"><h3>Total Technicians</h3><div class="value">${stats.total || 0}</div></div></div>
                <div class="stat-card"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><div class="stat-info"><h3>Active</h3><div class="value">${stats.active || 0}</div></div></div>
                <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><h3>Pending Overtime</h3><div class="value" id="pendingOvertime">0</div></div></div>
                <div class="stat-card"><div class="stat-icon red"><i class="fas fa-calendar-day"></i></div><div class="stat-info"><h3>Today's Present</h3><div class="value" id="presentCount">0</div></div></div>
            `;
        }

        function renderWeeklySummary(data) {
            const container = document.getElementById('weeklySummary');
            if (!data || data.length === 0) {
                container.innerHTML = '<div class="empty-state">No data for this week</div>';
                return;
            }

            const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            container.innerHTML = data.map(day => `
                <div class="day-card">
                    <div class="day-name">${new Date(day.attendance_date).toLocaleDateString('en-US', { weekday: 'short' })}</div>
                    <div class="day-date">${new Date(day.attendance_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</div>
                    <div class="day-stats">
                        <div class="present">✓ ${day.present || 0}</div>
                        <div class="late">⚠ ${day.late || 0}</div>
                        <div class="absent">✗ ${day.absent || 0}</div>
                    </div>
                </div>
            `).join('');
        }

        async function submitCheckIn() {
            const technicianId = document.getElementById('checkinTechnician').value;
            if (!technicianId) { showAlert('Please select a technician', 'error'); return; }

            const data = {
                technician_id: technicianId,
                check_in_time: document.getElementById('checkinTime').value,
                status: document.getElementById('checkinStatus').value,
                notes: document.getElementById('checkinNotes').value
            };

            const response = await apiCall('checkin', { method: 'POST', body: JSON.stringify(data) });
            if (response && response.success) {
                showAlert(response.message, 'success');
                closeModal('checkinModal');
                loadAttendance();
                loadStats();
            } else {
                showAlert(response?.message || 'Check-in failed', 'error');
            }
        }

        async function quickCheckout(technicianId) {
            if (!confirm('Check out this technician?')) return;
            const response = await apiCall('checkout', { method: 'POST', body: JSON.stringify({ technician_id: technicianId }) });
            if (response && response.success) {
                showAlert(response.message, 'success');
                loadAttendance();
            } else {
                showAlert(response?.message || 'Check-out failed', 'error');
            }
        }

        function changeDate(direction) {
            let date = new Date(currentDate);
            if (direction === 'prev') date.setDate(date.getDate() - 1);
            else if (direction === 'next') date.setDate(date.getDate() + 1);
            else if (direction === 'today') date = new Date();
            
            currentDate = date.toISOString().split('T')[0];
            document.getElementById('currentDate').innerHTML = date.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            loadAttendance();
        }

        function changeView(view) {
            currentView = view;
            if (view === 'weekly') {
                document.getElementById('weeklySummary').style.display = 'grid';
                loadStats();
            } else if (view === 'monthly') {
                document.getElementById('weeklySummary').style.display = 'none';
                loadStats();
            } else {
                document.getElementById('weeklySummary').style.display = 'none';
                loadStats();
            }
        }

        function openCheckinModal() { openModal('checkinModal'); loadTechnicians(); }
        function openCheckoutModal() { window.location.href = 'checkout.php'; }
        function openReportModal() { window.location.href = 'daily_report.php'; }
        function openOvertimeModal() { window.location.href = 'overtime_request.php'; }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            document.body.insertBefore(alertDiv, document.body.firstChild);
            setTimeout(() => alertDiv.remove(), 5000);
        }

        function formatNumber(num) { return num.toLocaleString('en-US'); }
        function escapeHtml(text) { if (!text) return ''; const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

        async function logout() {
            await fetch(`${API_BASE}/auth.php?action=logout`, { method: 'POST' });
            window.location.href = '/index.php';
        }

        document.getElementById('logoutBtn')?.addEventListener('click', logout);
        window.onclick = e => { if (e.target.classList.contains('modal')) e.target.classList.remove('active'); };

        loadTechnicians();
        loadAttendance();
        loadStats();
    </script>
</body>
</html>