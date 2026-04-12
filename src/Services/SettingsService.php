<?php
namespace App\Services;

use PDO;

class SettingsService {
    private $db;
    private $cache = [];

    public function __construct($db) {
        $this->db = $db;
    }

    public function get($key, $default = null) {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $stmt = $this->db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $value = $result ? $result['setting_value'] : $default;
        $this->cache[$key] = $value;
        
        return $value;
    }

    public function getMultiple($keys) {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $values = [];
        foreach ($results as $row) {
            $values[$row['setting_key']] = $row['setting_value'];
            $this->cache[$row['setting_key']] = $row['setting_value'];
        }
        
        return $values;
    }

    public function set($key, $value) {
        $stmt = $this->db->prepare("SELECT id FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        
        if ($stmt->fetch()) {
            $stmt = $this->db->prepare("UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO site_settings (setting_key, setting_value, category) VALUES (?, ?, 'automation')");
            $stmt->execute([$key, $value]);
        }
        
        $this->cache[$key] = $value;
        return true;
    }

    public function clearCache() {
        $this->cache = [];
    }
}
