<?php
class DateHelper {
    
    public static function getWeekStart($date = null) {
        $date = $date ?? date('Y-m-d');
        return date('Y-m-d', strtotime('monday this week', strtotime($date)));
    }
    
    public static function getWeekEnd($date = null) {
        $date = $date ?? date('Y-m-d');
        return date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    }
    
    public static function getMonthStart($date = null) {
        $date = $date ?? date('Y-m-d');
        return date('Y-m-01', strtotime($date));
    }
    
    public static function getMonthEnd($date = null) {
        $date = $date ?? date('Y-m-d');
        return date('Y-m-t', strtotime($date));
    }
    
    public static function getQuarterStart($date = null) {
        $date = $date ?? date('Y-m-d');
        $month = ceil(date('n', strtotime($date)) / 3);
        $startMonth = ($month - 1) * 3 + 1;
        return date('Y-m-01', strtotime(date('Y', strtotime($date)) . '-' . $startMonth . '-01'));
    }
    
    public static function getQuarterEnd($date = null) {
        $date = $date ?? date('Y-m-d');
        $quarterStart = self::getQuarterStart($date);
        return date('Y-m-t', strtotime($quarterStart . ' +2 months'));
    }
    
    public static function getDaysInRange($startDate, $endDate) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day');
        
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        
        $days = [];
        foreach ($period as $date) {
            $days[] = $date->format('Y-m-d');
        }
        
        return $days;
    }
    
    public static function formatDate($date, $format = 'd/m/Y') {
        if (!$date) return '';
        return date($format, strtotime($date));
    }
    
    public static function formatTime($time, $format = 'h:i A') {
        if (!$time) return '';
        return date($format, strtotime($time));
    }
    
    public static function getWorkingHours($startTime, $endTime) {
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $interval = $start->diff($end);
        return $interval->h + ($interval->i / 60);
    }
    
    public static function isWeekend($date) {
        $day = date('N', strtotime($date));
        return $day >= 6;
    }
    
    public static function getHolidays($year = null) {
        $year = $year ?? date('Y');
        
        // Uganda public holidays
        $holidays = [
            "$year-01-01" => "New Year's Day",
            "$year-01-26" => "NRM Liberation Day",
            "$year-02-16" => "Archbishop Janani Luwum Day",
            "$year-03-08" => "International Women's Day",
            "$year-05-01" => "Labour Day",
            "$year-06-03" => "Martyrs' Day",
            "$year-06-09" => "National Heroes' Day",
            "$year-10-09" => "Independence Day",
            "$year-12-25" => "Christmas Day",
            "$year-12-26" => "Boxing Day"
        ];
        
        // Add variable holidays (Easter, Eid, etc. would need external API or manual entry)
        
        return $holidays;
    }
    
    public static function isHoliday($date) {
        $holidays = self::getHolidays(date('Y', strtotime($date)));
        return isset($holidays[$date]);
    }
}
?>