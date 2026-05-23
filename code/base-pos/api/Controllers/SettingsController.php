<?php
class SettingsController extends Controller
{
    public function getStoreSettings()
    {
        $settingModel = new Setting();
        $settings = $settingModel->getSettingsByKeys([
            'store_name',
            'store_phone',
            'store_address',
            'tax_rate',
            'currency_symbol',
            'receipt_footer'
        ]);

        Response::success('Store settings retrieved', $settings);
    }

    public function saveStoreSettings()
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        // Get request data
        $data = $this->getRequestData();

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Update settings
        $settingModel = new Setting();
        $settingsToUpdate = [
            'store_name' => $data['store_name'] ?? null,
            'store_phone' => $data['store_phone'] ?? null,
            'store_address' => $data['store_address'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? null,
            'currency_symbol' => $data['currency_symbol'] ?? null,
            'receipt_footer' => $data['receipt_footer'] ?? null
        ];

        try {
            $settingModel->updateSettings($settingsToUpdate);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_store_settings',
                'Updated store settings'
            );

            Response::success('Store settings updated successfully');
        } catch (Exception $e) {
            Response::error('Failed to update store settings: '.$e->getMessage());
        }
    }

    public function getSystemSettings()
    {
        $settingModel = new Setting();
        $settings = $settingModel->getSettingsByKeys([
            'low_stock_threshold',
            'date_format',
            'time_zone',
            'language'
        ]);

        Response::success('System settings retrieved', $settings);
    }

    public function saveSystemSettings()
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        // Get request data
        $data = $this->getRequestData();

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Update settings
        $settingModel = new Setting();
        $settingsToUpdate = [
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
            'date_format' => $data['date_format'] ?? null,
            'time_zone' => $data['time_zone'] ?? null,
            'language' => $data['language'] ?? null
        ];

        try {
            $settingModel->updateSettings($settingsToUpdate);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_system_settings',
                'Updated system settings'
            );

            Response::success('System settings updated successfully');
        } catch (Exception $e) {
            Response::error('Failed to update system settings: '.$e->getMessage());
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
            Response::error('Error creating backup: '.$e->getMessage());
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
            Response::error('Error restoring backup: '.$e->getMessage());
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
            Response::error('Error retrieving backup history: '.$e->getMessage());
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
            Response::error('Error downloading backup: '.$e->getMessage());
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
            Response::error('Error deleting backup: '.$e->getMessage());
        }
    }
}
