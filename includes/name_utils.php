<?php
/**
 * Utility functions for handling student names throughout the system
 * Properly handles cases where students don't have middle names
 */

/**
 * Format a full name in "Last, First Middle" format
 * @param string $last_name
 * @param string $first_name
 * @param string|null $middle_name
 * @return string Formatted full name
 */
function formatFullName($last_name, $first_name, $middle_name = null) {
    $formatted_name = trim($last_name) . ", " . trim($first_name);
    
    if (!empty($middle_name)) {
        $formatted_name .= " " . trim($middle_name);
    }
    
    return $formatted_name;
}

/**
 * Format a name in "First Middle Last" format
 * @param string $first_name
 * @param string|null $middle_name
 * @param string $last_name
 * @return string Formatted name
 */
function formatFirstMiddleLast($first_name, $middle_name = null, $last_name) {
    $formatted_name = trim($first_name);
    
    if (!empty($middle_name)) {
        $formatted_name .= " " . trim($middle_name);
    }
    
    $formatted_name .= " " . trim($last_name);
    
    return $formatted_name;
}

/**
 * Get middle initial if middle name exists
 * @param string|null $middle_name
 * @return string Middle initial with period, or empty string
 */
function getMiddleInitial($middle_name = null) {
    if (!empty($middle_name)) {
        return strtoupper(substr(trim($middle_name), 0, 1)) . ".";
    }
    return "";
}

/**
 * Format name with middle initial in "Last, First M." format
 * @param string $last_name
 * @param string $first_name
 * @param string|null $middle_name
 * @return string Formatted name with middle initial
 */
function formatNameWithInitial($last_name, $first_name, $middle_name = null) {
    $formatted_name = trim($last_name) . ", " . trim($first_name);
    
    $middle_initial = getMiddleInitial($middle_name);
    if (!empty($middle_initial)) {
        $formatted_name .= " " . $middle_initial;
    }
    
    return $formatted_name;
}

/**
 * Sanitize and format name parts
 * @param string|null $name_part
 * @return string|null Sanitized name part or null if empty
 */
function sanitizeNamePart($name_part) {
    if (empty($name_part)) {
        return null;
    }
    
    // Remove extra spaces and capitalize properly
    $name_part = trim($name_part);
    if (empty($name_part)) {
        return null;
    }
    
    // Capitalize first letter of each word
    return ucwords(strtolower($name_part));
}
?>