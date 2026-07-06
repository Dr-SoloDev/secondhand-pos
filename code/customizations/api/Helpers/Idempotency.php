<?php
class Idempotency
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function check($key, $endpoint)
    {
        $existing = $this->db->fetch(
            "SELECT response_json FROM idempotency_keys
             WHERE idempotency_key = ? AND endpoint = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$key, $endpoint]
        );
        if ($existing) {
            $data = json_decode($existing['response_json'], true);
            Response::success('Duplicate request (idempotent)', $data, 409);
            exit;
        }
    }

    public function save($key, $endpoint, $responseData)
    {
        $this->db->insert('idempotency_keys', [
            'idempotency_key' => $key,
            'endpoint' => $endpoint,
            'response_json' => json_encode($responseData),
        ]);
    }

    public function cleanup()
    {
        // ลบ keys ที่อายุเกิน 24 ชม. (เรียกเป็นครั้งคราว หรือ cron)
        $this->db->execute(
            $this->db->prepare(
                "DELETE FROM idempotency_keys WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            ),
            []
        );
    }
}
