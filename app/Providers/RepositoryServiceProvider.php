<?php

namespace App\Providers;

use App\Interfaces\AttendanceRepositoryInterface;
use App\Interfaces\AuthRepositoryInterface;
use App\Interfaces\BankInformationRepositoryInterface;
use App\Interfaces\BrandScanRepositoryInterface;
use App\Interfaces\BreachCheckRepositoryInterface;
use App\Interfaces\DashboardRepositoryInterface;
use App\Interfaces\EmergencyContactRepositoryInterface;
use App\Interfaces\EmployeeProfileRepositoryInterface;
use App\Interfaces\JobInformationRepositoryInterface;
use App\Interfaces\LeaveRequestRepositoryInterface;
use App\Interfaces\NotificationRepositoryInterface;
use App\Interfaces\PayrollRepositoryInterface;
use App\Interfaces\PermissionRepositoryInterface;
use App\Interfaces\ProjectRepositoryInterface;
use App\Interfaces\ProjectTaskRepositoryInterface;
use App\Interfaces\ReportRepositoryInterface;
use App\Interfaces\RoleRepositoryInterface;
use App\Interfaces\SettingRepositoryInterface;
use App\Interfaces\TeamRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Repositories\AttendanceRepository;
use App\Repositories\AuthRepository;
use App\Repositories\BankInformationRepository;
use App\Repositories\BrandScanRepository;
use App\Repositories\BreachCheckRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\EmergencyContactRepository;
use App\Repositories\EmployeeProfileRepository;
use App\Repositories\JobInformationRepository;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PayrollRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectTaskRepository;
use App\Repositories\ReportRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(AuthRepositoryInterface::class, AuthRepository::class);
        $this->app->bind(TeamRepositoryInterface::class, TeamRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(JobInformationRepositoryInterface::class, JobInformationRepository::class);
        $this->app->bind(BankInformationRepositoryInterface::class, BankInformationRepository::class);
        $this->app->bind(EmergencyContactRepositoryInterface::class, EmergencyContactRepository::class);
        $this->app->bind(EmployeeProfileRepositoryInterface::class, EmployeeProfileRepository::class);
        $this->app->bind(ProjectRepositoryInterface::class, ProjectRepository::class);
        $this->app->bind(ProjectTaskRepositoryInterface::class, ProjectTaskRepository::class);
        $this->app->bind(AttendanceRepositoryInterface::class, AttendanceRepository::class);
        $this->app->bind(LeaveRequestRepositoryInterface::class, LeaveRequestRepository::class);
        $this->app->bind(DashboardRepositoryInterface::class, DashboardRepository::class);
        $this->app->bind(PayrollRepositoryInterface::class, PayrollRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
        $this->app->bind(BreachCheckRepositoryInterface::class, BreachCheckRepository::class);
        $this->app->bind(BrandScanRepositoryInterface::class, BrandScanRepository::class);
        $this->app->bind(ReportRepositoryInterface::class, ReportRepository::class);
        $this->app->bind(SettingRepositoryInterface::class, SettingRepository::class);
        $this->app->bind(NotificationRepositoryInterface::class, NotificationRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
