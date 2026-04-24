<?php
namespace App\Models;

use App\Core\Model;

class VehiclePickupReminder extends Model
{
    protected static $table = 'vehicle_pickup_reminders';

    public static function createTable()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS vehicle_pickup_reminders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                job_card_id INT,
                vehicle_reg VARCHAR(20) NOT NULL,
                vehicle_make VARCHAR(50),
                vehicle_model VARCHAR(50),
                pickup_type ENUM('workshop', 'home', 'office') DEFAULT 'workshop',
                pickup_address TEXT,
                pickup_location_details TEXT,
                pickup_date DATE NOT NULL,
                pickup_time TIME,
                reminder_date DATE NOT NULL,
                reminder_time TIME,
                reminder_sent TINYINT DEFAULT 0,
                reminder_type ENUM('sms', 'email', 'both') DEFAULT 'sms',
                status ENUM('pending', 'scheduled', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
                notes TEXT,
                assigned_to INT,
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
            )
        ";
        self::query($sql);
    }

    public static function getReminders($filters = [])
    {
        $sql = "
            SELECT 
                vpr.*,
                c.full_name,
                c.telephone,
                c.email,
                c.address as customer_address,
                jc.job_number,
                u.full_name as assigned_to_name
            FROM vehicle_pickup_reminders vpr
            LEFT JOIN customers c ON vpr.customer_id = c.id
            LEFT JOIN job_cards jc ON vpr.job_card_id = jc.id
            LEFT JOIN users u ON vpr.assigned_to = u.id
        ";
        $where = [];
        $params = [];

        if (!empty($filters['status']) && $filters['status'] != 'all') {
            $where[] = "vpr.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(c.full_name LIKE ? OR vpr.vehicle_reg LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
        }
        if (!empty($filters['pickup_type'])) {
            $where[] = "vpr.pickup_type = ?";
            $params[] = $filters['pickup_type'];
        }

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY vpr.pickup_date ASC, vpr.created_at DESC";

        return self::fetchAll($sql, $params);
    }

    public static function getStats()
    {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status IN ('pending', 'scheduled') AND pickup_date <= CURDATE() AND reminder_sent = 0 THEN 1 ELSE 0 END) as due_today
            FROM vehicle_pickup_reminders
        ";
        $result = self::fetchOne($sql);
        return $result ?: [
            'total' => 0, 'pending' => 0, 'scheduled' => 0,
            'in_progress' => 0, 'completed' => 0, 'cancelled' => 0, 'due_today' => 0
        ];
    }

    public static function updateStatus($id, $status)
    {
        self::update(self::$table, ['status' => $status], 'id = :id', ['id' => $id]);
    }

    public static function markSent($id)
    {
        self::update(self::$table, ['reminder_sent' => 1], 'id = :id', ['id' => $id]);
    }
}