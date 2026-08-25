<?php
class Response
{
    /**
     * @param $message
     * @param $data
     * @param null $code
     */
    public static function success($message, $data = null, $code = 200)
    {
        http_response_code($code);
        echo self::generateJson('success', $message, $data);
        exit;
    }

    /**
     * @param $message
     * @param $code
     */
    public static function error($message, $code = 400)
    {
        http_response_code($code);
        echo self::generateJson('error', $message);
        exit;
    }

    /**
     * @param $status
     * @param $message
     * @param $data
     */
    private static function generateJson($status, $message, $data = null)
    {
        $response = [
            'status' => $status,
            'message' => TextEncoding::repairMojibake($message)
        ];

        if ($data !== null) {
            $response['data'] = TextEncoding::normalize($data);
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Escape cells that could be interpreted as formulas by Excel/Sheets.
     * Covers =,+, -,@ prefixes including leading whitespace/BOM
     * (e.g. " =cmd", "\u{FEFF}=1+1|cmd") — CVE-style CSV injection guard.
     *
     * @param array $row
     * @return array
     */
    public static function spreadsheetSafeRow(array $row)
    {
        return array_map(static function ($cell) {
            if (!is_string($cell)) {
                return $cell;
            }
            if (preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $cell)) {
                return "'" . $cell;
            }
            return $cell;
        }, $row);
    }

    /**
     * @param $data
     * @param $filename
     */
    public static function csv($data, $filename = 'export.csv')
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $output = fopen('php://output', 'w');

        // Add BOM to fix UTF-8 in Excel
        fputs($output, "\xEF\xBB\xBF");

        // Output rows with CSV injection protection
        foreach ($data as $row) {
            fputcsv($output, self::spreadsheetSafeRow($row));
        }

        fclose($output);
        exit;
    }

    public static function file($path, $filename = null, $allowedDir = null)
    {
        $realPath = realpath($path);
        $realAllowed = realpath($allowedDir ?: BACKUP_DIR);

        if (!$realPath || !$realAllowed || strpos($realPath, $realAllowed) !== 0) {
            self::error('Invalid file path', 403);
        }

        if (!file_exists($realPath)) {
            self::error('File not found', 404);
        }

        $filename = $filename ?? basename($realPath);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: '.filesize($realPath));
        if (ob_get_level()) ob_clean();
        flush();
        readfile($realPath);
        exit;
    }
}
