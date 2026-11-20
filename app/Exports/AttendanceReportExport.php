<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private array $rows;

    private int $rowNumber = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function headings(): array
    {
        return [
            'No',
            'Employee ID',
            'Employee Name',
            'Email',
            'Team',
            'Department',
            'Job Title',
            'Present',
            'Late',
            'Leave / Permission',
            'Sick',
            'Absent',
            'Total Days',
            'Attendance Rate (%)',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row['employee_code'] ?? '-',
            $row['name'] ?? '-',
            $row['email'] ?? '-',
            $row['team_name'] ?? '-',
            $row['department'] ?? '-',
            $row['job_title'] ?? '-',
            $row['present'] ?? 0,
            $row['late'] ?? 0,
            $row['permission'] ?? 0,
            $row['sick'] ?? 0,
            $row['absent'] ?? 0,
            $row['total_records'] ?? 0,
            ($row['attendance_rate'] ?? 0) . '%',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 11,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0C51D9'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Attendance Report';
    }
}
