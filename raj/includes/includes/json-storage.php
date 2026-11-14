<?php
// /raj/includes/json-storage.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/encryption.php';

/**
 * JSON Storage Handler with Safety Features
 * - flock() for file locking
 * - Atomic writes (write to temp, then rename)
 * - Backup/version history
 * - Concurrency protection
 */

class JsonStorage {
    private $basePath;
    private $maxBackups = 10;
    private $lockTimeout = 5; // seconds

    public function __construct($basePath = null) {
        $this->basePath = $basePath ?? STORAGE_PATH;
        $this->ensureDirectoryExists($this->basePath);
    }

    /**
     * Ensure directory exists with proper permissions
     */
    private function ensureDirectoryExists($path) {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Get the path for a specific agent
     */
    private function getAgentPath($agentId) {
        $path = $this->basePath . $agentId . '/';
        $this->ensureDirectoryExists($path);
        return $path;
    }

    /**
     * Get the path for a specific file
     */
    private function getFilePath($agentId, $filename) {
        return $this->getAgentPath($agentId) . $filename;
    }

    /**
     * Read JSON file with flock() protection
     */
    public function readJson($agentId, $filename) {
        $filePath = $this->getFilePath($agentId, $filename);
        
        if (!file_exists($filePath)) {
            return null;
        }

        $fp = fopen($filePath, 'r');
        if (!$fp) {
            error_log("Failed to open file: $filePath");
            return null;
        }

        // Acquire shared lock
        if (flock($fp, LOCK_SH)) {
            $content = '';
            while (!feof($fp)) {
                $content .= fread($fp, 8192);
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error in $filePath: " . json_last_error_msg());
                return null;
            }
            return $data;
        }
        
        fclose($fp);
        error_log("Failed to acquire lock for: $filePath");
        return null;
    }

    /**
     * Write JSON file with atomic write and flock() protection
     */
    public function writeJson($agentId, $filename, $data, $createBackup = true) {
        $filePath = $this->getFilePath($agentId, $filename);
        $tempPath = $filePath . '.tmp';
        $backupPath = $filePath . '.backup';
        
        // Validate data
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log("JSON encode error in $filePath: " . json_last_error_msg());
            return false;
        }

        // Create backup before writing
        if ($createBackup && file_exists($filePath)) {
            $this->createBackup($agentId, $filename);
        }

        // Write to temporary file first
        $fp = fopen($tempPath, 'w');
        if (!$fp) {
            error_log("Failed to open temp file: $tempPath");
            return false;
        }

        // Acquire exclusive lock on temp file
        if (flock($fp, LOCK_EX)) {
            // Write to temp file
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            
            // Atomic rename - this is the critical atomic operation
            if (rename($tempPath, $filePath)) {
                // Verify the written data
                $verify = $this->readJson($agentId, $filename);
                if ($verify !== null) {
                    return true;
                } else {
                    // If verification fails, restore from backup
                    if (file_exists($backupPath)) {
                        rename($backupPath, $filePath);
                        error_log("Restored from backup for: $filePath");
                    }
                    return false;
                }
            } else {
                error_log("Atomic rename failed: $tempPath -> $filePath");
                return false;
            }
        }
        
        fclose($fp);
        error_log("Failed to acquire exclusive lock for: $tempPath");
        return false;
    }

    /**
     * Create a backup of a file
     */
    private function createBackup($agentId, $filename) {
        $filePath = $this->getFilePath($agentId, $filename);
        if (!file_exists($filePath)) {
            return false;
        }

        $backupDir = $this->getAgentPath($agentId) . 'backups/';
        $this->ensureDirectoryExists($backupDir);

        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = $backupDir . $filename . '.' . $timestamp . '.bak';
        
        if (copy($filePath, $backupPath)) {
            // Clean up old backups
            $this->cleanupBackups($agentId, $filename);
            return true;
        }
        
        return false;
    }

    /**
     * Clean up old backups (keep only $maxBackups)
     */
    private function cleanupBackups($agentId, $filename) {
        $backupDir = $this->getAgentPath($agentId) . 'backups/';
        if (!is_dir($backupDir)) {
            return;
        }

        $pattern = $backupDir . $filename . '.*.bak';
        $backups = glob($pattern);
        
        if (count($backups) > $this->maxBackups) {
            // Sort by modification time (oldest first)
            usort($backups, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest backups
            $toRemove = array_slice($backups, 0, count($backups) - $this->maxBackups);
            foreach ($toRemove as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Restore a specific backup version
     */
    public function restoreBackup($agentId, $filename, $version = null) {
        $backupDir = $this->getAgentPath($agentId) . 'backups/';
        if (!is_dir($backupDir)) {
            return false;
        }

        $pattern = $backupDir . $filename . '.*.bak';
        $backups = glob($pattern);
        
        if (empty($backups)) {
            return false;
        }

        // If no version specified, use the latest backup
        if ($version === null) {
            usort($backups, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $backupFile = $backups[0];
        } else {
            // Find specific version
            foreach ($backups as $backup) {
                if (strpos($backup, $version) !== false) {
                    $backupFile = $backup;
                    break;
                }
            }
            if (!isset($backupFile)) {
                return false;
            }
        }

        $targetPath = $this->getFilePath($agentId, $filename);
        return copy($backupFile, $targetPath);
    }

    /**
     * Get list of available backups for an agent
     */
    public function getBackups($agentId, $filename) {
        $backupDir = $this->getAgentPath($agentId) . 'backups/';
        if (!is_dir($backupDir)) {
            return [];
        }

        $pattern = $backupDir . $filename . '.*.bak';
        $backups = glob($pattern);
        
        $result = [];
        foreach ($backups as $backup) {
            $basename = basename($backup);
            $parts = explode('.', $basename);
            // Extract timestamp from filename
            if (count($parts) >= 3) {
                $timestamp = $parts[count($parts) - 2];
                $result[] = [
                    'file' => $basename,
                    'timestamp' => $timestamp,
                    'datetime' => date('Y-m-d H:i:s', strtotime(str_replace('_', ':', $timestamp))),
                    'size' => filesize($backup)
                ];
            }
        }
        
        // Sort by timestamp descending (newest first)
        usort($result, function($a, $b) {
            return strtotime($b['datetime']) - strtotime($a['datetime']);
        });
        
        return $result;
    }

    /**
     * Get agent profile with decryption
     */
    public function getAgentProfile($agentId) {
        $profile = $this->readJson($agentId, 'profile.json');
        if (!$profile) {
            return null;
        }
        
        // Decrypt sensitive fields if they are encrypted
        if (isset($profile['documents']['pan']['number'])) {
            $profile['documents']['pan']['number_decrypted'] = decryptData($profile['documents']['pan']['number']);
        }
        if (isset($profile['documents']['aadhaar']['number'])) {
            $profile['documents']['aadhaar']['number_decrypted'] = decryptData($profile['documents']['aadhaar']['number']);
        }
        if (isset($profile['address_verification']['latitude'])) {
            $profile['address_verification']['latitude_decrypted'] = decryptData($profile['address_verification']['latitude']);
            $profile['address_verification']['longitude_decrypted'] = decryptData($profile['address_verification']['longitude']);
        }
        
        return $profile;
    }

    /**
     * Save agent profile with encryption
     */
    public function saveAgentProfile($agentId, $profile) {
        // Encrypt sensitive fields before saving
        if (isset($profile['documents']['pan']['number_decrypted'])) {
            $profile['documents']['pan']['number'] = encryptData($profile['documents']['pan']['number_decrypted']);
            unset($profile['documents']['pan']['number_decrypted']);
        }
        if (isset($profile['documents']['aadhaar']['number_decrypted'])) {
            $profile['documents']['aadhaar']['number'] = encryptData($profile['documents']['aadhaar']['number_decrypted']);
            unset($profile['documents']['aadhaar']['number_decrypted']);
        }
        if (isset($profile['address_verification']['latitude_decrypted'])) {
            $profile['address_verification']['latitude'] = encryptData($profile['address_verification']['latitude_decrypted']);
            $profile['address_verification']['longitude'] = encryptData($profile['address_verification']['longitude_decrypted']);
            unset($profile['address_verification']['latitude_decrypted']);
            unset($profile['address_verification']['longitude_decrypted']);
        }
        
        return $this->writeJson($agentId, 'profile.json', $profile, true);
    }

    /**
     * Add entry to audit log
     */
    public function addAuditLog($agentId, $action, $details = '') {
        $auditPath = $this->getFilePath($agentId, 'audit.json');
        $logs = [];
        
        if (file_exists($auditPath)) {
            $existing = $this->readJson($agentId, 'audit.json');
            if ($existing) {
                $logs = $existing;
            }
        }
        
        $logs[] = [
            'action' => $action,
            'timestamp' => date('c'),
            'details' => $details
        ];
        
        return $this->writeJson($agentId, 'audit.json', $logs, false);
    }

    /**
     * Get audit log
     */
    public function getAuditLog($agentId) {
        $logs = $this->readJson($agentId, 'audit.json');
        return $logs ?: [];
    }

    /**
     * Initialize agent storage structure
     */
    public function initializeAgent($agentId, $initialData = []) {
        $agentPath = $this->getAgentPath($agentId);
        
        // Create subdirectories
        $subdirs = ['documents', 'pan', 'aadhaar', 'candidate', 'address', 'backups'];
        foreach ($subdirs as $subdir) {
            $this->ensureDirectoryExists($agentPath . $subdir);
        }
        
        // Create default profile if not exists
        $profilePath = $this->getFilePath($agentId, 'profile.json');
        if (!file_exists($profilePath)) {
            $defaultProfile = [
                'agent_id' => $agentId,
                'account' => [
                    'status' => 'active',
                    'created_at' => date('c'),
                    'onboarding_completed' => false
                ],
                'personal_information' => [
                    'full_name' => $initialData['full_name'] ?? '',
                    'father_name' => $initialData['father_name'] ?? '',
                    'mother_name' => $initialData['mother_name'] ?? '',
                    'mobile' => $initialData['mobile'] ?? '',
                    'email' => $initialData['email'] ?? '',
                    'email_verified' => false
                ],
                'documents' => [
                    'resume' => '',
                    'pan' => ['number' => '', 'image' => ''],
                    'aadhaar' => ['number' => '', 'front' => '', 'back' => ''],
                    'candidate_photo' => ''
                ],
                'address_verification' => [
                    'latitude' => '',
                    'longitude' => '',
                    'accuracy' => '',
                    'location_timestamp' => '',
                    'house_photo_1' => '',
                    'house_photo_2' => '',
                    'street_photo' => ''
                ],
                'address' => [
                    'house_number' => '',
                    'street' => '',
                    'locality' => '',
                    'landmark' => '',
                    'city' => '',
                    'state' => '',
                    'pincode' => '',
                    'country' => 'India'
                ],
                'submission' => [
                    'submitted' => false,
                    'submitted_at' => ''
                ],
                'shift' => [
                    'type' => 'day',
                    'work_location' => 'office',
                    'assigned_by' => '',
                    'assigned_at' => ''
                ]
            ];
            
            $this->writeJson($agentId, 'profile.json', $defaultProfile, false);
        }
        
        // Create empty audit log if not exists
        $auditPath = $this->getFilePath($agentId, 'audit.json');
        if (!file_exists($auditPath)) {
            $this->writeJson($agentId, 'audit.json', [], false);
        }
        
        return true;
    }

    /**
     * Delete agent data (with backup)
     */
    public function deleteAgent($agentId) {
        $agentPath = $this->getAgentPath($agentId);
        if (!is_dir($agentPath)) {
            return false;
        }
        
        // Create backup before deletion
        $backupDir = $this->basePath . 'deleted_agents/';
        $this->ensureDirectoryExists($backupDir);
        $backupPath = $backupDir . $agentId . '_' . date('Y-m-d_H-i-s') . '/';
        
        // Recursively copy agent data to backup
        $this->recursiveCopy($agentPath, $backupPath);
        
        // Delete agent directory
        $this->recursiveDelete($agentPath);
        
        return true;
    }

    /**
     * Recursively copy directory
     */
    private function recursiveCopy($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Recursively delete directory
     */
    private function recursiveDelete($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }

    /**
     * Export all agent data as JSON
     */
    public function exportAgentData($agentId) {
        $data = [
            'agent_id' => $agentId,
            'exported_at' => date('c'),
            'profile' => $this->readJson($agentId, 'profile.json'),
            'audit' => $this->readJson($agentId, 'audit.json'),
            'backups' => $this->getBackups($agentId, 'profile.json')
        ];
        return $data;
    }

    /**
     * Import agent data from JSON
     */
    public function importAgentData($agentId, $data) {
        if (!isset($data['profile'])) {
            return false;
        }
        
        $this->initializeAgent($agentId);
        
        if (isset($data['profile'])) {
            $this->writeJson($agentId, 'profile.json', $data['profile'], true);
        }
        if (isset($data['audit'])) {
            $this->writeJson($agentId, 'audit.json', $data['audit'], false);
        }
        
        return true;
    }

    /**
     * Get storage statistics
     */
    public function getStats() {
        $stats = [
            'total_agents' => 0,
            'total_size' => 0,
            'backup_count' => 0,
            'agents' => []
        ];
        
        if (!is_dir($this->basePath)) {
            return $stats;
        }
        
        $agents = glob($this->basePath . 'BGS*', GLOB_ONLYDIR);
        $stats['total_agents'] = count($agents);
        
        foreach ($agents as $agentDir) {
            $agentId = basename($agentDir);
            $size = $this->getDirectorySize($agentDir);
            $stats['agents'][$agentId] = [
                'size' => $size,
                'size_formatted' => $this->formatSize($size)
            ];
            $stats['total_size'] += $size;
            
            // Count backups
            $backupDir = $agentDir . '/backups/';
            if (is_dir($backupDir)) {
                $backups = glob($backupDir . '*.bak');
                $stats['backup_count'] += count($backups);
            }
        }
        
        $stats['total_size_formatted'] = $this->formatSize($stats['total_size']);
        return $stats;
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize($dir) {
        $size = 0;
        if (!is_dir($dir)) {
            return $size;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $size += $this->getDirectorySize($path);
            } else {
                $size += filesize($path);
            }
        }
        return $size;
    }

    /**
     * Format file size
     */
    private function formatSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}

// ============================================================
// HELPER FUNCTIONS FOR GLOBAL ACCESS (NO DUPLICATES)
// ============================================================

function getJsonStorage() {
    static $storage = null;
    if ($storage === null) {
        $storage = new JsonStorage();
    }
    return $storage;
}

// Note: The following functions are ONLY for backward compatibility
// They call the JsonStorage class methods directly
// Do not redeclare these anywhere else!

function readAgentJson($agentId, $filename) {
    return getJsonStorage()->readJson($agentId, $filename);
}

function writeAgentJson($agentId, $filename, $data) {
    return getJsonStorage()->writeJson($agentId, $filename, $data);
}

function getAgentBackups($agentId) {
    return getJsonStorage()->getBackups($agentId, 'profile.json');
}

function restoreAgentBackup($agentId, $version = null) {
    return getJsonStorage()->restoreBackup($agentId, 'profile.json', $version);
}

function exportAgentData($agentId) {
    return getJsonStorage()->exportAgentData($agentId);
}

function importAgentData($agentId, $data) {
    return getJsonStorage()->importAgentData($agentId, $data);
}

function getStorageStats() {
    return getJsonStorage()->getStats();
}
?>