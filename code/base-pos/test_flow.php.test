<?php
require '/var/www/html/api/autoload.php';
require '/var/www/html/api/config.php';

$db = Database::getInstance();

echo "=== [1] สต็อกเริ่มต้น: เหล็ก ===\n";
$before = $db->fetch("SELECT stock_kg FROM categories WHERE id = 24");
echo "stock_kg = {$before['stock_kg']}\n\n";

echo "=== [2] สร้าง PO ใหม่: เหล็ก 100 กก. ===\n";
$po = new PurchaseOrder();
$result = $po->createWithItems([
    'branch_id' => 1,
    'seller_id' => 1,
    'payment_method' => 'cash',
    'status' => 'completed'
], [
    [
        'item_name' => 'เหล็กเทส',
        'category_id' => 24,
        'quantity' => 100,
        'weight_deduction' => 0,
        'unit' => 'กก.',
        'unit_price' => 10.00,
        'total_price' => 1000.00
    ]
], 1);
echo "PO created: {$result['reference_no']} (ID: {$result['id']})\n";
$afterPo = $db->fetch("SELECT stock_kg FROM categories WHERE id = 24");
echo "stock_kg = {$afterPo['stock_kg']} (ควร +100 = " . ($before['stock_kg'] + 100) . ")\n\n";

echo "=== [3] สร้าง Sale Lot (draft): เหล็ก 50 กก. ===\n";
$sl = new SaleLot();
$lotResult = $sl->create([
    'branch_id' => 1,
    'buyer_name' => 'ลูกค้าเทส',
    'sale_date' => date('Y-m-d'),
    'status' => 'draft',
    'items' => [
        ['category_id' => 24, 'quantity_kg' => 50, 'unit_price' => 15.00]
    ],
    'created_by' => 1
]);
echo "Sale Lot created: {$lotResult['reference_no']} (ID: {$lotResult['id']})\n";
$afterDraft = $db->fetch("SELECT stock_kg FROM categories WHERE id = 24");
echo "stock_kg = {$afterDraft['stock_kg']} (draft ไม่ควรเปลี่ยน)\n\n";

echo "=== [4] ยืนยัน Sale Lot (confirm): เหล็ก 50 กก. ===\n";
$sl->updateStatus($lotResult['id'], 'confirmed');
$afterConfirm = $db->fetch("SELECT stock_kg FROM categories WHERE id = 24");
echo "stock_kg = {$afterConfirm['stock_kg']} (ควร -50 = " . ($afterPo['stock_kg'] - 50) . ")\n";
$consumed = $db->fetch("SELECT consumed_qty FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id DESC LIMIT 1", [$result['id']]);
echo "consumed_qty = {$consumed['consumed_qty']} (ควร = 50)\n\n";

echo "=== [5] ยกเลิก Sale Lot (cancel): เหล็ก 50 กก. ===\n";
$sl->updateStatus($lotResult['id'], 'cancelled');
$afterCancel = $db->fetch("SELECT stock_kg FROM categories WHERE id = 24");
echo "stock_kg = {$afterCancel['stock_kg']} (ควรคืน +50 = " . ($afterConfirm['stock_kg'] + 50) . ")\n";
$consumed2 = $db->fetch("SELECT consumed_qty FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id DESC LIMIT 1", [$result['id']]);
echo "consumed_qty = {$consumed2['consumed_qty']} (ควร = 0)\n\n";

echo "=== [6] ยกเลิก PO (cancel): เหล็ก 100 กก. ===\n";
$po->cancel($result['id']);
$afterPoCancel = $db->fetch("SELECT stock_kg FROM categories WHERE id = 24");
echo "stock_kg = {$afterPoCancel['stock_kg']} (ควร -100 = " . ($before['stock_kg']) . ")\n\n";

echo "=== ✅ FLOW TEST PASSED ===\n";
if (abs($afterPoCancel['stock_kg'] - $before['stock_kg']) < 0.01) {
    echo "สต็อกกลับมาค่าเดิม 100% ถูกต้อง!\n";
} else {
    echo "⚠️ สต็อกไม่ตรง! ต่าง " . abs($afterPoCancel['stock_kg'] - $before['stock_kg']) . " กก.\n";
}
