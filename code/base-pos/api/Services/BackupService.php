<?php
class BackupService
{
    public static function createBackup()
    {
        // Create backup directory if it doesn't exist
        if (!file_exists(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }

        // Generate backup filename
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_{$timestamp}.sql";
        $filePath = BACKUP_DIR.'/'.$filename;

        // Build mysqldump command
        $command = sprintf(
            "mysqldump --host=%s --user=%s --password=%s %s > %s 2>&1",
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME,
            $filePath
        );

        // Execute command
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            return [
                'success' => false,
                'message' => implode("\n", $output)
            ];
        }

        // Log backup in database
        $db = Database::getInstance();
        $fileSize = filesize($filePath);

        $stmt = $db->prepare(
            "INSERT INTO backup_history (filename, file_size, created_by) VALUES (?, ?, ?)"
        );

        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;
        $db->execute($stmt, [$filename, $fileSize, $userId]);

        return [
            'success' => true,
            'filename' => $filename
        ];
    }

    /**
     * @param $uploadedFile
     */
    public static function restoreBackup($uploadedFile)
    {
        // Check for upload errors
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Upload failed with error code: '.$uploadedFile['error']
            ];
        }

        // Create temp directory if it doesn't exist
        if (!file_exists(TEMP_DIR)) {
            mkdir(TEMP_DIR, 0755, true);
        }

        $tempFile = TEMP_DIR.'/'.basename($uploadedFile['name']);

        // Move uploaded file to temp directory
        if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFile)) {
            return [
                'success' => false,
                'message' => 'Failed to move uploaded file'
            ];
        }

        // Extract zip file if necessary
        $filePath = $tempFile;
        if (pathinfo($tempFile, PATHINFO_EXTENSION) === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($tempFile) === true) {
                $extractPath = TEMP_DIR.'/extract_'.time();
                mkdir($extractPath, 0755, true);

                $zip->extractTo($extractPath);
                $zip->close();

                // Find SQL file in extracted files
                $sqlFiles = glob($extractPath.'/*.sql');
                if (empty($sqlFiles)) {
                    return [
                        'success' => false,
                        'message' => 'No SQL files found in the ZIP archive'
                    ];
                }

                $filePath = $sqlFiles[0]; // Use the first SQL file found
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to open ZIP archive'
                ];
            }
        }

        // Build mysql command
        $command = sprintf(
            "mysql --host=%s --user=%s --password=%s %s < %s 2>&1",
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME,
            $filePath
        );

        // Execute command
        exec($command, $output, $returnVar);

        // Clean up temp files
        if (pathinfo($tempFile, PATHINFO_EXTENSION) === 'zip') {
            array_map('unlink', glob($extractPath.'/*'));
            rmdir($extractPath);
        }
        unlink($tempFile);

        if ($returnVar !== 0) {
            return [
                'success' => false,
                'message' => implode("\n", $output)
            ];
        }

        return [
            'success' => true
        ];
    }

    /**
     * @return mixed
     */
    public static function getBackupHistory()
    {
        $db = Database::getInstance();

        return $db->fetchAll(
            "SELECT h.filename, h.file_size as size, h.created_at, u.username as created_by
             FROM backup_history h
             LEFT JOIN users u ON h.created_by = u.id
             ORDER BY h.created_at DESC"
        );
    }

    /**
     * @param $filename
     */
    public static function downloadBackup($filename)
    {
        $filePath = BACKUP_DIR.'/'.$filename;

        // Validate filename to prevent directory traversal
        if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return false;
        }

        if (!file_exists($filePath)) {
            return false;
        }

        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: '.filesize($filePath));

        // Clear output buffer
        ob_clean();
        flush();

        // Read file and output to browser
        readfile($filePath);
        return true;
    }

    /**
     * @param $filename
     */
    public static function deleteBackup($filename)
    {
        $db = Database::getInstance();
        $filePath = BACKUP_DIR.'/'.$filename;

        // Validate filename to prevent directory traversal
        if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return false;
        }

        if (!file_exists($filePath)) {
            return false;
        }

        // Delete file from disk
        if (!unlink($filePath)) {
            return false;
        }

        // Delete record from database
        $db->delete('backup_history', ['filename = ?'], [$filename]);

        return true;
    }
}
