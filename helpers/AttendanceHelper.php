<?php
class AttendanceHelper {
    
    public static function calculateTotalHours($checkIn, $checkOut) {
        if (!$checkIn || !$checkOut) return 0;
        
        $checkInTime = strtotime($checkIn);
        $checkOutTime = strtotime($checkOut);
        
        $diff = $checkOutTime - $checkInTime;
        $hours = $diff / 3600;
        
        return round($hours, 1);
    }
    
    public static function getStatusFromTime($checkInTime, $expectedStartTime = '08:00:00') {
        $checkIn = strtotime($checkInTime);
        $expected = strtotime($expectedStartTime);
        
        if ($checkIn > $expected + 900) { // More than 15 minutes late
            return 'late';
        }
        
        return 'present';
    }
    
    public static function getAttendanceRate($presentDays, $totalDays) {
        if ($totalDays == 0) return 0;
        return round(($presentDays / $totalDays) * 100, 1);
    }
    
    public static function getPunctualityRate($onTimeDays, $totalDays) {
        if ($totalDays == 0) return 0;
        return round(($onTimeDays / $totalDays) * 100, 1);
    }
    
    public static function getOvertimePay($hours, $hourlyRate, $multiplier = 1.5) {
        return $hours * $hourlyRate * $multiplier;
    }
    
    public static function getLateMinutes($checkInTime, $expectedStartTime = '08:00:00') {
        $checkIn = strtotime($checkInTime);
        $expected = strtotime($expectedStartTime);
        
        if ($checkIn <= $expected) return 0;
        
        return round(($checkIn - $expected) / 60);
    }
    
    public static function getEarlyDepartureMinutes($checkOutTime, $expectedEndTime = '17:00:00') {
        $checkOut = strtotime($checkOutTime);
        $expected = strtotime($expectedEndTime);
        
        if ($checkOut >= $expected) return 0;
        
        return round(($expected - $checkOut) / 60);
    }
    
    public static function formatAttendanceSummary($attendanceRecords) {
        $summary = [
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'half_day' => 0,
            'on_leave' => 0,
            'total_hours' => 0,
            'total_days' => count($attendanceRecords)
        ];
        
        foreach ($attendanceRecords as $record) {
            if (isset($summary[$record['status']])) {
                $summary[$record['status']]++;
            }
            $summary['total_hours'] += $record['total_hours'] ?? 0;
        }
        
        $summary['attendance_rate'] = self::getAttendanceRate(
            $summary['present'] + $summary['late'],
            $summary['total_days']
        );
        
        return $summary;
    }
}
?>