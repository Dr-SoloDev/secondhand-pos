<?php
/**
 * BranchStock Model — Per-branch per-item stock tracking
 *
 * Source of Truth สำหรับสต็อกทั้งหมดในระบบ (ตาม ADD-001)
 * แทน categories.stock_kg เดิมที่เป็น global aggregate
 *
 * @package ScrapPOS
 * @author  SoloCorp OS — Engineering (ช่างฟูล)
 * @see     ADD-001: Branch Stock Redesign
 */
class BranchStock extends Model
{
    protected $table = 'branch_stock';

    /**
     * UPSERT stock ใน branch_stock
     * - ถ้ามี record อยู่แล้ว: stock_kg += ? และ recalculate unit_price แบบ weighted average
     * - ถ้ายังไม่มี: INSERT ใหม่
     *
     * ใช้เมื่อ:
     *   - PO Create (เพิ่ม stock)
     *   - Stock Transfer destination (รับ stock)
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @param float  $stockKg   จำนวน kg ที่เพิ่ม
     * @param float  $unitPrice ราคาต่อหน่วยของ lot ที่เพิ่ม
     */
    public function upsert($branchId, $categoryId, $itemName, $stockKg, $unitPrice): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO branch_stock (branch_id, category_id, item_name, stock_kg, unit_price)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 stock_kg   = stock_kg + VALUES(stock_kg),
                 unit_price = ROUND(
                     ((stock_kg * unit_price) + (VALUES(stock_kg) * VALUES(unit_price)))
                     / GREATEST((stock_kg + VALUES(stock_kg)), 0.001),
                     4
                 ),
                 last_updated = NOW()"
        );
        $this->db->execute($stmt, [
            (int)$branchId,
            (int)$categoryId,
            trim($itemName),
            (float)$stockKg,
            (float)$unitPrice,
        ]);
    }

    /**
     * Deduct (หัก) stock จาก branch_stock
     * - stock_kg = GREATEST(0, stock_kg - qty) — ไม่ติดลบ
     *
     * ใช้เมื่อ:
     *   - SaleLot Confirm (ขายของ)
     *   - PO Cancel (ยกเลิกใบรับซื้อ)
     *   - Stock Transfer origin (โอนออก)
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @param float  $qty
     */
    public function deduct($branchId, $categoryId, $itemName, $qty): void
    {
        $this->db->query(
            "UPDATE branch_stock
             SET stock_kg = GREATEST(0, stock_kg - ?),
                 last_updated = NOW()
             WHERE branch_id = ?
               AND category_id = ?
               AND item_name = ?",
            [
                (float)$qty,
                (int)$branchId,
                (int)$categoryId,
                trim($itemName),
            ]
        );
    }

    /**
     * Restore (คืน) stock เข้า branch_stock
     * - stock_kg = stock_kg + qty
     *
     * ใช้เมื่อ:
     *   - SaleLot Cancel (ยกเลิกการขาย — คืน stock)
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @param float  $qty
     */
    public function restore($branchId, $categoryId, $itemName, $qty): void
    {
        $this->db->query(
            "UPDATE branch_stock
             SET stock_kg = stock_kg + ?,
                 last_updated = NOW()
             WHERE branch_id = ?
               AND category_id = ?
               AND item_name = ?",
            [
                (float)$qty,
                (int)$branchId,
                (int)$categoryId,
                trim($itemName),
            ]
        );
    }

    /**
     * ดึง stock ของ item ใด item หนึ่ง
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @return array|null
     */
    public function getStock($branchId, $categoryId, $itemName): ?array
    {
        $result = $this->db->fetch(
            "SELECT *
             FROM branch_stock
             WHERE branch_id = ?
               AND category_id = ?
               AND item_name = ?",
            [
                (int)$branchId,
                (int)$categoryId,
                trim($itemName),
            ]
        );
        return $result ?: null;
    }

    /**
     * ดึง stock ทั้งหมดของสาขาที่กำหนด (ที่มี stock_kg > 0)
     *
     * @param int $branchId
     * @return array
     */
    public function getByBranch($branchId): array
    {
        return $this->db->fetchAll(
            "SELECT bs.*, c.name AS category_name
             FROM branch_stock bs
             LEFT JOIN categories c ON bs.category_id = c.id
             WHERE bs.branch_id = ?
               AND bs.stock_kg > 0
             ORDER BY c.name ASC, bs.item_name ASC",
            [(int)$branchId]
        );
    }
}
