<?php
/**
 * IntegrityService — append-only hash chain ตรวจแก้ไขย้อนหลัง (tamper-evidence)
 *
 * 1 entity = 1 chain:
 *   chain_hash = HMAC-SHA256(chainKey, prev_hash|payload_hash|action|seq|entity_type|entity_id)
 *   genesis prev_hash = 64 zeros
 *
 * - เก็บเฉพาะ HASH (ไม่เก็บ payload) → ข้อมูลบัตร/ชื่อ ไม่หลุดเข้า audit log
 * - key derive จาก SELLER_ID_ENCRYPTION_KEY + domain "integrity-v1"
 *   (กัน brute-force จากค่า hash ที่публич뀜ได้)
 * - verify = เดิน chain แล้วคำนวณซ้ำ → แถวก่อนหน้าถูกแก้/ลบ = จับได้
 * - fail-open: append ล้มเหลวห้ามทำให้ธุรกิจพัง → error_log แล้วผ่าน
 */
class IntegrityService
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private static $chainKey;

    // ── pure functions (unit-testable, ไม่แตะ DB) ──────────────────────

    /**
     * canonical JSON: key เรียง, float → 2 ตำแหน่ง (money), UTF-8 ไม่ escape
     */
    public static function canonicalJson($value): string
    {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value);
            }
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[$k] = self::canonicalJson($v);
            }
            $isList = array_keys($value) === range(0, count($value) - 1);
            return $isList
                ? '[' . implode(',', $normalized) . ']'
                : '{' . implode(',', array_map(
                    static fn($k, $v) => json_encode((string)$k) . ':' . $v,
                    array_keys($normalized),
                    $normalized
                )) . '}';
        }
        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        return json_encode((string)$value, JSON_UNESCAPED_UNICODE);
    }

    public static function payloadHash(array $payload): string
    {
        return hash('sha256', self::canonicalJson($payload));
    }

    /**
     * คำนวณ chain_hash บรรทัดเดียว (pure)
     */
    public static function computeChainHash(
        string $prevHash,
        string $payloadHash,
        string $action,
        int $seq,
        string $entityType,
        int $entityId
    ): string {
        $message = $prevHash . '|' . $payloadHash . '|' . $action . '|' . $seq
            . '|' . $entityType . '|' . $entityId;
        return hash_hmac('sha256', $message, self::chainKey());
    }

    // ── DB operations ──────────────────────────────────────────────────

    /**
     * ต่อ chain 1 รายการ
     * @return int|false seq ที่บันทึก, false = ล้มเหลว (fail-open — log แล้วผ่าน)
     */
    public static function append(
        string $entityType,
        int $entityId,
        string $action,
        array $payload,
        ?string $reason = null,
        ?string $actor = null
    ) {
        try {
            $pdo = Database::getInstance()->getConnection();

            // SELECT ... FOR UPDATE กัน race ตอนเพิ่ม seq พร้อมกัน 2 เธรด
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'SELECT seq, chain_hash FROM record_integrity
                     WHERE entity_type = ? AND entity_id = ?
                     ORDER BY seq DESC LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$entityType, $entityId]);
                $last = $stmt->fetch(PDO::FETCH_ASSOC);

                $seq = $last ? ((int)$last['seq']) + 1 : 1;
                $prevHash = $last ? $last['chain_hash'] : self::GENESIS;
                $payloadHash = self::payloadHash($payload);
                $chainHash = self::computeChainHash(
                    $prevHash, $payloadHash, $action, $seq, $entityType, $entityId
                );

                $ins = $pdo->prepare(
                    'INSERT INTO record_integrity
                     (entity_type, entity_id, seq, action, reason, payload_hash, prev_hash, chain_hash, actor)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $entityType, $entityId, $seq, $action, $reason,
                    $payloadHash, $prevHash, $chainHash, $actor,
                ]);
                $pdo->commit();
                return $seq;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            error_log("IntegrityService::append failed ({$entityType}/{$entityId}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * เดินทั้ง chain คำนวณซ้ำ — จับการแก้/ลบแถวย้อนหลังได้
     * @return array{valid:bool, entries:int, broken_at:int|null, reason:string|null}
     */
    public static function verify(string $entityType, int $entityId): array
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare(
                'SELECT seq, action, payload_hash, prev_hash, chain_hash
                 FROM record_integrity
                 WHERE entity_type = ? AND entity_id = ?
                 ORDER BY seq ASC'
            );
            $stmt->execute([$entityType, $entityId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("IntegrityService::verify failed: " . $e->getMessage());
            return ['valid' => false, 'entries' => 0, 'broken_at' => null, 'reason' => 'db_error'];
        }

        if (!$rows) {
            return ['valid' => true, 'entries' => 0, 'broken_at' => null, 'reason' => 'empty'];
        }

        $expectedPrev = self::GENESIS;
        foreach ($rows as $i => $row) {
            $seq = (int)$row['seq'];
            if ($seq !== $i + 1) {
                return ['valid' => false, 'entries' => count($rows), 'broken_at' => $seq, 'reason' => 'seq_gap'];
            }
            if ($row['prev_hash'] !== $expectedPrev) {
                return ['valid' => false, 'entries' => count($rows), 'broken_at' => $seq, 'reason' => 'prev_hash_mismatch'];
            }
            $expected = self::computeChainHash(
                $row['prev_hash'], $row['payload_hash'], $row['action'],
                $seq, $entityType, $entityId
            );
            if (!hash_equals($expected, $row['chain_hash'])) {
                return ['valid' => false, 'entries' => count($rows), 'broken_at' => $seq, 'reason' => 'chain_hash_mismatch'];
            }
            $expectedPrev = $row['chain_hash'];
        }
        return ['valid' => true, 'entries' => count($rows), 'broken_at' => null, 'reason' => null];
    }

    /**
     * key derive — domain-separated จาก encryption key หลัก
     */
    private static function chainKey()
    {
        if (self::$chainKey !== null) {
            return self::$chainKey;
        }
        // ใช้ key material เดียวกับ SellerIdCipher (env เดียวกัน, dev fallback เหมือนกัน)
        $material = trim((string)getenv('SELLER_ID_ENCRYPTION_KEY'));
        $appEnvironment = strtolower((string)(getenv('APP_ENV') ?: 'development'));
        if ($material === '') {
            if ($appEnvironment === 'production') {
                throw new RuntimeException('SELLER_ID_ENCRYPTION_KEY is required in production');
            }
            $material = (string)getenv('JWT_SECRET');
        }
        if ($material === '') {
            throw new RuntimeException('No key material for integrity chain');
        }
        // domain-separated: HMAC เฉพาะ integrity chain (ไม่เท่ากับ key ตัวอื่น)
        self::$chainKey = hash_hmac('sha256', 'integrity-v1', hash('sha256', "seller-id-encryption-v1\0" . $material, true), true);
        return self::$chainKey;
    }

    public static function resetForTests()
    {
        self::$chainKey = null;
    }
}
