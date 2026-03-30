<?php
/**
 * Centralized system settings accessor.
 *
 * Settings are stored in system_settings(setting_name, setting_value, ...).
 * Values are cached per request for fast repeated lookups.
 */

if (!function_exists('loadSystemSettingsCache')) {
    function loadSystemSettingsCache() {
        static $loaded = false;
        static $cache = [];

        if ($loaded) {
            return $cache;
        }

        $loaded = true;

        // Use existing app connection helper when available.
        if (!function_exists('getDBConnection')) {
            $databaseFile = __DIR__ . '/../config/database.php';
            if (is_file($databaseFile)) {
                require_once $databaseFile;
            }
        }

        if (!function_exists('getDBConnection')) {
            return $cache;
        }

        $conn = getDBConnection();
        if (!$conn) {
            return $cache;
        }

        $result = $conn->query("SELECT setting_name, setting_value FROM system_settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $key = (string)($row['setting_name'] ?? '');
                if ($key === '') {
                    continue;
                }
                $cache[$key] = (string)($row['setting_value'] ?? '');
            }
        }

        return $cache;
    }
}

if (!function_exists('getSystemSetting')) {
    function getSystemSetting($key, $default = null) {
        $cache = loadSystemSettingsCache();
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        return $default;
    }
}

if (!function_exists('getSystemSettingInt')) {
    function getSystemSettingInt($key, $default, $min = null, $max = null) {
        $raw = getSystemSetting($key, $default);
        if (!is_numeric($raw)) {
            $value = (int)$default;
        } else {
            $value = (int)$raw;
        }

        if ($min !== null && $value < $min) {
            $value = (int)$min;
        }
        if ($max !== null && $value > $max) {
            $value = (int)$max;
        }

        return $value;
    }
}

if (!function_exists('getSystemSettingBool')) {
    function getSystemSettingBool($key, $default = false) {
        $fallback = $default ? '1' : '0';
        $raw = strtolower(trim((string)getSystemSetting($key, $fallback)));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
