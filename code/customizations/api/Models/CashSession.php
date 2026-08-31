<?php
class CashSession extends Model
{
    protected $table = 'cash_sessions';
    private const VARIANCE_APPROVAL_THRESHOLD = 100.0;

    public function getAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['branch_id'])) {
            $where[] = 'cs.branch_id = ?';
            $params[] = (int)$filters['branch_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'cs.business_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'cs.business_date <= ?';
            $params[] = $filters['date_to'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $this->db->fetchAll(
            "SELECT cs.*, b.name AS branch_name,
                    opener.full_name AS opening_requested_by_name,
                    closer.full_name AS closing_requested_by_name,
                    reviewer.full_name AS last_reviewed_by_name
             FROM cash_sessions cs
             JOIN branches b ON b.id = cs.branch_id
             LEFT JOIN users opener ON opener.id = cs.opening_requested_by
             LEFT JOIN users closer ON closer.id = cs.closing_requested_by
             LEFT JOIN users reviewer ON reviewer.id = cs.last_reviewed_by
             $whereSql ORDER BY cs.business_date DESC, cs.branch_id ASC LIMIT 200",
            $params
        ) ?: [];
        foreach ($rows as &$row) {
            $row['ledger_total'] = $this->movementTotal((int)$row['id']);
            $row['current_expected_cash'] = round((float)$row['opening_actual'] + $row['ledger_total'], 2);
            $row['drawer_balance'] = $row['current_expected_cash'];
        }
        unset($row);
        return $rows;
    }

    public function getCurrent(int $branchId): ?array
    {
        $row = $this->db->fetch(
            "SELECT cs.*, b.name AS branch_name
             FROM cash_sessions cs JOIN branches b ON b.id = cs.branch_id
             WHERE cs.branch_id = ? AND cs.business_date = CURDATE()",
            [$branchId]
        );
        if (!$row) {
            $row = $this->db->fetch(
                "SELECT cs.*, b.name AS branch_name
                 FROM cash_sessions cs JOIN branches b ON b.id = cs.branch_id
                 WHERE cs.branch_id = ?
                   AND cs.status IN ('pending_open','open','pending_close')
                 ORDER BY cs.business_date DESC LIMIT 1",
                [$branchId]
            );
            if ($row) {
                $row['ledger_total'] = $this->movementTotal((int)$row['id']);
                $row['current_expected_cash'] = round((float)$row['opening_actual'] + $row['ledger_total'], 2);
                $row['movements'] = $this->getMovements((int)$row['id']);
                $row = $this->applyPositionFields($row);
                $row['is_stale'] = true;
                return $row;
            }

            $branchName = $this->db->fetchColumn(
                "SELECT name FROM branches WHERE id = ?",
                [$branchId]
            );
            $result = [
                'id' => null,
                'branch_id' => $branchId,
                'branch_name' => $branchName ?: null,
                'business_date' => date('Y-m-d'),
                'status' => null,
                'opening_expected' => 0,
                'opening_actual' => null,
                'opening_variance' => null,
                'ledger_total' => 0,
                'current_expected_cash' => 0,
                'movements' => [],
            ];
            return $this->applyPositionFields($result);
        }
        $row['ledger_total'] = $this->movementTotal((int)$row['id']);
        $row['current_expected_cash'] = round((float)$row['opening_actual'] + $row['ledger_total'], 2);
        $row['movements'] = $this->getMovements((int)$row['id']);
        return $this->applyPositionFields($row);
    }

    public function hasPositionModel(int $branchId): bool
    {
        return (bool)$this->db->fetchColumn(
            "SELECT id FROM cash_position_baselines WHERE branch_id=? LIMIT 1",
            [$branchId]
        );
    }

    public function getPositionBalances(int $branchId): array
    {
        $baseline = $this->db->fetch(
            "SELECT * FROM cash_position_baselines WHERE branch_id=? LIMIT 1",
            [$branchId]
        );
        if (!$baseline) {
            return [
                'model_version' => 1,
                'drawer_balance' => 0.0,
                'reserve_balance' => 0.0,
                'business_total_cash' => 0.0,
            ];
        }

        $drawer = (float)$baseline['drawer_balance'];
        $reserve = (float)$baseline['reserve_balance'];
        $bankNet = 0.0;
        $movements = $this->db->fetchAll(
            "SELECT source_location, destination_location, balance_effect, amount, movement_type
             FROM cash_movements
             WHERE branch_id=? AND balance_effect IS NOT NULL AND created_at >= ?
             ORDER BY id ASC",
            [$branchId, $baseline['created_at']]
        ) ?: [];
        foreach ($movements as $movement) {
            $amount = (float)$movement['amount'];
            $source = $movement['source_location'] ?? null;
            $destination = $movement['destination_location'] ?? null;
            if (str_starts_with((string)$movement['movement_type'], 'bank_')) {
                $bankNet += $movement['balance_effect'] === 'increase' ? $amount : -$amount;
                continue;
            }
            if ($source === 'drawer') $drawer -= $amount;
            if ($source === 'business_reserve') $reserve -= $amount;
            if ($destination === 'drawer') $drawer += $amount;
            if ($destination === 'business_reserve') $reserve += $amount;
        }

        return [
            'model_version' => 2,
            'drawer_balance' => round($drawer, 2),
            'reserve_balance' => round($reserve, 2),
            'bank_balance' => round($bankNet, 2),
            'business_total_cash' => round($drawer + $reserve + $bankNet, 2),
            'baseline_id' => (int)$baseline['id'],
            'baseline_effective_date' => $baseline['effective_date'],
        ];
    }

    public function initializePositionBaseline(int $branchId, float $drawerBalance, float $reserveBalance,
        string $effectiveDate, string $note, int $userId): array
    {
        if (!is_finite($drawerBalance) || $drawerBalance < 0
            || !is_finite($reserveBalance) || $reserveBalance < 0) {
            throw new Exception('ยอดตั้งต้นของตำแหน่งเงินไม่ถูกต้อง');
        }
        $note = $this->normalizeReason($note);
        if ($note === null) throw new Exception('กรุณาระบุเหตุผล/หลักฐานของยอดตั้งต้น');
        $this->db->beginTransaction();
        try {
            if ($this->db->fetchColumn(
                "SELECT id FROM cash_position_baselines WHERE branch_id=? FOR UPDATE",
                [$branchId]
            )) {
                throw new Exception('สาขานี้ตั้งต้น cash position ไปแล้ว');
            }
            $active = $this->db->fetchColumn(
                "SELECT id FROM cash_sessions WHERE branch_id=? AND status IN ('pending_open','open','pending_close') LIMIT 1 FOR UPDATE",
                [$branchId]
            );
            if ($active) throw new Exception('ต้องปิดหรือเคลียร์รอบเงินสดที่ค้างอยู่ก่อนตั้งต้นระบบใหม่');
            $stmt = $this->db->prepare(
                "INSERT INTO cash_position_baselines
                   (branch_id,effective_date,drawer_balance,reserve_balance,note,created_by)
                 VALUES (?,?,?,?,?,?)"
            );
            $this->db->execute($stmt, [
                $branchId, $effectiveDate, round($drawerBalance, 2), round($reserveBalance, 2), $note, $userId,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->db->commit();
            return [
                'id' => $id,
                'branch_id' => $branchId,
                'effective_date' => $effectiveDate,
                'drawer_balance' => round($drawerBalance, 2),
                'reserve_balance' => round($reserveBalance, 2),
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Simple daily drawer open: insert amount = drawer balance. No carry-forward.
     * If session already open/pending from same day, reset it to fresh open with new amount.
     */
    public function openDay(int $branchId, float $actualCash, ?string $reason, int $userId): array
    {
        if (!is_finite($actualCash) || $actualCash < 0) {
            throw new Exception('ยอดเงินสดเปิดวันไม่ถูกต้อง');
        }
        if ($this->hasPositionModel($branchId)) {
            return $this->openPositionDay($branchId, $actualCash, $reason, $userId);
        }
        $reason = $this->normalizeReason($reason);

        $this->db->beginTransaction();
        try {
            $actualCash = round($actualCash, 2);

            $existing = $this->db->fetch(
                "SELECT id, status FROM cash_sessions
                 WHERE branch_id = ? AND business_date = CURDATE() FOR UPDATE",
                [$branchId]
            );

            if ($existing && in_array($existing['status'] ?? '', ['pending_open','open','pending_close'], true)) {
                // Session is active — reset to fresh open with new amount, clear old movements
                $sessionId = (int)$existing['id'];
                $this->db->query(
                    "UPDATE cash_sessions
                     SET status='open', opening_expected=0, opening_actual=?, opening_reason=?,
                         opening_requested_by=?, opened_by=?, opened_at=NOW(),
                         closing_expected=NULL, closing_actual=NULL, closing_reason=NULL,
                         closing_requested_by=NULL, closed_by=NULL, closed_at=NULL,
                         last_reviewed_by=NULL, last_reviewed_at=NULL, last_review_note=NULL
                     WHERE id=?",
                    [$actualCash, $reason, $userId, $userId, $sessionId]
                );
                $this->db->query(
                    "DELETE FROM cash_movements WHERE cash_session_id=?",
                    [$sessionId]
                );
            } elseif ($existing) {
                // Closed or rejected — reuse and reset to open
                $sessionId = (int)$existing['id'];
                $this->db->query(
                    "UPDATE cash_sessions
                     SET status='open', opening_expected=0, opening_actual=?, opening_reason=?,
                         opening_requested_by=?, opened_by=?, opened_at=NOW(),
                         closing_expected=NULL, closing_actual=NULL, closing_reason=NULL,
                         closing_requested_by=NULL, closed_by=NULL, closed_at=NULL,
                         last_reviewed_by=NULL, last_reviewed_at=NULL, last_review_note=NULL
                     WHERE id=?",
                    [$actualCash, $reason, $userId, $userId, $sessionId]
                );
                $this->db->query(
                    "DELETE FROM cash_movements WHERE cash_session_id=?",
                    [$sessionId]
                );
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO cash_sessions
                       (branch_id,business_date,status,opening_expected,opening_actual,opening_reason,
                        opening_requested_by,opened_by,opened_at)
                     VALUES (?,CURDATE(),'open',?,?,?,?,?,?)"
                );
                $this->db->execute($stmt, [
                    $branchId, 0, $actualCash, $reason, $userId, $userId, date('Y-m-d H:i:s'),
                ]);
                $sessionId = (int)$this->db->lastInsertId();
            }
            $this->addEvent($sessionId, 'opened', 0, $actualCash, 0, $reason, $userId);
            $this->db->commit();
            return ['id' => $sessionId, 'status' => 'open', 'expected_cash' => $actualCash, 'variance' => 0];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function approveOpen(int $sessionId, int $approverId, ?string $reviewNote = null, bool $allowSelfApproval = false): void
    {
        $this->reviewOpen($sessionId, $approverId, true, $reviewNote, $allowSelfApproval);
    }

    public function rejectOpen(int $sessionId, int $reviewerId, string $reviewNote): void
    {
        $this->reviewOpen($sessionId, $reviewerId, false, $reviewNote);
    }

    private function reviewOpen(int $sessionId, int $reviewerId, bool $approve, ?string $reviewNote, bool $allowSelfApproval = false): void
    {
        $reviewNote = $this->normalizeReason($reviewNote);
        if (!$approve && $reviewNote === null) {
            throw new Exception('กรุณาระบุเหตุผลที่ปฏิเสธ');
        }
        $this->db->beginTransaction();
        try {
            $session = $this->db->fetch("SELECT * FROM cash_sessions WHERE id=? FOR UPDATE", [$sessionId]);
            if (!$session || $session['status'] !== 'pending_open') {
                throw new Exception('ไม่พบคำขอเปิดยอดที่รอพิจารณา');
            }
            if (!$allowSelfApproval && (int)$session['opening_requested_by'] === $reviewerId) {
                throw new Exception('ผู้ขอเปิดยอดไม่สามารถอนุมัติรายการตัวเองได้');
            }
            $newStatus = $approve ? 'open' : 'rejected';
            $this->db->query(
                "UPDATE cash_sessions SET status=?, opened_by=?, opened_at=?,
                        last_reviewed_by=?, last_reviewed_at=NOW(), last_review_note=? WHERE id=?",
                [$newStatus, $approve ? $reviewerId : null, $approve ? date('Y-m-d H:i:s') : null,
                 $reviewerId, $reviewNote, $sessionId]
            );
            $this->addEvent($sessionId, $approve ? 'open_approved' : 'open_rejected',
                (float)$session['opening_expected'], (float)$session['opening_actual'], (float)$session['opening_variance'],
                $reviewNote, (int)$session['opening_requested_by'], $reviewerId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Simple daily drawer close: record variance, no auto-transfer.
     */
    public function closeDay(int $branchId, float $actualCash, ?string $reason, int $userId): array
    {
        if (!is_finite($actualCash) || $actualCash < 0) {
            throw new Exception('ยอดเงินสดปิดวันไม่ถูกต้อง');
        }
        if ($this->hasPositionModel($branchId)) {
            return $this->closePositionDay($branchId, $actualCash, $reason, $userId);
        }
        $reason = $this->normalizeReason($reason);
        $this->db->beginTransaction();
        try {
            $session = $this->db->fetch(
                "SELECT * FROM cash_sessions
                 WHERE branch_id=? AND status='open'
                 ORDER BY business_date DESC LIMIT 1 FOR UPDATE",
                [$branchId]
            );
            if (!$session || $session['status'] !== 'open') {
                throw new Exception('สาขานี้ยังไม่มีรอบประจำวันที่เปิดอยู่');
            }
            $expected = round((float)$session['opening_actual'] + $this->movementTotal((int)$session['id']), 2);
            $actualCash = round($actualCash, 2);
            $variance = round($actualCash - $expected, 2);
            if (abs($variance) > 0.009 && $reason === null) {
                throw new Exception('ยอดเงินจริงไม่ตรงยอดในระบบ กรุณาระบุเหตุผล');
            }
            $requiresApproval = abs($variance) > self::VARIANCE_APPROVAL_THRESHOLD;
            $status = $requiresApproval ? 'pending_close' : 'closed';
            $this->db->query(
                "UPDATE cash_sessions
                 SET status=?, closing_expected=?, closing_actual=?, closing_reason=?, closing_requested_by=?,
                     closed_by=?, closed_at=? WHERE id=?",
                [$status, $expected, $actualCash, $reason, $userId,
                 $requiresApproval ? null : $userId,
                 $requiresApproval ? null : date('Y-m-d H:i:s'), (int)$session['id']]
            );
            $this->addEvent((int)$session['id'], $requiresApproval ? 'close_requested' : 'closed',
                $expected, $actualCash, $variance, $reason, $userId);
            $this->db->commit();
            return ['id' => (int)$session['id'], 'status' => $status, 'expected_cash' => $expected, 'variance' => $variance];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reviewClose(int $sessionId, int $reviewerId, bool $approve, ?string $reviewNote, bool $allowSelfApproval = false): void
    {
        $reviewNote = $this->normalizeReason($reviewNote);
        if (!$approve && $reviewNote === null) {
            throw new Exception('กรุณาระบุเหตุผลที่ปฏิเสธ');
        }
        $this->db->beginTransaction();
        try {
            $session = $this->db->fetch("SELECT * FROM cash_sessions WHERE id=? FOR UPDATE", [$sessionId]);
            if (!$session || $session['status'] !== 'pending_close') {
                throw new Exception('ไม่พบคำขอปิดยอดที่รอพิจารณา');
            }
            if (!$allowSelfApproval && (int)$session['closing_requested_by'] === $reviewerId) {
                throw new Exception('ผู้ขอปิดยอดไม่สามารถอนุมัติรายการตัวเองได้');
            }
            if ($approve) {
                $this->db->query(
                    "UPDATE cash_sessions SET status='closed', closed_by=?, closed_at=?,
                            last_reviewed_by=?, last_reviewed_at=NOW(), last_review_note=? WHERE id=?",
                    [$reviewerId, date('Y-m-d H:i:s'), $reviewerId, $reviewNote, $sessionId]
                );
            } else {
                $this->db->query(
                    "UPDATE cash_sessions SET status='open',
                        closing_expected=NULL, closing_actual=NULL, closing_reason=NULL,
                        closing_requested_by=NULL, closing_transfer_amount=0,
                        closed_by=NULL, closed_at=NULL,
                        last_reviewed_by=?, last_reviewed_at=NOW(), last_review_note=? WHERE id=?",
                    [$reviewerId, $reviewNote, $sessionId]
                );
            }
            $this->addEvent($sessionId, $approve ? 'close_approved' : 'close_rejected',
                (float)$session['closing_expected'], (float)$session['closing_actual'], (float)$session['closing_variance'],
                $reviewNote, (int)$session['closing_requested_by'], $reviewerId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reopenSameDay(int $sessionId, string $reason, int $adminId): void
    {
        $reason = $this->normalizeReason($reason);
        if ($reason === null) {
            throw new Exception('กรุณาระบุเหตุผลที่เปิดยอดใหม่');
        }
        $this->db->beginTransaction();
        try {
            $session = $this->db->fetch("SELECT * FROM cash_sessions WHERE id=? FOR UPDATE", [$sessionId]);
            if (!$session || $session['status'] !== 'closed') {
                throw new Exception('เปิดใหม่ได้เฉพาะรอบที่ปิดแล้ว');
            }
            if ($session['business_date'] !== date('Y-m-d')) {
                throw new Exception('เปิดยอดใหม่ได้เฉพาะวันเดียวกัน หลังเปลี่ยนวันให้ใช้เอกสารปรับปรุง');
            }
            $this->db->query(
                "UPDATE cash_sessions
                 SET status='open', closing_expected=NULL, closing_actual=NULL, closing_reason=NULL,
                     closing_requested_by=NULL, closed_by=NULL, closed_at=NULL,
                     last_reviewed_by=?, last_reviewed_at=NOW(), last_review_note=?
                 WHERE id=?",
                [$adminId, $reason, $sessionId]
            );
            $this->addEvent($sessionId, 'reopened', (float)$session['closing_expected'],
                (float)$session['closing_actual'], (float)$session['closing_variance'], $reason, $adminId, $adminId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function assertOpen(int $branchId): array
    {
        $session = $this->db->fetch(
            "SELECT * FROM cash_sessions
             WHERE branch_id=? AND business_date=CURDATE() FOR UPDATE",
            [$branchId]
        );
        if (!$session || $session['status'] !== 'open') {
            throw new Exception('สาขานี้ยังไม่ได้เปิดยอดประจำวัน หรือกำลังรออนุมัติ');
        }
        return $session;
    }

    public function assertDrawerSufficient(int $branchId, float $amount): void
    {
        if (!$this->hasPositionModel($branchId)) return;
        $session = $this->assertOpen($branchId);
        $drawerBalance = round((float)$session['opening_actual'] + $this->movementTotal((int)$session['id']), 2);
        if ($drawerBalance + 0.0001 < round($amount, 2)) {
            throw new Exception('เงินสดในลิ้นชักไม่เพียงพอ กรุณาเติมเงินเข้าลิ้นชักก่อน (ยอดลิ้นชัก ' .
                number_format($drawerBalance, 2) . ' บาท)');
        }
    }

    public function recordMovement(int $branchId, string $direction, string $type, float $amount,
        string $referenceType, int $referenceId, string $description, int $userId): int
    {
        if (!in_array($direction, ['in', 'out'], true) || !is_finite($amount) || $amount <= 0) {
            throw new Exception('ข้อมูลรายการเงินสดไม่ถูกต้อง');
        }
        if ($this->hasPositionModel($branchId)) {
            $source = $direction === 'out' ? 'drawer' : 'external';
            $destination = $direction === 'in' ? 'drawer' : 'external';
            return $this->recordPositionMovement(
                $branchId,
                $direction === 'in' ? 'increase' : 'decrease',
                $amount, $source, $destination,
                $type, $referenceType, $referenceId, $description, $userId
            );
        }
        $session = $this->assertOpen($branchId);
        if ($direction === 'out') {
            $available = round((float)$session['opening_actual'] + $this->movementTotal((int)$session['id']), 2);
            if ($available + 0.0001 < $amount) {
                throw new Exception('เงินสดในลิ้นชักไม่เพียงพอ กรุณาเติมเงินเข้าลิ้นชักก่อน');
            }
        }
        $stmt = $this->db->prepare(
            "INSERT INTO cash_movements
               (cash_session_id,branch_id,direction,movement_type,amount,reference_type,reference_id,description,recorded_by)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $this->db->execute($stmt, [
            (int)$session['id'], $branchId, $direction, substr($type, 0, 40), round($amount, 2),
            substr($referenceType, 0, 40), $referenceId, substr(trim($description), 0, 500), $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function recordBankMovement(int $branchId, string $direction, float $amount,
        string $movementType, int $referenceId, string $description, int $userId,
        string $referenceType = 'bank_transaction'): int
    {
        $amount = round($amount, 2);
        if ($amount <= 0) return 0;
        $effect = $direction === 'in' ? 'increase' : 'decrease';
        $existing = $this->db->fetchColumn(
            "SELECT id FROM cash_movements WHERE movement_type=? AND reference_type=? AND reference_id=? LIMIT 1",
            [$movementType, $referenceType, $referenceId]
        );
        if ($existing) return (int)$existing;
        $session = $this->db->fetch(
            "SELECT id FROM cash_sessions
             WHERE branch_id=? AND status IN ('open','pending_close')
             ORDER BY business_date DESC LIMIT 1",
            [$branchId]
        );
        if (!$session) {
            throw new Exception('สาขานี้ยังไม่ได้เปิดยอดประจำวัน');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO cash_movements
             (cash_session_id,branch_id,direction,source_location,destination_location,balance_effect,
                business_date,amount,movement_type,reference_type,reference_id,description,recorded_by)
             VALUES (?,?,?,?,?,?,CURDATE(),?,?,?,?,?,?)"
        );
        $this->db->execute($stmt, [
            (int)$session['id'], $branchId, $direction, 'external', 'external', $effect,
            $amount, substr($movementType, 0, 40), substr($referenceType, 0, 40),
            $referenceId, substr($description, 0, 500), $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function transferDrawerOut(int $branchId, float $amount, string $destination,
        string $description, int $userId, int $referenceId = 0,
        string $referenceType = 'cash_position'): int
    {
        $amount = round($amount, 2);
        if ($amount <= 0) throw new Exception('จำนวนเงินไม่ถูกต้อง');
        if (!in_array($destination, ['business_reserve', 'external'], true)) {
            throw new Exception('ปลายทางของเงินไม่ถูกต้อง');
        }
        $session = $this->assertOpen($branchId);
        $drawerBalance = round((float)$session['opening_actual'] + $this->movementTotal((int)$session['id']), 2);
        if ($drawerBalance + 0.0001 < $amount) {
            throw new Exception('เงินสดในลิ้นชักไม่เพียงพอสำหรับย้ายออก');
        }
        $effect = $destination === 'business_reserve' ? 'transfer' : 'decrease';
        return $this->insertPositionMovement(
            (int)$session['id'], $branchId, 'out', $effect, $amount,
            'drawer', $destination, 'drawer_cash_out', $referenceId,
            $description, $userId, $referenceType
        );
    }

    /**
     * Simple deposit: just add cash to the drawer via movement.
     */
    public function fundDrawer(int $branchId, float $amount, string $sourceType,
        int $referenceId, string $description, int $userId, float $excess = 0.0): int
    {
        return $this->recordMovement(
            $branchId, 'in', 'cash_deposit', $amount,
            'cash_deposit_request', $referenceId, $description, $userId
        );
    }

    /**
     * Simple daily drawer open for position model branches.
     * Sets opening_actual directly, no reserve/capital logic.
     */
    private function openPositionDay(int $branchId, float $actualCash, ?string $reason, int $userId): array
    {
        $reason = $this->normalizeReason($reason);
        $this->db->beginTransaction();
        try {
            $actualCash = round($actualCash, 2);

            $existing = $this->db->fetch(
                "SELECT id, status FROM cash_sessions
                 WHERE branch_id=? AND business_date=CURDATE() FOR UPDATE",
                [$branchId]
            );

            if ($existing && in_array($existing['status'] ?? '', ['pending_open','open','pending_close'], true)) {
                $sessionId = (int)$existing['id'];
                $this->db->query(
                    "UPDATE cash_sessions
                     SET status='open', cash_model_version=2, opening_expected=0, opening_actual=?,
                         opening_reason=?, opening_requested_by=?, opened_by=?, opened_at=NOW(),
                         closing_expected=NULL, closing_actual=NULL, closing_reason=NULL,
                         closing_requested_by=NULL, closed_by=NULL, closed_at=NULL,
                         last_reviewed_by=NULL, last_reviewed_at=NULL, last_review_note=NULL
                     WHERE id=?",
                    [$actualCash, $reason, $userId, $userId, $sessionId]
                );
                $this->db->query(
                    "DELETE FROM cash_movements WHERE cash_session_id=?",
                    [$sessionId]
                );
            } elseif ($existing) {
                $sessionId = (int)$existing['id'];
                $this->db->query(
                    "UPDATE cash_sessions
                     SET status='open', cash_model_version=2, opening_expected=0, opening_actual=?,
                         opening_reason=?, opening_requested_by=?, opened_by=?, opened_at=NOW(),
                         closing_expected=NULL, closing_actual=NULL, closing_reason=NULL,
                         closing_requested_by=NULL, closed_by=NULL, closed_at=NULL,
                         last_reviewed_by=NULL, last_reviewed_at=NULL, last_review_note=NULL
                     WHERE id=?",
                    [$actualCash, $reason, $userId, $userId, $sessionId]
                );
                $this->db->query(
                    "DELETE FROM cash_movements WHERE cash_session_id=?",
                    [$sessionId]
                );
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO cash_sessions
                       (branch_id,business_date,status,cash_model_version,opening_expected,opening_actual,
                        opening_reason,opening_requested_by,opened_by,opened_at)
                     VALUES (?,CURDATE(),'open',2,0,?,?,?,?,NOW())"
                );
                $this->db->execute($stmt, [
                    $branchId, $actualCash, $reason, $userId, $userId,
                ]);
                $sessionId = (int)$this->db->lastInsertId();
            }

            $this->addEvent($sessionId, 'opened', 0, $actualCash, 0, $reason, $userId);
            $this->db->commit();
            return [
                'id' => $sessionId,
                'status' => 'open',
                'expected_cash' => $actualCash,
                'variance' => 0.0,
                'drawer_balance' => $actualCash,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Simple daily drawer close for position model branches.
     * Records variance only, no auto-transfer to safe.
     */
    private function closePositionDay(int $branchId, float $actualCash, ?string $reason, int $userId): array
    {
        $reason = $this->normalizeReason($reason);
        $this->db->beginTransaction();
        try {
            $session = $this->db->fetch(
                "SELECT * FROM cash_sessions
                 WHERE branch_id=? AND status='open' ORDER BY business_date DESC LIMIT 1 FOR UPDATE",
                [$branchId]
            );
            if (!$session) throw new Exception('สาขานี้ยังไม่มีรอบประจำวันที่เปิดอยู่');
            $expected = round((float)$session['opening_actual'] + $this->movementTotal((int)$session['id']), 2);
            $actualCash = round($actualCash, 2);
            $variance = round($actualCash - $expected, 2);
            if (abs($variance) > 0.009 && $reason === null) {
                throw new Exception('ยอดเงินจริงไม่ตรงยอดในลิ้นชัก กรุณาระบุเหตุผล');
            }
            $requiresApproval = abs($variance) > self::VARIANCE_APPROVAL_THRESHOLD;
            $status = $requiresApproval ? 'pending_close' : 'closed';
            $this->db->query(
                "UPDATE cash_sessions
                 SET status=?, cash_model_version=2, closing_expected=?, closing_actual=?, closing_reason=?,
                      closing_requested_by=?, closed_by=?, closed_at=?
                 WHERE id=?",
                [$status, $expected, $actualCash, $reason, $userId,
                 $requiresApproval ? null : $userId,
                 $requiresApproval ? null : date('Y-m-d H:i:s'),
                 (int)$session['id']]
            );
            $this->addEvent((int)$session['id'], $requiresApproval ? 'close_requested' : 'closed',
                $expected, $actualCash, $variance, $reason, $userId);
            $this->db->commit();
            return [
                'id' => (int)$session['id'], 'status' => $status,
                'expected_cash' => $expected, 'variance' => $variance,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function recordPositionMovement(int $branchId, string $effect, float $amount,
        string $source, string $destination, string $type, string $referenceType,
        int $referenceId, string $description, int $userId): int
    {
        $session = $this->assertOpen($branchId);
        $drawerBalance = round((float)$session['opening_actual'] + $this->movementTotal((int)$session['id']), 2);
        if ($source === 'drawer' && $drawerBalance + 0.0001 < $amount) {
            throw new Exception('เงินสดในลิ้นชักไม่เพียงพอ กรุณาเติมเงินเข้าลิ้นชักก่อน');
        }
        return $this->insertPositionMovement(
            (int)$session['id'], $branchId,
            $destination === 'drawer' ? 'in' : 'out', $effect, $amount,
            $source, $destination, $type, $referenceId, $description, $userId,
            $referenceType
        );
    }

    private function insertPositionMovement(int $sessionId, int $branchId, string $direction, string $effect,
        float $amount, string $source, string $destination, string $type, int $referenceId,
        string $description, int $userId, string $referenceType = 'cash_position', float $excess = 0.0): int
    {
        if ($amount <= 0) return 0;
        $existing = $this->db->fetchColumn(
            "SELECT id FROM cash_movements WHERE movement_type=? AND reference_type=? AND reference_id=? LIMIT 1",
            [$type, $referenceType, $referenceId]
        );
        if ($existing) return (int)$existing;
        $stmt = $this->db->prepare(
            "INSERT INTO cash_movements
             (cash_session_id,branch_id,direction,source_location,destination_location,balance_effect,
                business_date,amount,excess_amount,movement_type,reference_type,reference_id,description,recorded_by)
             VALUES (?,?,?,?,?,?,CURDATE(),?,?,?,?,?,?,?)"
        );
        $this->db->execute($stmt, [
            $sessionId, $branchId, $direction, $source, $destination, $effect,
            round($amount, 2), round($excess, 2), substr($type, 0, 50), substr($referenceType, 0, 40),
            $referenceId, substr(trim($description), 0, 500), $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function applyPositionFields(array $row): array
    {
        $ledgerTotal = !empty($row['id']) ? $this->movementTotal((int)$row['id']) : 0;
        $expectedCash = round((float)($row['opening_actual'] ?? 0) + $ledgerTotal, 2);
        $row['current_expected_cash'] = $expectedCash;
        // Simple daily drawer: drawer_balance = current_expected_cash
        $row['drawer_balance'] = $expectedCash;
        if ($this->hasPositionModel((int)($row['branch_id'] ?? 0))) {
            $row['cash_model_version'] = 2;
            $positions = $this->getPositionBalances((int)$row['branch_id']);
            $row['reserve_balance'] = $positions['reserve_balance'];
            $row['bank_balance'] = $positions['bank_balance'] ?? 0.0;
            $row['business_total_cash'] = $positions['business_total_cash'];
        }
        return $row;
    }

    public function hasMovement(string $type, string $referenceType, int $referenceId): bool
    {
        return (bool)$this->db->fetchColumn(
            "SELECT id FROM cash_movements WHERE movement_type=? AND reference_type=? AND reference_id=? LIMIT 1",
            [$type, $referenceType, $referenceId]
        );
    }

    public function getMovements(int $sessionId): array
    {
        return $this->db->fetchAll(
            "SELECT cm.*, u.full_name AS recorded_by_name
             FROM cash_movements cm LEFT JOIN users u ON u.id=cm.recorded_by
             WHERE cm.cash_session_id=? ORDER BY cm.id ASC",
            [$sessionId]
        ) ?: [];
    }

    private function movementTotal(int $sessionId): float
    {
        return round((float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0)
             FROM cash_movements WHERE cash_session_id=?
               AND (movement_type IS NULL OR movement_type NOT LIKE 'bank\\\\_%')",
            [$sessionId]
        ), 2);
    }

    private function addEvent(int $sessionId, string $type, ?float $expected, ?float $actual,
        ?float $variance, ?string $reason, int $actorId, ?int $reviewerId = null): void
    {
        $this->db->query(
            "INSERT INTO cash_session_events
               (cash_session_id,event_type,expected_amount,actual_amount,variance_amount,reason,actor_id,reviewer_id)
             VALUES (?,?,?,?,?,?,?,?)",
            [$sessionId, $type, $expected, $actual, $variance, $reason, $actorId, $reviewerId]
        );
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim((string)$reason);
        return $reason === '' ? null : substr($reason, 0, 500);
    }
}
