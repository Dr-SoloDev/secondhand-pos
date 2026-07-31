<?php
/**
 * PrintController — สั่งพิมพ์ใบรับซื้อไปยังเครื่องพิมพ์ Thermal (Deli S420)
 * ผ่าน Print Server HTTP ที่รันอยู่บน Host Machine
 *
 * Flow: PHP fetch data → ส่งไป Print Server → Print Server ส่ง ESC/POS ไป USB Printer
 */
class PrintController extends Controller
{
    private $printServerUrl;

    public function __construct($user = null)
    {
        parent::__construct($user);

        $host = getenv('PRINT_SERVER_HOST') ?: 'host.docker.internal';
        $port = getenv('PRINT_SERVER_PORT') ?: '9120';
        $this->printServerUrl = "http://{$host}:{$port}";
    }

    /**
     * POST /api/print/thermal-purchase
     * Body: { "id": 123 }
     *
     * 1. ดึงข้อมูล PO (ผ่าน Model, auth แล้ว)
     * 2. ส่งข้อมูลไป Print Server → พิมพ์
     */
    public function thermalPurchase()
    {
        $this->requireAuth();

        $id = $this->getPurchaseIdFromRequest();
        $po = $this->getPrintablePurchaseOrder($id);
        $payload = $this->buildThermalPurchasePayload($id, $po);

        $result = $this->callPrintServer('/print', $payload);

        if (!$result['success']) {
            error_log("[Print] #{$id} failed: {$result['error']}");
            Response::error('พิมพ์ไม่สำเร็จ: ' . $result['error'], 500);
        }

        Response::success('สั่งพิมพ์สำเร็จ', [
            'id' => $id,
            'type' => 'purchase',
            'server_response' => $result['data']
        ]);
    }

    /**
     * POST /api/print/thermal-purchase/preview
     * Body: { "id": 123 }
     */
    public function previewThermalPurchase()
    {
        $this->requireAuth();

        $id = $this->getPurchaseIdFromRequest();
        $po = $this->getPrintablePurchaseOrder($id);
        $payload = $this->buildThermalPurchasePayload($id, $po, 'image');

        $result = $this->callPrintServer('/preview', $payload);

        if (!$result['success']) {
            error_log("[Print Preview] #{$id} failed: {$result['error']}");
            Response::error('สร้างตัวอย่างบิลไม่สำเร็จ: ' . $result['error'], 500);
        }

        $imageBase64 = $result['data']['image_base64'] ?? '';
        if ($imageBase64 === '') {
            Response::error('Print Server ไม่ส่งภาพตัวอย่างกลับมา', 500);
        }

        Response::success('สร้างตัวอย่างบิลสำเร็จ', [
            'id' => $id,
            'type' => 'purchase',
            'image_base64' => $imageBase64
        ]);
    }

    /**
     * GET /api/print/status
     */
    public function status()
    {
        $this->requireAuth();

        $printerName = getenv('PRINTER_NAME') ?: 'Deli-S420';

        // เช็ค Print Server health
        $serverResult = $this->callPrintServer('/health');

        Response::success('สถานะเครื่องพิมพ์', [
            'printer' => $printerName,
            'print_server' => $serverResult['data'] ?? 'offline',
            'ready' => $serverResult['success']
        ]);
    }

    /**
     * GET /api/print/test
     * ทดสอบการเชื่อมต่อ Print Server
     */
    public function test()
    {
        $this->requireAuth();

        $health = $this->callPrintServer('/health');
        if (!$health['success']) {
            Response::error('Print Server ไม่พร้อมทำงาน', 500);
        }

        Response::success('Print Server พร้อมทำงาน', $health['data']);
    }

    private function getPurchaseIdFromRequest()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if (!$id) {
            Response::error('กรุณาระบุรหัสใบรับซื้อ', 400);
        }
        return $id;
    }

    private function getPrintablePurchaseOrder($id)
    {
        // ดึงข้อมูล PO โดยตรง (ไม่ต้องเรียก API ซ้อน)
        $model = new PurchaseOrder();
        $po = $model->getById($id);
        if (!$po) {
            Response::error('ไม่พบใบรับซื้อ', 404);
        }

        // SECURITY: non-admin ดูได้เฉพาะ PO ของสาขาตัวเอง
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$po['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์เข้าถึงใบรับซื้อนี้', 403);
            }
        }

        // G2-E2: detect precious metal flag
        $isPreciousMetal = false;
        foreach ($po['items'] as $item) {
            if (!empty($item['requires_precious_receipt'])) {
                $isPreciousMetal = true;
                break;
            }
            if (!empty($item['category_name']) && mb_strpos($item['category_name'], 'ทองแดง') !== false) {
                $isPreciousMetal = true;
                break;
            }
        }
        $po['is_precious_metal'] = $isPreciousMetal;

        return $this->applyReceiptSettings($po);
    }

    private function buildThermalPurchasePayload($id, $po, $mode = 'image')
    {
        // ใช้ภาพจากฟอนต์ไทยสำหรับการพิมพ์จริง เพราะ Deli S420 บาง firmware
        // เลือก code page จีนเองเมื่อรับไบต์ CP874
        return json_encode([
            'id' => $id,
            'type' => 'purchase',
            'data' => $po,
            'mode' => $mode,
            'encoding' => 'cp874'
        ], JSON_UNESCAPED_UNICODE);
    }

    private function applyReceiptSettings($po)
    {
        $settings = (new Setting())->getReceiptSettings();
        $po['shop_name'] = $settings['store_name'];
        $po['shop_phone'] = $settings['store_phone'] !== '' ? $settings['store_phone'] : ($po['branch_phone'] ?? '');
        $po['shop_address'] = $settings['store_address'];
        $po['tax_id'] = $settings['tax_id'];
        $po['receipt_footer'] = $settings['receipt_footer'];
        $po['receipt_welcome_message'] = $settings['receipt_welcome_message'];
        return $po;
    }

    /**
     * เรียก Print Server HTTP
     */
    private function callPrintServer($path, $postBody = null)
    {
        $url = $this->printServerUrl . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if ($postBody !== null) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postBody,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($postBody)
                ],
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && ($data['status'] ?? '') === 'success') {
            return ['success' => true, 'data' => $data];
        }

        $msg = $data['message'] ?? "HTTP $httpCode";
        if (!empty($error)) $msg .= " ($error)";
        return ['success' => false, 'error' => $msg];
    }
}
