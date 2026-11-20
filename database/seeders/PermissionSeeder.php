<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    private $permissions = [
        'dashboard' => [
            'menu',
            'view',
        ],

        'profile' => [
            'menu',
            'view',
        ],

        'team' => [
            'menu',
            'list',
            'create',
            'edit',
            'delete',
            'view',
        ],

        'employee' => [
            'menu',
            'list',
            'create',
            'edit',
            'delete',
        ],

        'project' => [
            'menu',
            'statistic',
            'list',
            'create',
            'edit',
            'delete',
        ],

        'task' => [
            'menu',
            'list',
            'create',
            'edit',
            'delete',
        ],

        'attendance' => [
            'menu',
            'list',
            'my-attendances',
            'my-statistics',
            'check-in',
            'check-out',
            'last-attendance',
        ],

        'leave-request' => [
            'menu',
            'list',
            'create',
            'approve',
            'my-requests',
        ],

        'payroll' => [
            'menu',
            'list',
            'create',
            'edit',
            'delete',
            'process',
            'statistics',
        ],

        'cti' => [
            'menu',
            'breach-check',
            'brand-scan',
            'urlscan',
        ],

        'report' => [
            'menu',
            'view',
            'export',
        ],

        'setting' => [
            'menu',
            'view',
            'edit',
        ],

        'notification' => [
            'list',
            'read',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->permissions as $key => $value) {
            foreach ($value as $permission) {
                Permission::firstOrCreate([
                    'name' => $key.'-'.$permission,
                    'guard_name' => 'sanctum',
                ]);
            }
        }

        // Granular CTI & Audit Log permissions
        $granularPermissions = [
            'cti.brand-protection.view',
            'cti.email-breach.check-any',
            'cti.email-breach.check-own',
            'audit-log.view',
            // Role-scoped Quick Action permissions
            'quick-action.admin.all',
            'quick-action.manager.team-view',
            'quick-action.manager.leave-approval',
            'quick-action.manager.project-view',
            'quick-action.manager.attendance-team',
            'quick-action.manager.report-team',
            'quick-action.hr.employee-create',
            'quick-action.hr.employee-list',
            'quick-action.hr.leave-approval',
            'quick-action.hr.attendance-recap',
            'quick-action.hr.report-view',
            'quick-action.finance.payroll-create',
            'quick-action.finance.payroll-list',
            'quick-action.finance.payroll-export',
            'quick-action.finance.payroll-report',
            'quick-action.self.clock-in-out',
            'quick-action.self.leave-create',
            'quick-action.self.attendance-history',
            'quick-action.self.payslips',
            'quick-action.self.profile',
            'quick-action.self.check-own-email',
            'quick-action.security.brand-scan',
            'quick-action.security.suspicious-domain',
            'quick-action.security.ssl-monitor',
            'quick-action.security.breach-check-all',
            'quick-action.security.audit-user',
            'quick-action.security.failed-logins',
        ];

        foreach ($granularPermissions as $permName) {
            Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => 'sanctum',
            ]);
        }
    }
}
