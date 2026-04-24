<?php
require_once __DIR__ . '/../config/database.php';

class JobCard {
    private $conn;
    private $table = "job_cards";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function generateJobNumber() {
        $year = date('Y');
        $prefix = "JB-{$year}";
        
        $query = "SELECT job_number FROM {$this->table} 
                  WHERE job_number LIKE :prefix 
                  ORDER BY id DESC LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':prefix' => "{$prefix}%"]);
        $last = $stmt->fetch();
        
        if ($last) {
            $lastNum = (int)substr($last['job_number'], -4);
            $newNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNum = '0001';
        }
        
        return "{$prefix}-{$newNum}";
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (job_number, customer_id, vehicle_reg, vehicle_make, vehicle_model, 
                   vehicle_year, odometer_reading, fuel_level, date_received, status, 
                   priority, notes, inspection_data, work_items, created_by) 
                  VALUES (:job_number, :customer_id, :vehicle_reg, :vehicle_make, :vehicle_model,
                          :vehicle_year, :odometer_reading, :fuel_level, :date_received, :status,
                          :priority, :notes, :inspection_data, :work_items, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':job_number' => $data['job_number'],
            ':customer_id' => $data['customer_id'],
            ':vehicle_reg' => $data['vehicle_reg'] ?? null,
            ':vehicle_make' => $data['vehicle_make'] ?? null,
            ':vehicle_model' => $data['vehicle_model'] ?? null,
            ':vehicle_year' => $data['vehicle_year'] ?? null,
            ':odometer_reading' => $data['odometer_reading'] ?? null,
            ':fuel_level' => $data['fuel_level'] ?? null,
            ':date_received' => $data['date_received'] ?? date('Y-m-d'),
            ':status' => $data['status'] ?? 'pending',
            ':priority' => $data['priority'] ?? 'normal',
            ':notes' => $data['notes'] ?? null,
            ':inspection_data' => $data['inspection_data'] ?? null,
            ':work_items' => $data['work_items'] ?? null,
            ':created_by' => $data['created_by']
        ]);
    }
    
    public function getLastJobNumber() {
        $query = "SELECT job_number FROM {$this->table} ORDER BY id DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function getInspectionItems() {
        return [
            'basic' => [
                'wheel_spanner' => 'Wheel Spanner',
                'car_jack' => 'Car Jack',
                'special_nut' => 'Special Nut',
                'reflector' => 'Reflector',
                'engine_check_light' => 'Engine Check Light',
                'radio' => 'Radio',
                'ac' => 'AC',
                'fuel_level_indicator' => 'Fuel Level Indicator'
            ],
            'front' => [
                'front_bumper' => 'Front Bumper',
                'front_grill' => 'Front Grill',
                'headlights' => 'Headlights',
                'fog_lights' => 'Fog Lights',
                'windshield' => 'Windshield',
                'windshield_wipers' => 'Windshield Wipers'
            ],
            'rear' => [
                'rear_bumper' => 'Rear Bumper',
                'tail_lights' => 'Tail Lights',
                'rear_fog_lights' => 'Rear Fog Lights',
                'rear_windshield' => 'Rear Windshield',
                'rear_wiper' => 'Rear Wiper',
                'boot_lid' => 'Boot Lid / Trunk'
            ],
            'left' => [
                'left_front_door' => 'Front Door',
                'left_rear_door' => 'Rear Door',
                'left_mirror' => 'Side Mirror',
                'left_side_molding' => 'Side Molding',
                'left_side_glass' => 'Side Glass'
            ],
            'right' => [
                'right_front_door' => 'Front Door',
                'right_rear_door' => 'Rear Door',
                'right_mirror' => 'Side Mirror',
                'right_side_molding' => 'Side Molding',
                'right_side_glass' => 'Side Glass'
            ],
            'top' => [
                'roof_condition' => 'Roof Condition',
                'sunroof' => 'Sunroof / Moonroof',
                'roof_rails' => 'Roof Rails',
                'aerial_antenna' => 'Aerial / Antenna'
            ]
        ];
    }
    
    public function getStatusOptions() {
        return [
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled'
        ];
    }
    
    public function getPriorityOptions() {
        return [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'urgent' => 'Urgent'
        ];
    }
    
    public function getFuelLevelOptions() {
        return [
            'Reserve' => '⛽ Reserve',
            'Quarter' => '⛽ Quarter',
            'Half' => '⛽ Half',
            'Three Quarter' => '⛽ Three Quarter',
            'Full' => '⛽ Full'
        ];
    }
    
    public function getInspectionStatusOptions() {
        return [
            'Good' => '✓ Good',
            'Fair' => '⚠ Fair',
            'Poor' => '✗ Poor',
            'Missing' => '✗ Missing',
            'Minor Scratch' => '⚠ Minor Scratch',
            'Dent' => '⚠ Dent',
            'Crack' => '✗ Crack',
            'Damaged' => '✗ Damaged',
            'Not Closing' => '✗ Not Closing',
            'Rust' => '✗ Rust'
        ];
    }
}
?>