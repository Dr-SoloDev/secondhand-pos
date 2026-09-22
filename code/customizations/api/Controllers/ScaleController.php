<?php
class ScaleController extends Controller
{
    /**
     * GET /api/scale/devices?branch_id=X
     */
    public function getDevices()
    {
        $this->requireAuth();
        $branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
        if (!$branchId) {
            // non-admin บังคับสาขาตัวเอง
            if (!in_array(($this->user['role'] ?? ''), ['admin','super_manager'], true)) {
                $branchId = (int)($this->user['branch_id'] ?? 0);
            }
        }
        if (!$branchId) Response::error('ต้องระบุสาขา', 400);

        $this->assertReadBranchAccess($branchId, 'ไม่มีสิทธิ์ดูเครื่องชั่งสาขานี้');
        $devices = (new ScaleDevice())->getByBranch($branchId);
        // แนบ scale_mode ของสาขา
        $branch = (new Branch())->findById($branchId);
        Response::success('Scale devices retrieved', [
            'branch_id' => $branchId,
            'scale_mode' => $branch['scale_mode'] ?? 'disabled',
            'devices' => $devices,
        ]);
    }

    /**
     * GET /api/scale/device?id=X
     */
    public function getDevice($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('ต้องระบุรหัสเครื่องชั่ง', 400);
        $device = (new ScaleDevice())->getById((int)$id);
        if (!$device) Response::error('ไม่พบเครื่องชั่ง', 404);
        $this->assertReadBranchAccess((int)$device['branch_id'], 'ไม่มีสิทธิ์ดูเครื่องชั่งนี้');
        Response::success('Scale device retrieved', $device);
    }

    /**
     * POST /api/scale/devices
     * Body: { branch_id, code, name, model, serial_no, port, baud_rate }
     */
    public function createDevice()
    {
        $this->requireAuth(['admin','super_manager','manager']);
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['branch_id','code']);
        $branchId = (int)$data['branch_id'];
        $this->assertReadBranchAccess($branchId, 'ไม่มีสิทธิ์เพิ่มเครื่องชั่งสาขานี้');

        // branch ต้องมีอยู่
        if (!(new Branch())->findById($branchId)) Response::error('ไม่พบสาขา', 404);

        try {
            $id = (new ScaleDevice())->createDevice([
                'branch_id' => $branchId,
                'code' => $data['code'],
                'name' => $data['name'] ?? $data['code'],
                'model' => $data['model'] ?? 'Tiger TI-01',
                'serial_no' => $data['serial_no'] ?? null,
                'port' => $data['port'] ?? null,
                'baud_rate' => $data['baud_rate'] ?? 9600,
                'status' => $data['status'] ?? 'active',
            ]);
            Logger::logActivity($this->user['user_id'] ?? $this->user['id'], 'create_scale_device', "Created scale device {$data['code']} for branch {$branchId}", [
                'actor' => $this->user, 'module'=>'scale', 'entity_type'=>'scale_device', 'entity_id'=>$id, 'entity_branch_id'=>$branchId,
            ]);
            Response::success('เพิ่มเครื่องชั่งสำเร็จ', ['id'=>$id]);
        } catch (Exception $e) {
            error_log('Create scale device failed: '.$e->getMessage());
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/scale/device?id=X
     */
    public function updateDevice($id)
    {
        $this->requireAuth(['admin','super_manager','manager']);
        if (!$id) Response::error('ต้องระบุรหัสเครื่องชั่ง', 400);
        $device = (new ScaleDevice())->getById((int)$id);
        if (!$device) Response::error('ไม่พบเครื่องชั่ง', 404);
        $this->assertReadBranchAccess((int)$device['branch_id'], 'ไม่มีสิทธิ์แก้ไขเครื่องชั่งนี้');

        $data = $this->getRequestData();
        $data = $this->sanitizeInput($data);
        $allowed = array_intersect_key($data, array_flip(['name','model','serial_no','port','baud_rate','status']));
        if (empty($allowed)) Response::error('ไม่มีข้อมูลให้อัปเดต', 400);

        try {
            (new ScaleDevice())->updateDevice((int)$id, $allowed);
            Logger::logActivity($this->user['user_id'] ?? $this->user['id'], 'update_scale_device', "Updated scale device ID: {$id}", [
                'actor'=>$this->user,'module'=>'scale','entity_type'=>'scale_device','entity_id'=>(int)$id,'entity_branch_id'=>(int)$device['branch_id'],
            ]);
            Response::success('อัปเดตเครื่องชั่งสำเร็จ');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * DELETE /api/scale/device?id=X
     */
    public function deleteDevice($id)
    {
        $this->requireAuth(['admin','super_manager']);
        if (!$id) Response::error('ต้องระบุรหัสเครื่องชั่ง', 400);
        $device = (new ScaleDevice())->getById((int)$id);
        if (!$device) Response::error('ไม่พบเครื่องชั่ง', 404);
        $this->assertReadBranchAccess((int)$device['branch_id'], 'ไม่มีสิทธิ์ลบเครื่องชั่งนี้');
        (new ScaleDevice())->deleteDevice((int)$id);
        Logger::logActivity($this->user['user_id'] ?? $this->user['id'], 'delete_scale_device', "Deleted scale device ID: {$id}", [
            'actor'=>$this->user,'module'=>'scale','entity_type'=>'scale_device','entity_id'=>(int)$id,'entity_branch_id'=>(int)$device['branch_id'],
        ]);
        Response::success('ลบเครื่องชั่งสำเร็จ');
    }

    /**
     * PUT /api/scale/mode?id=branchId  Body: { scale_mode: disabled|auto|required }
     * ตั้งโหมดตาชั่งต่อสาขา
     */
    public function updateMode($id)
    {
        $this->requireAuth(['admin','super_manager']);
        // id มาจาก query ?id=branchId หรือ body branch_id
        $data = $this->getRequestData();
        if (!$id) $id = isset($data['branch_id']) ? intval($data['branch_id']) : 0;
        if (!$id) Response::error('ต้องระบุสาขา', 400);
        $mode = trim((string)($data['scale_mode'] ?? ''));
        if (!in_array($mode, ['disabled','auto','required'], true)) {
            Response::error('scale_mode ต้องเป็น disabled, auto หรือ required', 422);
        }
        if (!(new Branch())->findById((int)$id)) Response::error('ไม่พบสาขา', 404);
        // ใช้ BranchSetting หรือ update branches โดยตรง — ใช้ branches.scale_mode
        $db = Database::getInstance();
        $db->query("UPDATE branches SET scale_mode = ? WHERE id = ?", [$mode, (int)$id]);
        Logger::logActivity($this->user['user_id'] ?? $this->user['id'], 'update_scale_mode', "Updated scale_mode branch {$id} → {$mode}", [
            'actor'=>$this->user,'module'=>'scale','entity_type'=>'branch','entity_id'=>(int)$id,'after'=>['scale_mode'=>$mode],
        ]);
        Response::success('อัปเดตโหมดตาชั่งสำเร็จ', ['branch_id'=>(int)$id,'scale_mode'=>$mode]);
    }

    /**
     * GET /api/scale/readings?branch_id=X&limit=50
     * ดู audit log
     */
    public function getReadings()
    {
        $this->requireAuth(['admin','super_manager','manager']);
        $branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
        if (!$branchId) {
            if (!in_array(($this->user['role'] ?? ''), ['admin','super_manager'], true)) {
                $branchId = (int)($this->user['branch_id'] ?? 0);
            }
        }
        if (!$branchId) Response::error('ต้องระบุสาขา', 400);
        $this->assertReadBranchAccess($branchId, 'ไม่มีสิทธิ์ดูข้อมูลตาชั่งสาขานี้');
        $limit = min(200, max(1, intval($_GET['limit'] ?? 50)));
        $readings = (new ScaleReading())->getByBranch($branchId, $limit);
        $stats = (new ScaleReading())->getStatsByBranch($branchId);
        Response::success('Scale readings retrieved', ['readings'=>$readings,'stats'=>$stats]);
    }

    /**
     * GET /api/scale/health?branch_id=X
     * เช็คสถานะตาชั่งของสาขา (ให้ frontend รู้ว่า branch นี้มีตาชั่งไหม)
     */
    public function health()
    {
        $this->requireAuth();
        $branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
        if (!$branchId) $branchId = (int)($this->user['branch_id'] ?? 0);
        if (!$branchId) Response::error('ต้องระบุสาขา', 400);
        $branch = (new Branch())->findById($branchId);
        if (!$branch) Response::error('ไม่พบสาขา', 404);
        $devices = (new ScaleDevice())->getByBranch($branchId, 'active');
        Response::success('Scale health', [
            'branch_id' => $branchId,
            'scale_mode' => $branch['scale_mode'] ?? 'disabled',
            'device_count' => count($devices),
            'devices' => $devices,
        ]);
    }
}
