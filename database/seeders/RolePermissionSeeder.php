<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
            $security = Role::firstOrCreate(['name' => 'security', 'guard_name' => 'sanctum']);
            $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'sanctum']);
            $hr = Role::firstOrCreate(['name' => 'hr', 'guard_name' => 'sanctum']);
            $employee = Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'sanctum']);
            $finance = Role::firstOrCreate(['name' => 'finance', 'guard_name' => 'sanctum']);

            $employeeSpecific = [
                'attendance-my-attendances',
                'attendance-last-attendance',
                'attendance-check-in',
                'attendance-check-out',
                'leave-request-my-requests',
                'profile-menu',
                'team-view',
            ];

            // Full CTI permissions reserved for Admin & Security only
            $restrictedCtiPermissions = [
                'cti.brand-protection.view',
                'cti.email-breach.check-any',
                'audit-log.view',
                'mfa.reset-user',
                'cti-brand-scan',
                'cti-urlscan',
                'cti-breach-check',
            ];

            // 1. Admin: Full system & CTI access (only admin quick actions, not role-specific duplicate quick actions)
            $adminExcludedQuickActions = [
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
            $admin->syncPermissions($this->permissionsAllExcept($adminExcludedQuickActions));

            // 2. Security: Full CTI + Audit User + Personal Profile/Leave self-service (NO Attendance, NO HRIS management)
            $security->syncPermissions(
                Permission::whereIn('name', [
                    'cti.brand-protection.view',
                    'cti.email-breach.check-any',
                    'audit-log.view',
                    'cti-menu',
                    'cti-brand-scan',
                    'cti-urlscan',
                    'cti-breach-check',
                    // Self-service (Profile, Leave, Settings, Notifications — NO Attendance!)
                    'dashboard-menu',
                    'dashboard-view',
                    'profile-menu',
                    'profile-view',
                    'leave-request-menu',
                    'leave-request-create',
                    'leave-request-my-requests',
                    'setting-menu',
                    'setting-view',
                    'notification-list',
                    'notification-read',
                    // Security Quick Actions (6 shortcuts)
                    'quick-action.security.brand-scan',
                    'quick-action.security.suspicious-domain',
                    'quick-action.security.ssl-monitor',
                    'quick-action.security.breach-check-all',
                    'quick-action.security.audit-user',
                    'quick-action.security.failed-logins',
                ])->get()
            );

            // 3. Manager: All HRIS management permissions EXCEPT full CTI & Audit User; only check-own for email breach
            $allQuickActions = Permission::where('name', 'like', 'quick-action.%')->pluck('name')->all();
            $managerExcluded = array_merge($employeeSpecific, $restrictedCtiPermissions, $allQuickActions);
            $managerPermissions = $this->permissionsAllExcept($managerExcluded)
                ->pluck('name')
                ->push(
                    'cti-menu',
                    'cti.email-breach.check-own',
                    // Manager Quick Actions (6 shortcuts)
                    'quick-action.manager.team-view',
                    'quick-action.manager.leave-approval',
                    'quick-action.manager.project-view',
                    'quick-action.manager.attendance-team',
                    'quick-action.manager.report-team',
                    'quick-action.self.check-own-email'
                )
                ->unique()
                ->all();
            $manager->syncPermissions(Permission::whereIn('name', $managerPermissions)->get());

            // 4. HR: HRIS modules + check-own for email breach + 6 HR Quick Actions
            $hrPermissions = $this->permissionsByPrefixes([
                'dashboard-',
                'team-',
                'employee-',
                'project-',
                'task-',
                'attendance-',
                'leave-request-',
                'profile-',
                'report-',
                'setting-',
                'notification-',
            ], array_merge($employeeSpecific, $restrictedCtiPermissions))
                ->pluck('name')
                ->push(
                    'cti-menu',
                    'cti.email-breach.check-own',
                    // HR Quick Actions (6 shortcuts)
                    'quick-action.hr.employee-create',
                    'quick-action.hr.employee-list',
                    'quick-action.hr.leave-approval',
                    'quick-action.hr.attendance-recap',
                    'quick-action.hr.report-view',
                    'quick-action.self.check-own-email'
                )
                ->unique()
                ->all();
            $hr->syncPermissions(Permission::whereIn('name', $hrPermissions)->get());

            // 5. Finance: Payroll & Reports + Clock In/Out + check-own for email breach + 6 Finance Quick Actions
            $finance->syncPermissions(
                Permission::whereIn('name', [
                    'dashboard-menu',
                    'dashboard-view',
                    'profile-menu',
                    'profile-view',
                    'employee-menu',
                    'employee-list',
                    'attendance-menu',
                    'attendance-list',
                    'attendance-my-attendances',
                    'attendance-check-in',
                    'attendance-check-out',
                    'attendance-last-attendance',
                    'leave-request-menu',
                    'leave-request-list',
                    'payroll-menu',
                    'payroll-list',
                    'payroll-create',
                    'payroll-edit',
                    'payroll-delete',
                    'payroll-process',
                    'payroll-statistics',
                    'report-menu',
                    'report-view',
                    'report-export',
                    'setting-menu',
                    'setting-view',
                    'notification-list',
                    'notification-read',
                    'cti-menu',
                    'cti.email-breach.check-own',
                    // Finance Quick Actions (6 shortcuts)
                    'quick-action.finance.payroll-create',
                    'quick-action.finance.payroll-list',
                    'quick-action.finance.payroll-export',
                    'quick-action.finance.payroll-report',
                    'quick-action.self.clock-in-out',
                    'quick-action.self.check-own-email',
                ])->get()
            );

            // 6. Employee: Self-service + check-own for email breach + 6 Employee Quick Actions
            $employee->syncPermissions(
                Permission::whereIn('name', [
                    'dashboard-menu',
                    'dashboard-view',
                    'profile-menu',
                    'profile-view',
                    'employee-list',
                    'team-view',
                    'attendance-my-attendances',
                    'attendance-check-in',
                    'attendance-check-out',
                    'attendance-last-attendance',
                    'leave-request-menu',
                    'leave-request-create',
                    'leave-request-my-requests',
                    'project-menu',
                    'project-list',
                    'task-menu',
                    'task-create',
                    'task-list',
                    'task-edit',
                    'setting-menu',
                    'setting-view',
                    'notification-list',
                    'notification-read',
                    'cti-menu',
                    'cti.email-breach.check-own',
                    // Employee Quick Actions (6 shortcuts)
                    'quick-action.self.clock-in-out',
                    'quick-action.self.leave-create',
                    'quick-action.self.attendance-history',
                    'quick-action.self.payslips',
                    'quick-action.self.profile',
                    'quick-action.self.check-own-email',
                ])->get()
            );
        });
    }

    private function permissionsAllExcept(array $except): Collection
    {
        return Permission::whereNotIn('name', $except)->get();
    }

    private function permissionsByPrefixes(array $prefixes, array $except = []): Collection
    {
        return Permission::where(function ($q) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $q->orWhere('name', 'like', $prefix . '%');
            }
        })->when(! empty($except), function ($q) use ($except) {
            $q->whereNotIn('name', $except);
        })->get();
    }
}
