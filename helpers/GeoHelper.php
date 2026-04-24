<?php
class GeoHelper {
    
    public static function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + 
                cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $km = $miles * 1.609344;
        
        return round($km, 2);
    }
    
    public static function getAddressFromCoordinates($lat, $lng) {
        // Google Maps Geocoding API
        $apiKey = GOOGLE_MAPS_API_KEY ?? '';
        if (empty($apiKey)) {
            return null;
        }
        
        $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key={$apiKey}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($data['status'] == 'OK') {
            return $data['results'][0]['formatted_address'] ?? null;
        }
        
        return null;
    }
    
    public static function getCoordinatesFromAddress($address) {
        $apiKey = GOOGLE_MAPS_API_KEY ?? '';
        if (empty($apiKey)) {
            return null;
        }
        
        $address = urlencode($address);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($data['status'] == 'OK') {
            return [
                'lat' => $data['results'][0]['geometry']['location']['lat'],
                'lng' => $data['results'][0]['geometry']['location']['lng']
            ];
        }
        
        return null;
    }
    
    public static function getOptimizedRoute($addresses) {
        // Google Maps Directions API for route optimization
        $apiKey = GOOGLE_MAPS_API_KEY ?? '';
        if (empty($apiKey) || count($addresses) < 2) {
            return null;
        }
        
        $origin = urlencode($addresses[0]);
        $destination = urlencode(end($addresses));
        $waypoints = array_slice($addresses, 1, -1);
        $waypointsStr = implode('|', array_map('urlencode', $waypoints));
        
        $url = "https://maps.googleapis.com/maps/api/directions/json?origin={$origin}&destination={$destination}&waypoints=optimize:true|{$waypointsStr}&key={$apiKey}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($data['status'] == 'OK') {
            $route = $data['routes'][0];
            return [
                'distance' => $route['legs'][0]['distance']['text'],
                'duration' => $route['legs'][0]['duration']['text'],
                'waypoint_order' => $route['waypoint_order'],
                'polyline' => $route['overview_polyline']['points']
            ];
        }
        
        return null;
    }
}
?>