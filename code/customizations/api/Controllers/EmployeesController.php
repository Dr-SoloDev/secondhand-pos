<?php
class EmployeesController extends Controller
{
    private function getScopedBranchId()
    {
        if (($this->user['role'] ?? '') !== 'admin') {
            $branchId = intval($this->user['branch_id'] ?? 0);
            if (!$branchId) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
            return $branchId;
        }

        return null;
    }

    private function assertEmployeeBranchAccess($employee)
    {
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->getScopedBranchId();
            if (!$employee || (int)($employee['branch_id'] ?? 0) !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์เข้าถึงข้อมูลพนักงานสาขานี้', 403);
            }
        }
    }

    public function index()
    {
        $this->requireAuth(['admin', 'manager']);
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 20;
        $filters = [
            'branch_id' => isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null,
            'status' => isset($_GET['status']) ? $this->sanitizeInput($_GET['status']) : null,
            'search' => isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null,
        ];

        $userBranch = $this->getScopedBranchId();
        if ($userBranch !== null) {
            $filters['branch_id'] = $userBranch;
        }

        $model = new Employee();
        $result = $model->getAll($page, $limit, $filters);
        Response::success('Employees retrieved successfully', array_merge($result, ['filters' => $filters]));
    }

    public function show()
    {
        $this->requireAuth(['admin', 'manager']);
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        if (!$id) {
            Response::error('Missing employee ID', 400);
            return;
        }

        $model = new Employee();
        $employee = $model->getById($id);
        if (!$employee) {
            Response::error('Employee not found', 404);
            return;
        }
        $this->assertEmployeeBranchAccess($employee);
        Response::success('Employee retrieved successfully', $employee);
    }

    public function store()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        $userBranch = $this->getScopedBranchId();
        if ($userBranch !== null) {
            $data['branch_id'] = $userBranch;
        }
        $this->validateRequiredFields($data, ['branch_id', 'full_name']);

        $data = $this->sanitizeInput($data);

        $model = new Employee();
        $id = $model->create($data);
        if (!$id) {
            Response::error('Failed to create employee', 500);
            return;
        }

        $employee = $model->getById($id);
        Response::success('Employee created successfully', $employee);
    }

    public function update()
    {
        $this->requireAuth(['admin', 'manager']);
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        if (!$id) {
            Response::error('Missing employee ID', 400);
            return;
        }

        $data = $this->getRequestData();
        $data = $this->sanitizeInput($data);
        $userBranch = $this->getScopedBranchId();
        if ($userBranch !== null) {
            $data['branch_id'] = $userBranch;
        }

        $model = new Employee();
        $employee = $model->getById($id);
        if (!$employee) {
            Response::error('Employee not found', 404);
            return;
        }
        $this->assertEmployeeBranchAccess($employee);
        $model->update($id, $data);

        $employee = $model->getById($id);
        Response::success('Employee updated successfully', $employee);
    }

    public function destroy()
    {
        $this->requireAuth(['admin']);
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        if (!$id) {
            Response::error('Missing employee I requireAuthD', 400);
            return;
        }

        $model = new Employee();
        $employee = $model->getById($id);
        if (!$employee) {
            Response::error('Employee not found', 404);
            return;
        }
        $this->assertEmployeeBranchAccess($employee);
        $model->delete($id);
        Response::success('Employee deleted successfully');
    }

    public function createSalaryExpense()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['employee_id', 'salary_date', 'amount']);

        $model = new Employee();
        $employee = $model->getById($data['employee_id']);
        if (!$employee) {
            Response::error('Employee not found', 404);
            return;
        }
        $this->assertEmployeeBranchAccess($employee);

        $expenseId = $model->autoCreateSalaryExpense(
            $data['employee_id'],
            $data['salary_date'],
            $data['amount']
        );

        if (!$expenseId) {
            Response::error('Failed to create salary expense', 500);
            return;
        }

        Response::success('Salary expense created successfully', ['expense_id' => $expenseId]);
    }

    public function createSSOExpense()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['employee_id', 'salary_date', 'amount']);

        $model = new Employee();
        $employee = $model->getById($data['employee_id']);
        if (!$employee) {
            Response::error('Employee not found', 404);
            return;
        }
        $this->assertEmployeeBranchAccess($employee);

        $expenseId = $model->autoCreateSSOExpense(
            $data['employee_id'],
            $data['salary_date'],
            $data['amount']
        );

        if (!$expenseId) {
            Response::error('Failed to create SSO expense', 500);
            return;
        }

        Response::success('SSO expense created successfully', ['expense_id' => $expenseId]);
    }
}
