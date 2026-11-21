<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\AttendanceCheckInRequest;
use App\Http\Requests\AttendanceCheckOutRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\PaginateResource;
use App\Interfaces\AttendanceRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

class AttendanceController extends Controller implements HasMiddleware
{
    private AttendanceRepositoryInterface $attendanceRepository;

    public function __construct(AttendanceRepositoryInterface $attendanceRepository)
    {
        $this->attendanceRepository = $attendanceRepository;
    }

    public static function middleware()
    {
        return [
            new Middleware(PermissionMiddleware::using(['attendance-list']), only: ['index', 'getAllPaginated', 'show', 'store', 'update', 'destroy', 'getStatistics']),
            new Middleware(PermissionMiddleware::using(['attendance-check-in']), only: ['checkIn']),
            new Middleware(PermissionMiddleware::using(['attendance-check-out']), only: ['checkOut']),
            new Middleware(PermissionMiddleware::using(['attendance-last-attendance']), only: ['getLastAttendance']),
            new Middleware(PermissionMiddleware::using(['attendance-my-attendances']), only: ['getMyAttendances', 'getMyAttendanceStatistics']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $attendances = $this->attendanceRepository->getAll(
                $request->search,
                $request->date,
                $request->limit,
                true
            );

            return ResponseHelper::jsonResponse(true, 'Attendances Retrieved Successfully', AttendanceResource::collection($attendances), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function getAllPaginated(Request $request)
    {
        $request = $request->validate([
            'search' => 'nullable|string',
            'row_per_page' => 'required|integer',
        ]);

        try {
            $attendances = $this->attendanceRepository->getAllPaginated(
                $request['search'] ?? null,
                $request['row_per_page']
            );

            return ResponseHelper::jsonResponse(true, 'Attendances Retrieved Successfully', PaginateResource::make($attendances, AttendanceResource::class), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function getMyAttendances()
    {
        try {
            $attendances = $this->attendanceRepository->getMyAttendances();

            return ResponseHelper::jsonResponse(true, 'My Attendances Retrieved Successfully', AttendanceResource::collection($attendances), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function getMyAttendanceStatistics()
    {
        try {
            $statistics = $this->attendanceRepository->getMyAttendanceStatistics();

            return ResponseHelper::jsonResponse(true, 'Attendance Statistics Retrieved Successfully', $statistics, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function getLastAttendance()
    {
        try {
            $attendance = $this->attendanceRepository->getLastAttendanceByEmployee();

            if (! $attendance) {
                return ResponseHelper::jsonResponse(false, 'No attendance data for today', null, 404);
            }

            return ResponseHelper::jsonResponse(true, 'Last Attendance Retrieved Successfully', new AttendanceResource($attendance), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function checkIn(AttendanceCheckInRequest $request)
    {
        $data = $request->validated();

        try {
            $attendance = $this->attendanceRepository->checkIn($data);

            return ResponseHelper::jsonResponse(true, 'Check-in Successful', new AttendanceResource($attendance), 201);
        } catch (\Exception $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, 400);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function checkOut(AttendanceCheckOutRequest $request)
    {
        $data = $request->validated();

        try {
            $attendance = $this->attendanceRepository->checkOut($data);

            return ResponseHelper::jsonResponse(true, 'Check-out Successful', new AttendanceResource($attendance), 200);
        } catch (\Exception $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, 400);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Get attendance statistics for admin
     */
    public function getStatistics()
    {
        try {
            $statistics = $this->attendanceRepository->getStatistics();

            return ResponseHelper::jsonResponse(true, 'Attendance Statistics Retrieved Successfully', $statistics, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $attendance = $this->attendanceRepository->getById($id);

            return ResponseHelper::jsonResponse(true, 'Attendance Retrieved Successfully', new AttendanceResource($attendance), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Attendance Not Found', null, 404);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }
}
