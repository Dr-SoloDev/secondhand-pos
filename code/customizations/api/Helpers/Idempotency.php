<?php
class Idempotency
{
    private $db;
    private $lockName;

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

    public function acquire($key, $endpoint)
    {
        $key = trim((string)$key);
        if ($key === '' || strlen($key) > 64) {
            throw new Exception('Invalid idempotency key');
        }
        $this->lockName = hash('sha256', $endpoint . ':' . $key);
        $acquired = (int)$this->db->fetchColumn('SELECT GET_LOCK(?, 10)', [$this->lockName]);
        if ($acquired !== 1) {
            throw new Exception('คำขอเดิมกำลังประมวลผล กรุณาลองใหม่');
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

    public function release()
    {
        if ($this->lockName !== null) {
            $this->db->fetchColumn('SELECT RELEASE_LOCK(?)', [$this->lockName]);
            $this->lockName = null;
        }
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
