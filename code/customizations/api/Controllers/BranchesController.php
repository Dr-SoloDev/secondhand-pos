<?php
class BranchesController extends Controller
{
    public function getBranches()
    {
        $this->requireAuth();
        $model = new Branch();
        $branches = $model->getAll();
        // แนบ print server + scale devices ของแต่ละสาขา
        if ($branches) {
            $settingModel = new BranchSetting();
            $scaleModel = new ScaleDevice();
            foreach ($branches as &$b) {
                $s = $settingModel->getByBranch(
                    (int)$b['id'],
                    ['print_server_host', 'print_server_port']
                );
                $b['print_server_host'] = $s['print_server_host'] ?? '';
                $b['print_server_port'] = $s['print_server_port'] ?? '';
                // scale_mode อาจยังไม่มี column ก่อน migration 076
                $b['scale_mode'] = $b['scale_mode'] ?? 'disabled';
                try {
                    $devices = $scaleModel->getByBranch((int)$b['id'], 'active');
                    $b['scale_device_count'] = count($devices);
                    $b['scale_devices'] = $devices;
                } catch (Exception $e) { $b['scale_device_count'] = 0; $b['scale_devices'] = []; }
            }
            unset($b);
        }
        Response::success('Branches retrieved', $branches);
    }

    public function getActiveBranches()
    {
        $this->requireAuth();
        $model = new Branch();
        Response::success('Active branches retrieved', $model->getActive());
    }

    public function getBranchSummary()
    {
        $this->requireAuth();
        $role = $this->user['role'] ?? '';
        $branchId = null;
        if (in_array($role, ['admin', 'super_manager'], true)) {
            if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') {
                $branchId = intval($_GET['branch_id']);
            }
        } else {
            $branchId = intval($this->user['branch_id'] ?? 0);
            if (!$branchId) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
        }
        $model = new Branch();
        Response::success('Branch summary retrieved', $model->getSummary($branchId));
    }

    public function getBranch($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('Branch ID is required', 400);
        $model = new Branch();
        $branch = $model->findById($id);
        if (!$branch) Response::error('Branch not found', 404);
        Response::success('Branch retrieved', $branch);
    }

    public function createBranch()
    {
        $this->requireAuth(['admin']);
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['code', 'name']);
        $data = $this->sanitizeInput($data);

        if (isset($data['cost_method']) && $data['cost_method'] !== 'fifo') {
            Response::error('ระบบกำหนดให้ทุกสาขาใช้ต้นทุนแบบ FIFO เท่านั้น', 422);
        }

        $model = new Branch();
        try {
            $id = $model->create($data);
            Logger::logActivity($this->user['user_id'], 'create_branch', "Created branch: {$data['name']}");
            Response::success('Branch created', ['id' => $id]);
        } catch (Exception $e) {
            error_log('Branch create failed: ' . $e->getMessage());
            Response::error('Failed to create branch', 500);
        }
    }

    public function updateBranch($id)
    {
        $this->requireAuth(['admin']);
        if (!$id) Response::error('Branch ID is required', 400);
        $data = $this->getRequestData();
        $data = $this->sanitizeInput($data);
        // scale_mode แยกจัดการ — ไม่ให้ update ผ่าน field ทั่วไปโดยตรง
        $scaleMode = null;
        if (array_key_exists('scale_mode', $data)) {
            $scaleMode = trim((string)$data['scale_mode']);
            if (!in_array($scaleMode, ['disabled','auto','required'], true)) {
                Response::error('scale_mode ต้องเป็น disabled, auto หรือ required', 422);
            }
        }
        $data = array_intersect_key($data, array_flip([
            'code', 'name', 'address', 'phone', 'manager_name', 'status', 'cost_method'
        ]));
        if (array_key_exists('cost_method', $data)) {
            if ($data['cost_method'] !== 'fifo') {
                Response::error('ระบบกำหนดให้ทุกสาขาใช้ต้นทุนแบบ FIFO เท่านั้น', 422);
            }
            $data['cost_method'] = 'fifo';
        }
        if (isset($data['status']) && !in_array($data['status'], ['active', 'inactive'], true)) {
            Response::error('Invalid branch status', 422);
        }
        $model = new Branch();
        try {
            if (!empty($data)) $model->update($id, $data);
            if ($scaleMode !== null) {
                try {
                    Database::getInstance()->query("UPDATE branches SET scale_mode = ? WHERE id = ?", [$scaleMode, (int)$id]);
                } catch (Exception $e) {
                    // column ยังไม่มีก่อน migration 076
                    error_log('scale_mode update failed: '.$e->getMessage());
                }
                Logger::logActivity($this->user['user_id'], 'update_branch_scale_mode', "Updated branch {$id} scale_mode → {$scaleMode}");
            }
            Logger::logActivity($this->user['user_id'], 'update_branch', "Updated branch ID: {$id}");
            Response::success('Branch updated');
        } catch (Exception $e) {
            error_log('Branch update failed: ' . $e->getMessage());
            Response::error('Failed to update branch', 500);
        }
    }

    /**
     * GET /api/branches/branch/print-server?id=X
     * อ่านค่า Print Server (เครื่องพิมพ์ความร้อน) ของสาขา
     */
    public function getBranchPrintServer($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('Branch ID is required', 400);

        $model = new Branch();
        if (!$model->findById($id)) {
            Response::error('Branch not found', 404);
        }

        $settings = (new BranchSetting())->getByBranch(
            (int)$id,
            ['print_server_host', 'print_server_port']
        );

        Response::success('Print server settings retrieved', [
            'branch_id' => (int)$id,
            'print_server_host' => $settings['print_server_host'] ?? '',
            'print_server_port' => $settings['print_server_port'] ?? '',
        ]);
    }

    /**
     * PUT /api/branches/branch/print-server?id=X
     * Body: { "print_server_host": "192.168.1.50", "print_server_port": "9120" }
     * ตั้งค่า Print Server (เครื่องพิมพ์ความร้อน USB) ของสาขา
     * — แต่ละสาขามี Windows PC + เครื่องพิมพ์ของตัวเอง
     */
    public function updateBranchPrintServer($id)
    {
        $this->requireAuth(['admin']);
        if (!$id) Response::error('Branch ID is required', 400);

        $model = new Branch();
        if (!$model->findById($id)) {
            Response::error('Branch not found', 404);
        }

        $data = $this->getRequestData();
        $host = trim((string)($data['print_server_host'] ?? ''));
        $port = trim((string)($data['print_server_port'] ?? ''));

        // port ต้องเป็นตัวเลข 1-65535 ถ้าระบุ
        if ($port !== '') {
            if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                Response::error('print_server_port ต้องเป็นเลข 1-65535', 422);
            }
        }

        $settings = [];
        if ($host !== '') {
            $settings['print_server_host'] = $host;
        }
        if ($port !== '') {
            $settings['print_server_port'] = $port;
        }

        try {
            (new BranchSetting())->updateForBranch((int)$id, $settings);
            Logger::logActivity(
                $this->user['user_id'],
                'update_branch_print_server',
                "Updated print server for branch ID: {$id} → {$host}" . ($port !== '' ? ":{$port}" : '')
            );
            Response::success('Print server settings updated', $settings);
        } catch (Exception $e) {
            error_log('Branch print server update failed: ' . $e->getMessage());
            Response::error('Failed to update print server settings', 500);
        }
    }
}
