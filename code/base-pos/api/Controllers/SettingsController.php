<?php
class SettingsController extends Controller
{
    private const SETTINGS_ROLES = ['admin', 'manager', 'super_manager'];
    private const BRANCH_STORE_KEYS = [
        'store_phone', 'store_address', 'receipt_footer', 'receipt_welcome_message'
    ];
    private const GLOBAL_STORE_KEYS = [
        'store_name', 'tax_id', 'tax_rate', 'currency_symbol', 'scrap_license_no'
    ];
    private const BRANCH_SYSTEM_KEYS = ['low_stock_threshold'];
    private const GLOBAL_SYSTEM_KEYS = ['date_format', 'time_zone', 'language'];

    public function getStoreSettings()
    {
        $this->requireAuth(self::SETTINGS_ROLES);

        $settingModel = new Setting();
        $keys = [
            'store_name',
            'store_phone',
            'store_address',
            'tax_id',
            'tax_rate',
            'currency_symbol',
            'scrap_license_no',
            'receipt_footer',
            'receipt_welcome_message'
        ];
        $settings = $settingModel->getSettingsByKeys($keys);
        $branchId = $this->resolveBranchSettingsId($_GET['branch_id'] ?? null, false);
        if ($branchId !== null) {
            $settings = array_merge($settings, (new BranchSetting())->getByBranch($branchId, $keys));
        }

        Response::success('Store settings retrieved', $settings);
    }

    public function saveStoreSettings()
    {
        $this->requireAuth(self::SETTINGS_ROLES);

        // Get request data
        $data = $this->getRequestData();

        // Sanitize input
        $data = $this->sanitizeInput($data);
        $branchId = $this->resolveBranchSettingsId($data['branch_id'] ?? null, true);
        if (!empty($data['tax_id'])) {
            $data['tax_id'] = preg_replace('/\D+/', '', (string)$data['tax_id']);
            if (strlen($data['tax_id']) !== 13) {
                Response::error('เลขประจำตัวผู้เสียภาษีต้องมี 13 หลัก', 422);
            }
        }

        // Update settings
        $settingModel = new Setting();
        $settingsToUpdate = [
            'store_name' => $data['store_name'] ?? null,
            'store_phone' => $data['store_phone'] ?? null,
            'store_address' => $data['store_address'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? null,
            'currency_symbol' => $data['currency_symbol'] ?? null,
            // ใบอนุญาตค้าของเก่า — ใบเดียวทั้งกิจการ (global only)
            'scrap_license_no' => !empty($data['scrap_license_no'])
                ? mb_substr(trim((string)$data['scrap_license_no']), 0, 100) : null,
            'receipt_footer' => $data['receipt_footer'] ?? null,
            'receipt_welcome_message' => $data['receipt_welcome_message'] ?? null
        ];

        if ($branchId !== null) {
            $this->rejectGlobalSettingKeys($data, self::GLOBAL_STORE_KEYS);
            $settingsToUpdate = array_intersect_key($settingsToUpdate, array_flip(self::BRANCH_STORE_KEYS));
        }

        try {
            if ($branchId === null) {
                $settingModel->updateSettings($settingsToUpdate);
            } else {
                (new BranchSetting())->updateForBranch($branchId, $settingsToUpdate);
            }

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_store_settings',
                $branchId === null ? 'Updated global store settings' : "Updated branch store settings branch:{$branchId}"
            );

            Response::success('Store settings updated successfully');
        } catch (Exception $e) {
            error_log('Store settings update failed: ' . $e->getMessage());
            Response::error('Failed to update store settings', 500);
        }
    }

    public function getSystemSettings()
    {
        $this->requireAuth(self::SETTINGS_ROLES);

        $settingModel = new Setting();
        $keys = [
            'low_stock_threshold',
            'date_format',
            'time_zone',
            'language'
        ];
        $settings = $settingModel->getSettingsByKeys($keys);
        $branchId = $this->resolveBranchSettingsId($_GET['branch_id'] ?? null, false);
        if ($branchId !== null) {
            $settings = array_merge($settings, (new BranchSetting())->getByBranch($branchId, $keys));
        }

        Response::success('System settings retrieved', $settings);
    }

    public function saveSystemSettings()
    {
        $this->requireAuth(self::SETTINGS_ROLES);

        // Get request data
        $data = $this->getRequestData();

        // Sanitize input
        $data = $this->sanitizeInput($data);
        $branchId = $this->resolveBranchSettingsId($data['branch_id'] ?? null, true);

        // Update settings
        $settingModel = new Setting();
        $settingsToUpdate = [
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
            'date_format' => $data['date_format'] ?? null,
            'time_zone' => $data['time_zone'] ?? null,
            'language' => $data['language'] ?? null
        ];

        if ($branchId !== null) {
            $this->rejectGlobalSettingKeys($data, self::GLOBAL_SYSTEM_KEYS);
            $settingsToUpdate = array_intersect_key($settingsToUpdate, array_flip(self::BRANCH_SYSTEM_KEYS));
        }

        try {
            if ($branchId === null) {
                $settingModel->updateSettings($settingsToUpdate);
            } else {
                (new BranchSetting())->updateForBranch($branchId, $settingsToUpdate);
            }

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_system_settings',
                $branchId === null ? 'Updated global system settings' : "Updated branch system settings branch:{$branchId}"
            );

            Response::success('System settings updated successfully');
        } catch (Exception $e) {
            error_log('System settings update failed: ' . $e->getMessage());
            Response::error('Failed to update system settings', 500);
        }
    }

    public function createBackup()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        try {
            $result = BackupService::createBackup();

            if ($result['success']) {
                // Log activity
                Logger::logActivity(
                    $this->user['user_id'],
                    'create_backup',
                    'Created database backup'
                );

                Response::success('Backup created successfully', [
                    'filename' => $result['filename'],
                    'download_url' => '/pos-system/api/settings/backup/download?filename='.$result['filename']
                ]);
            } else {
                Response::error('Failed to create backup: '.$result['message']);
            }
        } catch (Exception $e) {
            error_log('Backup create failed: ' . $e->getMessage());
            Response::error('Error creating backup', 500);
        }
    }

    public function restoreBackup()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        if (!isset($_FILES['backup_file'])) {
            Response::error('No backup file provided', 400);
        }

        try {
            $result = BackupService::restoreBackup($_FILES['backup_file']);

            if ($result['success']) {
                // Log activity
                Logger::logActivity(
                    $this->user['user_id'],
                    'restore_backup',
                    'Restored database from backup'
                );

                Response::success('Backup restored successfully');
            } else {
                Response::error('Failed to restore backup: '.$result['message']);
            }
        } catch (Exception $e) {
            error_log('Backup restore failed: ' . $e->getMessage());
            Response::error('Error restoring backup', 500);
        }
    }

    public function getBackupHistory()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        try {
            $backups = BackupService::getBackupHistory();
            Response::success('Backup history retrieved', $backups);
        } catch (Exception $e) {
            error_log('Backup history retrieval failed: ' . $e->getMessage());
            Response::error('Error retrieving backup history', 500);
        }
    }

    public function downloadBackup()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        if (!isset($_GET['filename'])) {
            Response::error('Filename is required', 400);
        }

        $filename = $this->sanitizeInput($_GET['filename']);

        try {
            if (!BackupService::downloadBackup($filename)) {
                Response::error('Failed to download backup file', 404);
            }
            // Note: downloadBackup will handle the file download and exit
        } catch (Exception $e) {
            error_log('Backup download failed: ' . $e->getMessage());
            Response::error('Error downloading backup', 500);
        }
    }

    public function deleteBackup()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        // Get request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['filename']);

        $filename = $this->sanitizeInput($data['filename']);

        try {
            if (BackupService::deleteBackup($filename)) {
                // Log activity
                Logger::logActivity(
                    $this->user['user_id'],
                    'delete_backup',
                    'Deleted backup file: '.$filename
                );

                Response::success('Backup file deleted successfully');
            } else {
                Response::error('Failed to delete backup file');
            }
        } catch (Exception $e) {
            error_log('Backup delete failed: ' . $e->getMessage());
            Response::error('Error deleting backup', 500);
        }
    }

    private function resolveBranchSettingsId($requested, bool $forWrite): ?int
    {
        $role = (string)($this->user['role'] ?? '');
        $requestedBranch = ($requested !== null && $requested !== '') ? (int)$requested : null;
        if ($requestedBranch !== null && $requestedBranch <= 0) {
            Response::error('branch_id ไม่ถูกต้อง', 400);
        }

        if ($role === 'manager') {
            $branchId = (int)($this->user['branch_id'] ?? 0);
            if (!$branchId) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
            if ($requestedBranch !== null && $requestedBranch !== $branchId) {
                Response::error('ไม่มีสิทธิ์ตั้งค่าสาขาอื่น', 403);
            }
            return $branchId;
        }

        if ($role === 'super_manager') {
            if ($requestedBranch === null) {
                Response::error('กรุณาระบุสาขาสำหรับค่าตั้งค่าปฏิบัติการ', 400);
            }
            $this->assertActiveBranch($requestedBranch);
            return $requestedBranch;
        }

        if ($requestedBranch !== null) {
            $this->assertActiveBranch($requestedBranch);
            return $requestedBranch;
        }

        if ($forWrite && $role !== 'admin') {
            Response::error('ไม่มีสิทธิ์แก้ไขค่าตั้งค่าระบบ', 403);
        }
        return null;
    }

    private function rejectGlobalSettingKeys(array $data, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                Response::error('ค่าตั้งค่านี้เป็นค่าระบบส่วนกลางและแก้ได้เฉพาะ Owner', 403);
            }
        }
    }

    private function assertActiveBranch(int $branchId): void
    {
        if (!$this->db->fetchColumn("SELECT 1 FROM branches WHERE id = ? AND status = 'active'", [$branchId])) {
            Response::error('ไม่พบสาขาที่เปิดใช้งาน', 422);
        }
    }
}
