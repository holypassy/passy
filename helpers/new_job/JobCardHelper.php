<?php
class JobCardHelper {
    
    public static function formatInspectionData($inspections) {
        $formatted = [];
        
        foreach ($inspections as $section => $items) {
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!empty($item['item_name'])) {
                        $formatted[] = [
                            'section' => $section,
                            'item' => $item['item_name'],
                            'status' => $item['status'] ?? 'Good',
                            'notes' => $item['notes'] ?? null
                        ];
                    }
                }
            }
        }
        
        return $formatted;
    }
    
    public static function generateJobCardHTML($jobCard, $items, $inspections) {
        $html = '
        <div class="job-card-print">
            <div class="header">
                <h1>SAVANT MOTORS UGANDA</h1>
                <p>Job Card #' . htmlspecialchars($jobCard['job_number']) . '</p>
            </div>
            <div class="customer-info">
                <h3>Customer Information</h3>
                <p><strong>Name:</strong> ' . htmlspecialchars($jobCard['customer_full_name']) . '</p>
                <p><strong>Phone:</strong> ' . htmlspecialchars($jobCard['customer_phone']) . '</p>
                <p><strong>Vehicle:</strong> ' . htmlspecialchars($jobCard['vehicle_reg']) . '</p>
            </div>
            <div class="work-items">
                <h3>Work to be Done</h3>
                <table>
                    <thead>
                        <tr><th>Part No.</th><th>Description</th> </tr>
                    </thead>
                    <tbody>';
        
        foreach ($items as $item) {
            $html .= '<tr><td>' . htmlspecialchars($item['part_number']) . '</td><td>' . htmlspecialchars($item['description']) . '</td></tr>';
        }
        
        $html .= '
                    </tbody>
                </table>
            </div>
            <div class="inspections">
                <h3>Vehicle Inspection</h3>';
        
        foreach ($inspections as $inspection) {
            $html .= '<p><strong>' . ucfirst($inspection['section']) . ':</strong> ' . htmlspecialchars($inspection['item']) . ' - ' . $inspection['status'] . '</p>';
        }
        
        $html .= '
            </div>
            <div class="footer">
                <p>Terms and conditions apply. Please read carefully before signing.</p>
                <div class="signatures">
                    <div>Customer Signature: _________________</div>
                    <div>Technician Signature: _________________</div>
                </div>
            </div>
        </div>';
        
        return $html;
    }
    
    public static function validateJobData($data) {
        $errors = [];
        
        if (empty($data['customer_id'])) {
            $errors[] = 'Customer is required';
        }
        
        if (empty($data['vehicle_reg'])) {
            $errors[] = 'Vehicle registration is required';
        }
        
        if (!empty($data['odometer_reading']) && !is_numeric(preg_replace('/[^0-9]/', '', $data['odometer_reading']))) {
            $errors[] = 'Odometer reading must be a number';
        }
        
        return $errors;
    }
    
    public static function getVehicleMakes() {
        return [
            'Toyota', 'Honda', 'Nissan', 'Mitsubishi', 'Subaru', 'Mazda', 
            'Suzuki', 'Isuzu', 'Ford', 'Volkswagen', 'Mercedes-Benz', 
            'BMW', 'Audi', 'Lexus', 'Land Rover', 'Jeep', 'Hyundai', 
            'Kia', 'Chevrolet', 'Other'
        ];
    }
    
    public static function getInspectionSections() {
        return [
            'basic' => 'Basic Items',
            'front' => 'Front Inspection',
            'rear' => 'Rear Inspection',
            'left' => 'Left Side',
            'right' => 'Right Side',
            'top' => 'Top View'
        ];
    }
    
    public static function getDefaultWorkItems() {
        return [
            ['part_number' => '', 'description' => '']
        ];
    }
    
    public static function getTermsAndConditions() {
        return [
            [
                'number' => 1,
                'text' => 'Only repairs set out overleaf will be carried out. Any other defects discovered will be drawn to your attention.'
            ],
            [
                'number' => 2,
                'text' => 'All estimates are based on prevailing labour rate and parts/material prices at the time repairs are carried out.'
            ],
            [
                'number' => 3,
                'text' => 'Storage charges of UGX 10,000 per day apply from 3 days after completion notification.'
            ],
            [
                'number' => 4,
                'text' => 'All items accepted fall under the UNCOLLECTED GOODS ACT (1952).'
            ],
            [
                'number' => 5,
                'text' => 'The Company accepts no liability for fault or defective workmanship once the vehicle has been taken away.'
            ],
            [
                'number' => 6,
                'text' => 'Unserviceable parts will be disposed of unless claimed within hours of completion.'
            ],
            [
                'number' => 7,
                'text' => 'Queries on invoices will not be entertained if not received within 24 hours after invoice issue date.'
            ],
            [
                'number' => 8,
                'text' => 'A penalty of 5% per month applies on outstanding amounts after thirty days from invoice date.'
            ]
        ];
    }
}
?>