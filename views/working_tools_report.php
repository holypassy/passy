<?php
// reports/working_tools_report.php
// This file is included in reports.php when 'tools' tab is selected

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get date range from request or default to current month
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    
    // 1. TOOL INVENTORY SUMMARY
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_tools,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_tools,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_tools,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_tools,
            SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_tools,
            COALESCE(SUM(purchase_price), 0) as total_value,
            COALESCE(AVG(purchase_price), 0) as avg_tool_value
        FROM tools 
        WHERE is_active = 1
    ");
    $inventory_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. TOOLS BY CATEGORY
    $stmt = $conn->query("
        SELECT 
            COALESCE(category, 'uncategorized') as category,
            COUNT(*) as tool_count,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
            COALESCE(SUM(purchase_price), 0) as total_value
        FROM tools 
        WHERE is_active = 1
        GROUP BY category
        ORDER BY tool_count DESC
    ");
    $tools_by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. CURRENTLY ASSIGNED TOOLS
    $stmt = $conn->query("
        SELECT 
            t.id,
            t.tool_code,
            t.tool_name,
            t.category,
            t.serial_number,
            t.condition as tool_condition,
            tech.full_name as technician_name,
            tech.technician_code,
            ta.assigned_date,
            ta.expected_return_date,
            ta.job_number,
            DATEDIFF(NOW(), ta.expected_return_date) as days_overdue,
            CASE 
                WHEN ta.expected_return_date < CURDATE() THEN 'Overdue'
                WHEN ta.expected_return_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) THEN 'Due Soon'
                ELSE 'On Time'
            END as assignment_status
        FROM tools t
        JOIN tool_assignments ta ON t.id = ta.tool_id
        JOIN technicians tech ON ta.technician_id = tech.id
        WHERE ta.status = 'assigned' AND ta.actual_return_date IS NULL
        ORDER BY 
            CASE 
                WHEN ta.expected_return_date < CURDATE() THEN 1
                WHEN ta.expected_return_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) THEN 2
                ELSE 3
            END,
            ta.expected_return_date ASC
    ");
    $assigned_tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. TOOLS IN MAINTENANCE
    $stmt = $conn->query("
        SELECT 
            t.id,
            t.tool_code,
            t.tool_name,
            t.category,
            t.serial_number,
            t.condition as tool_condition,
            tm.maintenance_date,
            tm.maintenance_type,
            tm.description,
            tm.estimated_completion,
            tm.cost,
            DATEDIFF(NOW(), tm.maintenance_date) as days_in_maintenance
        FROM tools t
        JOIN tool_maintenance tm ON t.id = tm.tool_id
        WHERE t.status = 'maintenance' AND tm.status = 'in_progress'
        ORDER BY tm.maintenance_date DESC
    ");
    $maintenance_tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. TOOL USAGE STATISTICS
    $stmt = $conn->prepare("
        SELECT 
            t.id,
            t.tool_code,
            t.tool_name,
            t.category,
            COUNT(ta.id) as total_assignments,
            COALESCE(SUM(DATEDIFF(COALESCE(ta.actual_return_date, NOW()), ta.assigned_date)), 0) as total_days_used,
            COALESCE(AVG(DATEDIFF(COALESCE(ta.actual_return_date, NOW()), ta.assigned_date)), 0) as avg_days_per_use,
            MAX(ta.assigned_date) as last_assigned,
            COUNT(CASE WHEN ta.is_overdue = TRUE THEN 1 END) as overdue_count
        FROM tools t
        LEFT JOIN tool_assignments ta ON t.id = ta.tool_id
        WHERE t.is_active = 1
        AND (ta.assigned_date BETWEEN :start_date AND :end_date OR ta.assigned_date IS NULL)
        GROUP BY t.id
        ORDER BY total_assignments DESC
        LIMIT 20
    ");
    $stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $usage_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. TOOL REQUESTS SUMMARY
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
            SUM(CASE WHEN urgency = 'emergency' THEN 1 ELSE 0 END) as emergency_requests
        FROM tool_requests
        WHERE created_at BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
    $requests_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 7. TOOLS NEEDING MAINTENANCE
    $stmt = $conn->query("
        SELECT 
            t.id,
            t.tool_code,
            t.tool_name,
            t.category,
            t.next_maintenance_date,
            t.condition,
            DATEDIFF(t.next_maintenance_date, CURDATE()) as days_until_maintenance
        FROM tools t
        WHERE t.is_active = 1
        AND t.next_maintenance_date IS NOT NULL
        AND t.next_maintenance_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY t.next_maintenance_date ASC
    ");
    $upcoming_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. TOOL VALUE DEPRECIATION (simple straight-line over 3 years)
    $stmt = $conn->query("
        SELECT 
            t.id,
            t.tool_code,
            t.tool_name,
            t.purchase_price,
            t.purchase_date,
            t.condition,
            TIMESTAMPDIFF(MONTH, t.purchase_date, CURDATE()) as age_months,
            GREATEST(t.purchase_price * (1 - (TIMESTAMPDIFF(MONTH, t.purchase_date, CURDATE()) / 36.0)), 0) as current_value
        FROM tools t
        WHERE t.is_active = 1 AND t.purchase_price > 0
        ORDER BY current_value DESC
        LIMIT 20
    ");
    $depreciation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    // Initialize empty arrays to prevent undefined variable errors
    $inventory_summary = [
        'total_tools' => 0, 'available_tools' => 0, 'assigned_tools' => 0,
        'maintenance_tools' => 0, 'lost_tools' => 0, 'total_value' => 0, 'avg_tool_value' => 0
    ];
    $tools_by_category = [];
    $assigned_tools = [];
    $maintenance_tools = [];
    $usage_stats = [];
    $requests_summary = [
        'total_requests' => 0, 'pending_requests' => 0, 'approved_requests' => 0,
        'rejected_requests' => 0, 'emergency_requests' => 0
    ];
    $upcoming_maintenance = [];
    $depreciation = [];
}
?>

<!-- Report Header with Links to Tool Management -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="color: #2a5298; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-tools"></i> Working Tools Report
        </h2>
        <p style="color: #666; margin-top: 5px;">
            <i class="fas fa-calendar"></i> Period: <?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>
        </p>
    </div>
    <div>
        <a href="tool_inventory.php" class="btn btn-primary" style="padding: 10px 20px; text-decoration: none;">
            <i class="fas fa-boxes"></i> View Full Inventory
        </a>
        <a href="tools.php" class="btn btn-info" style="padding: 10px 20px; text-decoration: none; margin-left: 10px;">
            <i class="fas fa-cog"></i> Manage Tools
        </a>
    </div>
</div>

<!-- Inventory Summary Cards -->
<div class="summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px;">
    <div class="summary-card" style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #2a5298;">
        <div style="font-size: 14px; color: #666;">Total Tools</div>
        <div style="font-size: 32px; font-weight: 700; color: #2a5298;"><?php echo $inventory_summary['total_tools']; ?></div>
        <div style="font-size: 12px; color: #999;">Total value: UGX <?php echo number_format($inventory_summary['total_value']); ?></div>
    </div>
    
    <div class="summary-card" style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #28a745;">
        <div style="font-size: 14px; color: #666;">Available</div>
        <div style="font-size: 32px; font-weight: 700; color: #28a745;"><?php echo $inventory_summary['available_tools']; ?></div>
        <div style="font-size: 12px; color: #999;">Ready to use</div>
    </div>
    
    <div class="summary-card" style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #ffc107;">
        <div style="font-size: 14px; color: #666;">Assigned</div>
        <div style="font-size: 32px; font-weight: 700; color: #ffc107;"><?php echo $inventory_summary['assigned_tools']; ?></div>
        <div style="font-size: 12px; color: #999;">To technicians</div>
    </div>
    
    <div class="summary-card" style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #dc3545;">
        <div style="font-size: 14px; color: #666;">Maintenance</div>
        <div style="font-size: 32px; font-weight: 700; color: #dc3545;"><?php echo $inventory_summary['maintenance_tools']; ?></div>
        <div style="font-size: 12px; color: #999;">Needs attention</div>
    </div>
    
    <div class="summary-card" style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #6f42c1;">
        <div style="font-size: 14px; color: #666;">Avg Tool Value</div>
        <div style="font-size: 24px; font-weight: 700; color: #6f42c1;">UGX <?php echo number_format($inventory_summary['avg_tool_value']); ?></div>
    </div>
</div>

<!-- Tools by Category -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
    <h3 style="color: #2a5298; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-chart-pie"></i> Tools by Category
    </h3>
    
    <table class="report-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Category</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Total</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Available</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Assigned</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Maintenance</th>
                <th style="padding: 12px; text-align: right; background: #f8f9fa;">Total Value</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tools_by_category as $category): ?>
            <tr>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                    <strong><?php echo ucfirst(str_replace('_', ' ', $category['category'])); ?></strong>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo $category['tool_count']; ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; color: #28a745;"><?php echo $category['available']; ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; color: #ffc107;"><?php echo $category['assigned']; ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; color: #dc3545;"><?php echo $category['maintenance']; ?></td>
                <td style="padding: 12px; text-align: right; border-bottom: 1px solid #dee2e6; font-weight: 600;">UGX <?php echo number_format($category['total_value']); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <a href="tool_inventory.php?category=<?php echo urlencode($category['category']); ?>" style="text-decoration: none; padding: 5px 10px; background: #2a5298; color: white; border-radius: 4px; font-size: 12px;">
                        View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Currently Assigned Tools -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
    <h3 style="color: #2a5298; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-hand-holding"></i> Currently Assigned Tools
        <span style="background: #ffc107; color: #333; padding: 3px 10px; border-radius: 20px; font-size: 12px; margin-left: 10px;">
            <?php echo count($assigned_tools); ?> tools
        </span>
    </h3>
    
    <?php if (empty($assigned_tools)): ?>
    <p style="text-align: center; padding: 30px; color: #666;">No tools currently assigned</p>
    <?php else: ?>
    <table class="report-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Tool</th>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Technician</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Assigned Date</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Expected Return</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Status</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($assigned_tools as $tool): 
                $status_color = $tool['assignment_status'] == 'Overdue' ? '#dc3545' : ($tool['assignment_status'] == 'Due Soon' ? '#ffc107' : '#28a745');
            ?>
            <tr>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                    <strong><?php echo htmlspecialchars($tool['tool_name']); ?></strong><br>
                    <small style="color: #666;"><?php echo $tool['tool_code']; ?></small>
                </td>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                    <?php echo htmlspecialchars($tool['technician_name']); ?><br>
                    <small style="color: #666;"><?php echo $tool['technician_code']; ?></small>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <?php echo date('d M Y', strtotime($tool['assigned_date'])); ?>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <?php echo date('d M Y', strtotime($tool['expected_return_date'])); ?>
                    <?php if ($tool['days_overdue'] > 0): ?>
                    <br><small style="color: #dc3545;"><?php echo $tool['days_overdue']; ?> days overdue</small>
                    <?php endif; ?>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <span style="background: <?php echo $status_color; ?>20; color: <?php echo $status_color; ?>; padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;">
                        <?php echo $tool['assignment_status']; ?>
                    </span>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <a href="view_tool.php?id=<?php echo $tool['id']; ?>" style="text-decoration: none; padding: 5px 10px; background: #2a5298; color: white; border-radius: 4px; font-size: 12px;">
                        View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Tools in Maintenance -->
<?php if (!empty($maintenance_tools)): ?>
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
    <h3 style="color: #2a5298; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-tools"></i> Tools in Maintenance
        <span style="background: #dc3545; color: white; padding: 3px 10px; border-radius: 20px; font-size: 12px; margin-left: 10px;">
            <?php echo count($maintenance_tools); ?> tools
        </span>
    </h3>
    
    <table class="report-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Tool</th>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Maintenance Type</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Start Date</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Days</th>
                <th style="padding: 12px; text-align: right; background: #f8f9fa;">Cost</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($maintenance_tools as $tool): ?>
            <tr>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                    <strong><?php echo htmlspecialchars($tool['tool_name']); ?></strong><br>
                    <small style="color: #666;"><?php echo $tool['tool_code']; ?></small>
                </td>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;"><?php echo ucfirst($tool['maintenance_type']); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo date('d M Y', strtotime($tool['maintenance_date'])); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo $tool['days_in_maintenance']; ?> days</td>
                <td style="padding: 12px; text-align: right; border-bottom: 1px solid #dee2e6;">UGX <?php echo number_format($tool['cost']); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <a href="tool_maintenance.php?id=<?php echo $tool['id']; ?>" style="text-decoration: none; padding: 5px 10px; background: #ffc107; color: #333; border-radius: 4px; font-size: 12px;">
                        Update
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Upcoming Maintenance -->
<?php if (!empty($upcoming_maintenance)): ?>
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
    <h3 style="color: #2a5298; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-calendar-check"></i> Upcoming Maintenance (Next 30 Days)
    </h3>
    
    <table class="report-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Tool</th>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Category</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Current Condition</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Due Date</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Days Left</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($upcoming_maintenance as $tool): 
                $days = $tool['days_until_maintenance'];
                $status_color = $days < 0 ? '#dc3545' : ($days < 7 ? '#ffc107' : '#28a745');
            ?>
            <tr>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                    <strong><?php echo htmlspecialchars($tool['tool_name']); ?></strong><br>
                    <small style="color: #666;"><?php echo $tool['tool_code']; ?></small>
                </td>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;"><?php echo ucfirst(str_replace('_', ' ', $tool['category'] ?: 'N/A')); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo ucfirst($tool['condition']); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo date('d M Y', strtotime($tool['next_maintenance_date'])); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; color: <?php echo $status_color; ?>; font-weight: 600;">
                    <?php echo $days >= 0 ? $days . ' days' : 'Overdue by ' . abs($days) . ' days'; ?>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <a href="schedule_maintenance.php?tool_id=<?php echo $tool['id']; ?>" style="text-decoration: none; padding: 5px 10px; background: #2a5298; color: white; border-radius: 4px; font-size: 12px;">
                        Schedule
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Tool Usage Statistics -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
    <h3 style="color: #2a5298; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-chart-bar"></i> Tool Usage Statistics
        <span style="background: #17a2b8; color: white; padding: 3px 10px; border-radius: 20px; font-size: 12px; margin-left: 10px;">
            Period: <?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?>
        </span>
    </h3>
    
    <table class="report-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Tool</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Total Uses</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Total Days</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Avg Days/Use</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Overdue Count</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Last Used</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usage_stats as $stat): ?>
            <tr>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                    <strong><?php echo htmlspecialchars($stat['tool_name']); ?></strong><br>
                    <small style="color: #666;"><?php echo $stat['tool_code']; ?></small>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo $stat['total_assignments']; ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo round($stat['total_days_used']); ?> days</td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo round($stat['avg_days_per_use'], 1); ?> days</td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; color: <?php echo $stat['overdue_count'] > 0 ? '#dc3545' : '#28a745'; ?>;">
                    <?php echo $stat['overdue_count']; ?>
                </td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <?php echo $stat['last_assigned'] ? date('d M Y', strtotime($stat['last_assigned'])) : 'Never'; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Tool Requests Summary -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
    <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <div style="font-size: 14px; color: #666;">Total Requests</div>
        <div style="font-size: 28px; font-weight: 700; color: #2a5298;"><?php echo $requests_summary['total_requests']; ?></div>
        <div style="margin-top: 10px;">
            <a href="tool_requests.php" style="text-decoration: none; color: #2a5298; font-size: 13px;">
                View All Requests <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
    
    <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <div style="font-size: 14px; color: #666;">Pending</div>
        <div style="font-size: 28px; font-weight: 700; color: #ffc107;"><?php echo $requests_summary['pending_requests']; ?></div>
    </div>
    
    <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <div style="font-size: 14px; color: #666;">Approved</div>
        <div style="font-size: 28px; font-weight: 700; color: #28a745;"><?php echo $requests_summary['approved_requests']; ?></div>
    </div>
    
    <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <div style="font-size: 14px; color: #666;">Emergency</div>
        <div style="font-size: 28px; font-weight: 700; color: #dc3545;"><?php echo $requests_summary['emergency_requests']; ?></div>
    </div>
</div>

<!-- Tool Value Depreciation -->
<?php if (!empty($depreciation)): ?>
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
    <h3 style="color: #2a5298; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-chart-line"></i> Tool Value Depreciation
    </h3>
    
    <table class="report-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="padding: 12px; text-align: left; background: #f8f9fa;">Tool</th>
                <th style="padding: 12px; text-align: right; background: #f8f9fa;">Purchase Price</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Purchase Date</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Age (months)</th>
                <th style="padding: 12px; text-align: right; background: #f8f9fa;">Current Value</th>
                <th style="padding: 12px; text-align: center; background: #f8f9fa;">Depreciation</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($depreciation as $tool): 
                $depreciation_percent = $tool['purchase_price'] > 0 ? 
                    (($tool['purchase_price'] - $tool['current_value']) / $tool['purchase_price']) * 100 : 0;
            ?>
            <tr>
                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                    <strong><?php echo htmlspecialchars($tool['tool_name']); ?></strong>
                </td>
                <td style="padding: 12px; text-align: right; border-bottom: 1px solid #dee2e6;">UGX <?php echo number_format($tool['purchase_price']); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo date('d M Y', strtotime($tool['purchase_date'])); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo $tool['age_months']; ?> months</td>
                <td style="padding: 12px; text-align: right; border-bottom: 1px solid #dee2e6; font-weight: 600;">UGX <?php echo number_format($tool['current_value']); ?></td>
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                    <div style="background: #e9ecef; height: 6px; width: 100px; margin: 0 auto; border-radius: 3px;">
                        <div style="background: <?php echo $depreciation_percent > 50 ? '#dc3545' : ($depreciation_percent > 25 ? '#ffc107' : '#28a745'); ?>; width: <?php echo $depreciation_percent; ?>%; height: 6px; border-radius: 3px;"></div>
                    </div>
                    <span style="font-size: 11px;"><?php echo round($depreciation_percent, 1); ?>%</span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Quick Links to Tool Management -->
<div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
    <a href="tool_inventory.php" class="btn btn-primary" style="padding: 12px 25px; text-decoration: none;">
        <i class="fas fa-boxes"></i> Full Inventory
    </a>
    <a href="tools.php" class="btn btn-success" style="padding: 12px 25px; text-decoration: none;">
        <i class="fas fa-plus"></i> Add New Tool
    </a>
    <a href="tool_requests.php" class="btn btn-warning" style="padding: 12px 25px; text-decoration: none;">
        <i class="fas fa-clipboard-list"></i> Tool Requests
    </a>
    <a href="technicians.php" class="btn btn-info" style="padding: 12px 25px; text-decoration: none;">
        <i class="fas fa-users-cog"></i> Technicians
    </a>
</div>

<!-- Export/Print Buttons -->
<div style="margin-top: 20px; text-align: right;">
    <button class="btn btn-info" onclick="exportToolsReport()">
        <i class="fas fa-download"></i> Export to Excel
    </button>
    <button class="btn btn-primary" onclick="window.print()" style="margin-left: 10px;">
        <i class="fas fa-print"></i> Print Report
    </button>
</div>

<script>
function exportToolsReport() {
    // Create CSV content
    let csv = "Working Tools Report\n";
    csv += "Generated: " + new Date().toLocaleString() + "\n\n";
    
    csv += "TOOL INVENTORY SUMMARY\n";
    csv += "Total Tools,Available,Assigned,Maintenance,Total Value\n";
    csv += "<?php echo $inventory_summary['total_tools']; ?>,<?php echo $inventory_summary['available_tools']; ?>,<?php echo $inventory_summary['assigned_tools']; ?>,<?php echo $inventory_summary['maintenance_tools']; ?>,<?php echo $inventory_summary['total_value']; ?>\n\n";
    
    csv += "TOOLS BY CATEGORY\n";
    csv += "Category,Total,Available,Assigned,Maintenance,Total Value\n";
    <?php foreach ($tools_by_category as $cat): ?>
    csv += "<?php echo $cat['category']; ?>,<?php echo $cat['tool_count']; ?>,<?php echo $cat['available']; ?>,<?php echo $cat['assigned']; ?>,<?php echo $cat['maintenance']; ?>,<?php echo $cat['total_value']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'working_tools_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>