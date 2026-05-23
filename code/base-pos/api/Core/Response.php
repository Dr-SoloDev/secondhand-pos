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
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

        // Output rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * @param $path
     * @param $filename
     */
    public static function file($path, $filename = null)
    {
        if (!file_exists($path)) {
            self::error('File not found', 404);
        }

        $filename = $filename ?? basename($path);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: '.filesize($path));
        ob_clean();
        flush();
        readfile($path);
        exit;
    }
}
