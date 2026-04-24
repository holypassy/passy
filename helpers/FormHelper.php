<?php
class FormHelper {
    
    public static function generateSelect($name, $options, $selected = null, $attributes = []) {
        $html = "<select name=\"{$name}\" id=\"{$name}\"";
        
        foreach ($attributes as $key => $value) {
            $html .= " {$key}=\"{$value}\"";
        }
        
        $html .= ">\n";
        $html .= "<option value=\"\">-- Select --</option>\n";
        
        foreach ($options as $value => $label) {
            $selectedAttr = ($selected == $value) ? ' selected' : '';
            $html .= "<option value=\"{$value}\"{$selectedAttr}>" . htmlspecialchars($label) . "</option>\n";
        }
        
        $html .= "</select>\n";
        
        return $html;
    }
    
    public static function generateInput($type, $name, $value = '', $attributes = []) {
        $html = "<input type=\"{$type}\" name=\"{$name}\" id=\"{$name}\" value=\"" . htmlspecialchars($value) . "\"";
        
        foreach ($attributes as $key => $attrValue) {
            $html .= " {$key}=\"{$attrValue}\"";
        }
        
        $html .= ">\n";
        
        return $html;
    }
    
    public static function generateTextarea($name, $value = '', $rows = 3, $attributes = []) {
        $html = "<textarea name=\"{$name}\" id=\"{$name}\" rows=\"{$rows}\"";
        
        foreach ($attributes as $key => $attrValue) {
            $html .= " {$key}=\"{$attrValue}\"";
        }
        
        $html .= ">" . htmlspecialchars($value) . "</textarea>\n";
        
        return $html;
    }
    
    public static function generateCheckbox($name, $value = '1', $checked = false, $label = '') {
        $checkedAttr = $checked ? ' checked' : '';
        $html = "<input type=\"checkbox\" name=\"{$name}\" id=\"{$name}\" value=\"{$value}\"{$checkedAttr}>";
        
        if ($label) {
            $html .= "<label for=\"{$name}\"> {$label}</label>";
        }
        
        return $html;
    }
    
    public static function generateRadio($name, $value, $label, $checked = false) {
        $checkedAttr = $checked ? ' checked' : '';
        $html = "<input type=\"radio\" name=\"{$name}\" id=\"{$name}_{$value}\" value=\"{$value}\"{$checkedAttr}>";
        $html .= "<label for=\"{$name}_{$value}\"> {$label}</label>";
        
        return $html;
    }
    
    public static function getFuelLevelIcon($level) {
        $icons = [
            'Reserve' => '⚠️',
            'Quarter' => '⛽▁',
            'Half' => '⛽▃',
            'Three Quarter' => '⛽▇',
            'Full' => '⛽█'
        ];
        
        return $icons[$level] ?? '⛽';
    }
    
    public static function getStatusBadge($status) {
        $badges = [
            'Good' => '<span class="badge-success">✓ Good</span>',
            'Fair' => '<span class="badge-warning">⚠ Fair</span>',
            'Poor' => '<span class="badge-danger">✗ Poor</span>',
            'Missing' => '<span class="badge-danger">✗ Missing</span>',
            'Minor Scratch' => '<span class="badge-warning">⚠ Minor Scratch</span>',
            'Dent' => '<span class="badge-warning">⚠ Dent</span>',
            'Crack' => '<span class="badge-danger">✗ Crack</span>',
            'Damaged' => '<span class="badge-danger">✗ Damaged</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge-secondary">' . $status . '</span>';
    }
}
?>