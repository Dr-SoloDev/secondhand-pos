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
                $row['is_stale'] = true;
                return $row;
            }

            // No session yet today — still return expected carry-forward so the open form can show it.
            $branchName = $this->db->fetchColumn(
                "SELECT name FROM branches WHERE id = ?",
                [$branchId]
            );
            $previousClosing = $this->db->fetchColumn(
                "SELECT closing_actual FROM cash_sessions
                 WHERE branch_id = ? AND status = 'closed' AND business_date < CURDATE()
                 ORDER BY business_date DESC LIMIT 1",
                [$branchId]
            );
            $expected = round((float)($previousClosing ?? 0), 2);
            return [
                'id' => null,
                'branch_id' => $branchId,
                'branch_name' => $branchName ?: null,
                'business_date' => date('Y-m-d'),
                'status' => null,
                'opening_expected' => $expected,
                'opening_actual' => null,
                'opening_variance' => null,
                'ledger_total' => 0,
                'current_expected_cash' => $expected,
                'movements' => [],
            ];
        }
        $row['ledger_total'] = $this->movementTotal((int)$row['id']);
        $row['current_expected_cash'] = round((float)$row['opening_actual'] + $row['ledger_total'], 2);
        $row['movements'] = $this->getMovements((int)$row['id']);
        return $row;
    }

    /**
     * P0-5: คืน branch_id ของ session เพื่อใช้ตรวจ branch scope ในการอนุมัติ
     */
    public function getBranch(int $sessionId): ?int
    {
        $branchId = $this->db->fetchColumn(
            "SELECT branch_id FROM cash_sessions WHERE id = ?",
            [$sessionId]
        );
        return $branchId !== null && $branchId !== false ? (int)$branchId : null;
    }

    public function openDay(int $branchId, float $actualCash, ?string $reason, int $userId): array
    {
        if (!is_finite($actualCash) || $actualCash < 0) {
            throw new Exception('ยอดเงินสดเปิดวันไม่ถูกต้อง');
        }
        $reason = $this->normalizeReason($reason);

        $this->db->beginTransaction();
        try {
            $unfinished = $this->db->fetch(
                "SELECT id, business_date, status FROM cash_sessions
                 WHERE branch_id = ? AND status IN ('pending_open','open','pending_close')
                 ORDER BY business_date DESC LIMIT 1 FOR UPDATE",
                [$branchId]
            );
            if ($unfinished) {
                throw new Exception('สาขานี้มีรอบประจำวันที่ยังดำเนินการไม่เสร็จ');
            }

            $previousClosing = $this->db->fetchColumn(
                "SELECT closing_actual FROM cash_sessions
                 WHERE branch_id = ? AND status = 'closed' AND business_date < CURDATE()
                 ORDER BY business_date DESC LIMIT 1 FOR UPDATE",
                [$branchId]
            );
            $expected = round((float)($previousClosing ?? 0), 2);
            $actualCash = round($actualCash, 2);
            $variance = round($actualCash - $expected, 2);
            if (abs($variance) > 0.009 && $reason === null) {
                throw new Exception('ยอดเงินจริงไม่ตรงยอดยกมา กรุณาระบุเหตุผล');
            }
            $requiresApproval = abs($variance) > self::VARIANCE_APPROVAL_THRESHOLD;
            $status = $requiresApproval ? 'pending_open' : 'open';

            $existing = $this->db->fetch(
                "SELECT id, status FROM cash_sessions
                 WHERE branch_id = ? AND business_date = CURDATE() FOR UPDATE",
                [$branchId]
            );
            if ($existing && ($existing['status'] ?? '') !== 'rejected') {
                throw new Exception('สาขานี้เปิดรอบประจำวันนี้ไปแล้ว');
            }

            if ($existing) {
                $sessionId = (int)$existing['id'];
                $this->db->query(
                    "UPDATE cash_sessions
                     SET status=?, opening_expected=?, opening_actual=?, opening_reason=?,
                         opening_requested_by=?, opened_by=?, opened_at=?,
                         last_reviewed_by=NULL, last_reviewed_at=NULL, last_review_note=NULL
                     WHERE id=?",
                    [$status, $expected, $actualCash, $reason, $userId,
                     $requiresApproval ? null : $userId, $requiresApproval ? null : date('Y-m-d H:i:s'), $sessionId]
                );
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO cash_sessions
                       (branch_id,business_date,status,opening_expected,opening_actual,opening_reason,
                        opening_requested_by,opened_by,opened_at)
                     VALUES (?,CURDATE(),?,?,?,?,?,?,?)"
                );
                $this->db->execute($stmt, [
                    $branchId, $status, $expected, $actualCash, $reason, $userId,
                    $requiresApproval ? null : $userId,
                    $requiresApproval ? null : date('Y-m-d H:i:s'),
                ]);
                $sessionId = (int)$this->db->lastInsertId();
            }
            $this->addEvent($sessionId, $requiresApproval ? 'open_requested' : 'opened', $expected, $actualCash, $variance, $reason, $userId);
            $this->db->commit();
            return ['id' => $sessionId, 'status' => $status, 'expected_cash' => $expected, 'variance' => $variance];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function approveOpen(int $sessionId, int $approverId, ?string $reviewNote = null): void
    {
        $this->reviewOpen($sessionId, $approverId, true, $reviewNote);
    }

    public function rejectOpen(int $sessionId, int $reviewerId, string $reviewNote): void
    {
        $this->reviewOpen($sessionId, $reviewerId, false, $reviewNote);
    }

    private function reviewOpen(int $sessionId, int $reviewerId, bool $approve, ?string $reviewNote): void
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
            if ((int)$session['opening_requested_by'] === $reviewerId) {
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

    public function closeDay(int $branchId, float $actualCash, ?string $reason, int $userId): array
    {
        if (!is_finite($actualCash) || $actualCash < 0) {
            throw new Exception('ยอดเงินสดปิดวันไม่ถูกต้อง');
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

    public function reviewClose(int $sessionId, int $reviewerId, bool $approve, ?string $reviewNote): void
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
            if ((int)$session['closing_requested_by'] === $reviewerId) {
                throw new Exception('ผู้ขอปิดยอดไม่สามารถอนุมัติรายการตัวเองได้');
            }
            $newStatus = $approve ? 'closed' : 'open';
            $this->db->query(
                "UPDATE cash_sessions SET status=?, closed_by=?, closed_at=?,
                        last_reviewed_by=?, last_reviewed_at=NOW(), last_review_note=? WHERE id=?",
                [$newStatus, $approve ? $reviewerId : null, $approve ? date('Y-m-d H:i:s') : null,
                 $reviewerId, $reviewNote, $sessionId]
            );
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
            $currentExpected = round(
                (float)$session['opening_actual'] + $this->movementTotal($sessionId),
                2
            );
            $closingActual = round((float)$session['closing_actual'], 2);
            $rebaseAmount = round($closingActual - $currentExpected, 2);
            $this->db->query(
                "UPDATE cash_sessions
                 SET status='open', closing_expected=NULL, closing_actual=NULL, closing_reason=NULL,
                     closing_requested_by=NULL, closed_by=NULL, closed_at=NULL,
                     last_reviewed_by=?, last_reviewed_at=NOW(), last_review_note=?
                 WHERE id=?",
                [$adminId, $reason, $sessionId]
            );
            if (abs($rebaseAmount) > 0.009) {
                $this->db->query(
                    "INSERT INTO cash_movements
                       (cash_session_id,branch_id,direction,movement_type,amount,reference_type,reference_id,description,recorded_by)
                     VALUES (?,?,?,?,?,'cash_session_reopen',NULL,?,?)",
                    [
                        $sessionId,
                        (int)$session['branch_id'],
                        $rebaseAmount > 0 ? 'in' : 'out',
                        'session_reopen_rebase',
                        abs($rebaseAmount),
                        'ปรับฐานยอดหลังเปิดรอบใหม่จากยอดปิดที่อนุมัติ',
                        $adminId,
                    ]
                );
            }
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

    public function recordMovement(int $branchId, string $direction, string $type, float $amount,
        string $referenceType, int $referenceId, string $description, int $userId): int
    {
        if (!in_array($direction, ['in', 'out'], true) || !is_finite($amount) || $amount <= 0) {
            throw new Exception('ข้อมูลรายการเงินสดไม่ถูกต้อง');
        }
        $session = $this->assertOpen($branchId);
        if ($direction === 'out') {
            $available = round((float)$session['opening_actual'] + $this->movementTotal((int)$session['id']), 2);
            if ($available + 0.0001 < $amount) {
                throw new Exception('เงินสดในลิ้นชักไม่เพียงพอสำหรับรายการนี้');
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
             FROM cash_movements WHERE cash_session_id=?",
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
