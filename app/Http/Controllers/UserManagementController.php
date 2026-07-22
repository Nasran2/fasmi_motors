<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    // ─── LOGGING HELPER ─────────────────────────────────────────

    protected function log(string $module, string $action, string $description, ?object $record = null, ?array $old = null, ?array $new = null)
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'module' => $module,
            'action' => $action,
            'description' => $description,
            'reference_type' => $record ? get_class($record) : null,
            'reference_id' => $record ? $record->id : null,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    // ─── 1. USERS LIST ──────────────────────────────────────────

    public function index(Request $r)
    {
        $q = User::with('role');

        if ($r->search) {
            $q->where(fn ($x) => $x->where('name', 'like', "%{$r->search}%")
                ->orWhere('username', 'like', "%{$r->search}%")
                ->orWhere('email', 'like', "%{$r->search}%")
                ->orWhere('phone', 'like', "%{$r->search}%"));
        }

        if ($r->role_id) {
            $q->where('role_id', $r->role_id);
        }

        if ($r->status) {
            if ($r->status === 'active') {
                $q->where('active', 1);
            }
            if ($r->status === 'inactive') {
                $q->where('active', 0);
            }
            if ($r->status === 'locked') {
                $q->whereNotNull('locked_until')->where('locked_until', '>', now());
            }
        }

        if ($r->quick === 'online') {
            $q->where('last_login_at', '>', now()->subMinutes(15));
        } elseif ($r->quick === 'never') {
            $q->whereNull('last_login_at');
        }

        $rows = $q->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => User::count(),
            'active' => User::where('active', 1)->count(),
            'inactive' => User::where('active', 0)->count(),
            'online' => User::where('last_login_at', '>', now()->subMinutes(15))->count(),
            'admins' => User::whereHas('role', fn ($rq) => $rq->where('name', 'Super Admin')->orWhere('name', 'Admin'))->count(),
            'locked' => User::whereNotNull('locked_until')->where('locked_until', '>', now())->count(),
        ];

        $roles = Role::orderBy('name')->get();

        return view('user-management.users.index', compact('rows', 'stats', 'roles'));
    }

    // ─── 2. ADD USER ────────────────────────────────────────────

    public function create()
    {
        $roles = Role::orderBy('name')->get();

        return view('user-management.users.create', compact('roles'));
    }

    public function store(Request $r)
    {
        $r->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'exists:roles,id'],
            'store_access' => ['nullable', 'string'],
        ]);

        $user = User::create([
            'name' => $r->name,
            'username' => $r->username,
            'email' => $r->email,
            'phone' => $r->phone,
            'password' => $r->password,
            'role_id' => $r->role_id,
            'active' => $r->active ?? 1,
            'store_access' => $r->store_access ?? 'All Stores',
            'force_password_change' => $r->boolean('force_password_change'),
            'created_by' => auth()->id(),
        ]);

        $this->log('User Management', 'Created', "Created system user: {$user->name} ({$user->username})", $user, null, $user->toArray());

        return redirect()->route('user-management.users.index')->with('success', 'User account created successfully.');
    }

    // ─── 3. EDIT USER ───────────────────────────────────────────

    public function edit(User $user)
    {
        $roles = Role::orderBy('name')->get();

        return view('user-management.users.edit', compact('user', 'roles'));
    }

    public function update(Request $r, User $user)
    {
        $r->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'exists:roles,id'],
            'store_access' => ['nullable', 'string'],
        ]);

        $old = $user->toArray();

        $user->fill([
            'name' => $r->name,
            'username' => $r->username,
            'email' => $r->email,
            'phone' => $r->phone,
            'role_id' => $r->role_id,
            'active' => $r->active ?? 1,
            'store_access' => $r->store_access ?? 'All Stores',
            'force_password_change' => $r->boolean('force_password_change'),
            'updated_by' => auth()->id(),
        ]);

        if ($r->filled('password')) {
            $r->validate([
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
            $user->password = $r->password;
        }

        $user->save();

        $this->log('User Management', 'Updated', "Updated system user: {$user->name} ({$user->username})", $user, $old, $user->toArray());

        return redirect()->route('user-management.users.index')->with('success', 'User account updated successfully.');
    }

    // ─── RESET PASSWORD ──────────────────────────────────────────

    public function resetPassword(Request $r, User $user)
    {
        $r->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => $r->password,
            'force_password_change' => true,
        ]);

        $this->log('User Management', 'Password Reset', "Reset password for user: {$user->username}", $user);

        return back()->with('success', 'User password has been reset successfully. Force change password is enabled.');
    }

    // ─── TOGGLE STATUS ──────────────────────────────────────────

    public function toggleStatus(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($user->role?->name === 'Super Admin' && User::whereHas('role', fn ($q) => $q->where('name', 'Super Admin'))->where('active', 1)->count() <= 1 && $user->active) {
            return back()->with('error', 'The system must have at least one active Super Admin.');
        }

        $oldActive = $user->active;
        $user->update(['active' => ! $user->active]);

        $statusStr = $user->active ? 'Activated' : 'Deactivated';
        $this->log('User Management', $statusStr, "{$statusStr} user: {$user->username}", $user, ['active' => $oldActive], ['active' => $user->active]);

        return back()->with('success', "User account {$statusStr} successfully.");
    }

    // ─── DELETE USER ────────────────────────────────────────────

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->role?->name === 'Super Admin') {
            return back()->with('error', 'Super Admin account cannot be deleted.');
        }

        $user->delete();

        $this->log('User Management', 'Deleted', "Deleted user account: {$user->username}", $user);

        return redirect()->route('user-management.users.index')->with('success', 'User account deleted successfully.');
    }

    // ─── 4. ROLES ───────────────────────────────────────────────

    public function roles()
    {
        $rows = Role::withCount('users')->get();
        $stats = [
            'total' => Role::count(),
            'active' => Role::count(), // Default all active
            'assigned' => User::distinct('role_id')->count(),
            'custom' => Role::whereNotIn('name', ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Technician', 'Accountant'])->count(),
        ];

        return view('user-management.roles.index', compact('rows', 'stats'));
    }

    public function storeRole(Request $r)
    {
        $r->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles'],
            'description' => ['nullable', 'string'],
        ]);

        $role = Role::create([
            'name' => $r->name,
            'description' => $r->description,
            'permissions' => [],
        ]);

        $this->log('User Management', 'Created Role', "Created role: {$role->name}", $role);

        return back()->with('success', 'Role created successfully.');
    }

    public function updateRole(Request $r, Role $role)
    {
        if ($role->name === 'Super Admin') {
            return back()->with('error', 'Super Admin role details cannot be edited.');
        }

        $r->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles')->ignore($role->id)],
            'description' => ['nullable', 'string'],
        ]);

        $old = $role->toArray();
        $role->update([
            'name' => $r->name,
            'description' => $r->description,
        ]);

        $this->log('User Management', 'Updated Role', "Updated role: {$role->name}", $role, $old, $role->toArray());

        return back()->with('success', 'Role updated successfully.');
    }

    public function destroyRole(Role $role)
    {
        if (in_array($role->name, ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Technician', 'Accountant'])) {
            return back()->with('error', 'Default system roles cannot be deleted.');
        }

        if ($role->users()->count() > 0) {
            return back()->with('error', 'This role has assigned users and cannot be deleted.');
        }

        $role->delete();

        $this->log('User Management', 'Deleted Role', "Deleted role: {$role->name}", $role);

        return back()->with('success', 'Role deleted successfully.');
    }

    // ─── 5. PERMISSIONS MATRIX ──────────────────────────────────

    public function permissions(Request $r)
    {
        $roles = Role::orderBy('name')->get();
        $selectedRoleId = $r->input('role_id', $roles->first()?->id);
        $selectedRole = Role::find($selectedRoleId);

        // Defined permissions array grouped by modules
        $permissionGroups = [
            'Dashboard' => [
                'dashboard.view' => 'View Dashboard',
            ],
            'Bike Intake' => [
                'intake.view' => 'View Bike Intake',
                'intake.add' => 'Add Bike Intake',
                'intake.edit' => 'Edit Bike Intake',
                'intake.delete' => 'Delete Bike Intake',
                'intake.due_records' => 'View Due Records',
                'intake.create_job_card' => 'Create Job Card From Intake',
            ],
            'Job Cards' => [
                'job_card.view' => 'View Job Cards',
                'job_card.create' => 'Create Job Card',
                'job_card.edit' => 'Edit Job Card',
                'job_card.assign_technician' => 'Assign Technician',
                'job_card.change_status' => 'Change Status',
                'job_card.add_parts' => 'Add Parts',
                'job_card.add_services' => 'Add Service Charge',
                'job_card.complete' => 'Complete Job',
                'job_card.cancel' => 'Cancel Job',
                'job_card.print' => 'Print Job Card',
            ],
            'Customers & Bikes' => [
                'customer.view' => 'View Customers',
                'customer.add' => 'Add Customer',
                'customer.edit' => 'Edit Customer',
                'customer.delete' => 'Delete Customer',
                'bike.view' => 'View Bikes',
                'bike.add' => 'Add Bike',
                'bike.edit' => 'Edit Bike',
                'bike.change_ownership' => 'Change Bike Ownership',
                'customer.view_due' => 'View Customer Due',
            ],
            'Products / Stock' => [
                'product.view' => 'View Products',
                'product.add' => 'Add Product',
                'product.edit' => 'Edit Product',
                'product.delete' => 'Delete Product',
                'product.import' => 'Import Products',
                'product.barcode' => 'Barcode Print',
                'product.categories' => 'Manage Categories',
                'product.brands' => 'Manage Brands',
                'product.units' => 'Manage Units',
                'product.stores' => 'Manage Stores',
                'product.view_stock' => 'View Store Stock',
                'product.transfer' => 'Stock Transfer',
                'product.adjustment' => 'Stock Adjustment',
                'product.damage' => 'Damage / Write-off',
                'product.view_low_stock' => 'View Low Stock',
            ],
            'Purchases' => [
                'purchase.view' => 'View Purchases',
                'purchase.add' => 'Add Purchase',
                'purchase.edit' => 'Edit Purchase',
                'purchase.delete' => 'Delete Purchase',
                'purchase.return' => 'Purchase Return',
                'purchase.suppliers' => 'Supplier Management',
                'purchase.supplier_payments' => 'Supplier Payment',
                'purchase.view_due' => 'View Purchase Due',
            ],
            'Sales / Billing' => [
                'sales.job_card_billing' => 'Job Card Billing',
                'sales.parts_sales' => 'Parts Sales POS',
                'sales.view_invoices' => 'View Invoices',
                'sales.receive_payments' => 'Receive Payments',
                'sales.due_bills' => 'Manage Due Bills',
                'sales.customer_payments' => 'Customer Payments',
                'sales.public_links' => 'Public Bill Links',
                'sales.cancel_invoice' => 'Cancel Invoice',
                'sales.apply_discount' => 'Apply Discount',
            ],
            'Technicians' => [
                'technician.view' => 'View Technicians',
                'technician.add' => 'Add Technician',
                'technician.edit' => 'Edit Technician',
                'technician.attendance' => 'Attendance',
                'technician.salaries' => 'Salary Payments',
                'technician.advances' => 'Advance Payments',
                'technician.commission' => 'Commission',
                'technician.bike_deductions' => 'Own Bike Deductions',
                'technician.epf_etf' => 'EPF / ETF Records',
                'technician.history' => 'Technician History',
            ],
            'Accounts' => [
                'account.view_main' => 'View Main Account Ledger',
                'account.view_spare_parts' => 'View Spare Parts Ledger',
                'account.view_bank' => 'View Bank Account Ledger',
                'account.view_cash_book' => 'View Cash Book',
                'account.transfers' => 'Account Transfers',
                'account.daily_closing' => 'Daily Closing',
                'account.cheques' => 'Cheque Payments',
                'account.bank_payments' => 'Card / Bank Payments',
                'account.opening_balances' => 'Opening Balances',
                'account.edit_ledger' => 'Edit Ledger',
                'account.adjustment' => 'Add Adjustment',
                'account.deduction' => 'Add Deduction',
                'account.reopen_closing' => 'Reopen Daily Closing',
            ],
            'Expenses & Drawings' => [
                'expense.view' => 'View Expenses',
                'expense.add' => 'Add Expense',
                'expense.edit' => 'Edit Expense',
                'expense.cancel' => 'Cancel Expense',
                'expense.categories' => 'Expense Categories',
                'drawing.view' => 'View Drawings',
                'drawing.add' => 'Add Drawing',
                'drawing.edit' => 'Edit Drawing',
                'drawing.cancel' => 'Cancel Drawing',
                'drawing.categories' => 'Drawing Categories',
            ],
            'Service Reminders' => [
                'reminder.view' => 'View Reminders',
                'reminder.send' => 'Send Reminder SMS',
                'reminder.edit' => 'Edit Reminder',
                'reminder.reschedule' => 'Reschedule Reminder',
                'reminder.settings' => 'Reminder Settings',
            ],
            'SMS Center' => [
                'sms.view' => 'View SMS Dashboard',
                'sms.send' => 'Send SMS',
                'sms.templates' => 'Manage SMS Templates',
                'sms.logs' => 'View SMS Logs',
                'sms.retry' => 'Retry Failed SMS',
                'sms.settings' => 'SMS Provider Settings',
            ],
            'Reports' => [
                'reports.view' => 'View Reports',
                'reports.export_pdf' => 'Export PDF',
                'reports.export_excel' => 'Export Excel',
                'reports.print' => 'Print Reports',
            ],
            'Settings' => [
                'settings.general' => 'General Settings',
                'settings.profile' => 'Business Profile',
                'settings.invoice' => 'Invoice Settings',
                'settings.job_card' => 'Job Card Settings',
                'settings.accounts' => 'Account Settings',
                'settings.stock' => 'Stock Settings',
                'settings.technicians' => 'Technician Settings',
                'settings.salary' => 'Salary Settings',
                'settings.epf_etf' => 'EPF / ETF Settings',
                'settings.sms' => 'SMS Settings',
                'settings.reminder' => 'Service Reminder Settings',
                'settings.payment_methods' => 'Payment Method Settings',
                'settings.backup' => 'Backup Settings',
                'settings.security' => 'Security Settings',
            ],
            'User Management' => [
                'users.view' => 'View Users',
                'users.add' => 'Add User',
                'users.edit' => 'Edit User',
                'users.delete' => 'Delete User',
                'users.roles' => 'Manage Roles',
                'users.permissions' => 'Manage Permissions',
                'users.activity_logs' => 'View Activity Logs',
                'users.login_history' => 'View Login History',
            ],
        ];

        return view('user-management.permissions.index', compact('roles', 'selectedRole', 'permissionGroups'));
    }

    public function updatePermissions(Request $r)
    {
        $role = Role::findOrFail($r->role_id);
        if ($role->name === 'Super Admin') {
            return back()->with('error', 'Super Admin role always has full permissions.');
        }

        $oldPerms = $role->permissions ?? [];
        $newPerms = $r->input('permissions', []);

        $role->update(['permissions' => $newPerms]);

        $this->log('User Management', 'Permissions Changed', "Changed permissions for role: {$role->name}", $role, ['permissions' => $oldPerms], ['permissions' => $newPerms]);

        return back()->with('success', 'Role permissions updated successfully.');
    }

    // ─── 6. ACTIVITY LOGS ───────────────────────────────────────

    public function activityLogs(Request $r)
    {
        $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $q = ActivityLog::with('user');

        if ($r->search) {
            $q->where(fn ($x) => $x->where('description', 'like', "%{$r->search}%")
                ->orWhere('action', 'like', "%{$r->search}%")
                ->orWhere('module', 'like', "%{$r->search}%")
                ->orWhere('ip_address', 'like', "%{$r->search}%"));
        }

        if ($r->user_id) {
            $q->where('user_id', $r->user_id);
        }

        if ($r->module_name) {
            $q->where('module', $r->module_name);
        }

        if ($r->action_type) {
            $q->where('action', $r->action_type);
        }

        // Date filter
        $from = $r->from ? Carbon::parse($r->from)->startOfDay() : today()->startOfDay();
        $to = $r->to ? Carbon::parse($r->to)->endOfDay() : today()->endOfDay();
        $q->whereBetween('created_at', [$from, $to]);

        $rows = $q->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => ActivityLog::count(),
            'today' => ActivityLog::whereBetween('created_at', [today()->startOfDay(), today()->endOfDay()])->count(),
            'financial' => ActivityLog::whereIn('module', ['Accounts', 'Sales / Billing', 'Purchases', 'Expenses & Drawings'])->count(),
            'user_changes' => ActivityLog::where('module', 'User Management')->count(),
            'security' => ActivityLog::whereIn('action', ['Password Reset', 'Permissions Changed', 'Locked', 'Failed', 'Deactivated'])->count(),
        ];

        $users = User::orderBy('name')->get();
        $modules = ActivityLog::select('module')->distinct()->pluck('module');
        $actions = ActivityLog::select('action')->distinct()->pluck('action');

        return view('user-management.activity-logs.index', compact('rows', 'stats', 'users', 'modules', 'actions', 'from', 'to'));
    }

    // ─── 7. LOGIN HISTORY ───────────────────────────────────────

    public function loginHistory(Request $r)
    {
        $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $q = LoginHistory::with('user');

        if ($r->search) {
            $q->where(fn ($x) => $x->where('username_or_email', 'like', "%{$r->search}%")
                ->orWhere('ip_address', 'like', "%{$r->search}%")
                ->orWhere('browser', 'like', "%{$r->search}%")
                ->orWhere('device', 'like', "%{$r->search}%"));
        }

        if ($r->status) {
            $q->where('status', $r->status);
        }

        // Date filter
        $from = $r->from ? Carbon::parse($r->from)->startOfDay() : today()->startOfDay();
        $to = $r->to ? Carbon::parse($r->to)->endOfDay() : today()->endOfDay();
        $q->whereBetween('logged_in_at', [$from, $to]);

        $rows = $q->latest('logged_in_at')->paginate(25)->withQueryString();

        $stats = [
            'success' => LoginHistory::where('status', 'Success')->count(),
            'failed' => LoginHistory::where('status', 'Failed')->count(),
            'locked' => LoginHistory::where('status', 'Locked')->count(),
            'active_sessions' => User::where('last_login_at', '>', now()->subMinutes(15))->count(),
            'today' => LoginHistory::whereBetween('logged_in_at', [today()->startOfDay(), today()->endOfDay()])->count(),
        ];

        return view('user-management.login-history.index', compact('rows', 'stats', 'from', 'to'));
    }
}
