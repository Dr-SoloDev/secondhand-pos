<?php
class BackupService
{
    public static function createBackup()
    {
        if (!file_exists(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0750, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_{$timestamp}.sql";
        $filePath = BACKUP_DIR.'/'.$filename;

        $cnfFile = tempnam(sys_get_temp_dir(), 'mycnf_');
        $cnfContent = "[client]\nhost=" . DB_HOST . "\nuser=" . DB_USER . "\npassword=" . DB_PASS . "\n";
        file_put_contents($cnfFile, $cnfContent);
        chmod($cnfFile, 0600);

        $dbName = escapeshellarg(DB_NAME);
        $fileArg = escapeshellarg($filePath);
        $cnfArg = escapeshellarg($cnfFile);

        $command = "timeout 300 mysqldump --defaults-extra-file={$cnfArg} {$dbName} > {$fileArg} 2>&1";

        exec($command, $output, $returnVar);
        unlink($cnfFile);

        if ($returnVar !== 0) {
            return [
                'success' => false,
                'message' => 'Backup failed'
            ];
        }

        $db = Database::getInstance();
        $fileSize = filesize($filePath);

        $stmt = $db->prepare(
            "INSERT INTO backup_history (filename, file_size, created_by) VALUES (?, ?, ?)"
        );

        $userId = $db->fetchColumn(
            "SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
        ) ?: 1;
        $db->execute($stmt, [$filename, $fileSize, $userId]);

        return [
            'success' => true,
            'filename' => $filename
        ];
    }

    public static function restoreBackup($uploadedFile)
    {
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Upload failed'
            ];
        }

        if (!file_exists(TEMP_DIR)) {
            mkdir(TEMP_DIR, 0750, true);
        }

        $tempFile = TEMP_DIR . '/' . basename($uploadedFile['name']);

        if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFile)) {
            return [
                'success' => false,
                'message' => 'Failed to move uploaded file'
            ];
        }

        $filePath = $tempFile;
        if (pathinfo($tempFile, PATHINFO_EXTENSION) === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($tempFile) === true) {
                if ($zip->numFiles > 100) {
                    $zip->close();
                    unlink($tempFile);
                    return ['success' => false, 'message' => 'Archive too many files'];
                }

                $totalSize = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $totalSize += $stat['size'];
                    if ($totalSize > 500 * 1024 * 1024) {
                        $zip->close();
                        unlink($tempFile);
                        return ['success' => false, 'message' => 'Archive too large'];
                    }
                }

                $extractPath = TEMP_DIR . '/extract_' . time();
                mkdir($extractPath, 0750, true);

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    // SECURITY: reject path traversal (..), absolute paths (/ prefix),
                    // subdirectory entries (contains /), and hidden files (starts with .)
                    if (strpos($name, '..') !== false
                        || strpos($name, '/') !== false
                        || $name[0] === '.'
                    ) {
                        $zip->close();
                        array_map('unlink', glob($extractPath . '/*'));
                        rmdir($extractPath);
                        unlink($tempFile);
                        return ['success' => false, 'message' => 'Invalid archive entry'];
                    }
                }

                $zip->extractTo($extractPath);
                $zip->close();

                $sqlFiles = glob($extractPath . '/*.sql');
                if (empty($sqlFiles)) {
                    array_map('unlink', glob($extractPath . '/*'));
                    rmdir($extractPath);
                    unlink($tempFile);
                    return ['success' => false, 'message' => 'No SQL files found in archive'];
                }
                // SECURITY: verify extracted SQL file is within expected directory
                $filePath = $sqlFiles[0];
                $realPath = realpath($filePath);
                $realExtract = realpath($extractPath);
                if (!$realPath || !$realExtract || strpos($realPath, $realExtract) !== 0) {
                    array_map('unlink', glob($extractPath . '/*'));
                    rmdir($extractPath);
                    unlink($tempFile);
                    return ['success' => false, 'message' => 'Invalid file path in archive'];
                }
            } else {
                unlink($tempFile);
                return ['success' => false, 'message' => 'Failed to open archive'];
            }
        }

        $sql = file_get_contents($filePath);
        if ($sql === false || trim($sql) === '') {
            unlink($tempFile);
            return ['success' => false, 'message' => 'Invalid SQL file'];
        }

        try {
            $db = Database::getInstance();
            $statements = explode(';', $sql);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $db->getConnection()->exec($statement);
                }
            }
        } catch (Exception $e) {
            error_log('Backup restore failed: ' . $e->getMessage());
            unlink($tempFile);
            return ['success' => false, 'message' => 'Restore failed']; // details logged server-side
        }

        if (pathinfo($tempFile, PATHINFO_EXTENSION) === 'zip' && isset($extractPath) && file_exists($extractPath)) {
            array_map('unlink', glob($extractPath . '/*'));
            rmdir($extractPath);
        }
        unlink($tempFile);

        return ['success' => true];
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
        $safeName = basename($filename);
        $filePath = BACKUP_DIR.'/'.$safeName;

        if ($safeName !== $filename || !file_exists($filePath)) {
            return false;
        }

        $realPath = realpath($filePath);
        $realBackup = realpath(BACKUP_DIR);
        if (!$realPath || !$realBackup || strpos($realPath, $realBackup) !== 0) {
            return false;
        }

        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$safeName.'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: '.filesize($realPath));

        // Clear output buffer
        if (ob_get_level()) ob_clean();
        flush();

        // Read file and output to browser
        readfile($realPath);
        return true;
    }

    /**
     * @param $filename
     */
    public static function deleteBackup($filename)
    {
        $db = Database::getInstance();
        $safeName = basename($filename);

        if ($safeName !== $filename) {
            return false;
        }

        $filePath = BACKUP_DIR.'/'.$safeName;
        $realPath = realpath($filePath);
        $realBackup = realpath(BACKUP_DIR);
        if (!$realPath || !$realBackup || strpos($realPath, $realBackup) !== 0) {
            return false;
        }

        if (!file_exists($realPath)) {
            return false;
        }

        // Delete file from disk
        if (!unlink($realPath)) {
            return false;
        }

        // Delete record from database
        $db->delete('backup_history', ['filename = ?'], [$safeName]);

        return true;
    }
}
