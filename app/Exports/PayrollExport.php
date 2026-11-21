<?php

namespace App\Exports;

use App\Models\Payroll;
use App\Models\PayrollDetail;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PayrollExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected $payrollId;

    protected $payroll;

    protected $rowNumber = 0;

    public function __construct($payrollId)
    {
        $this->payrollId = $payrollId;
        $this->payroll = Payroll::findOrFail($payrollId);
    }

    /**
     * Get the collection of payroll details
     */
    public function collection()
    {
        return PayrollDetail::where('payroll_id', $this->payrollId)
            ->with([
                'employee.user',
                'employee.jobInformation.team',
                'employee.bankInformation',
            ])
            ->orderBy('final_salary', 'desc')
            ->get();
    }

    /**
     * Define the headings for the Excel file
     */
    public function headings(): array
    {
        return [
            'No',
            'Employee Name',
            'Employee ID',
            'Job Title',
            'Department',
            'Bank',
            'Account Number',
            'Account Holder Name',
            'Working Days',
            'Present',
            'Sick',
            'Absent',
            'Base Salary',
            'Deductions',
            'Net Salary',
            'Status',
            'Notes',
        ];
    }

    /**
     * Map each row to the desired format
     */
    public function map($detail): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $detail->employee->user->name ?? 'N/A',
            $detail->employee->code ?? $detail->employee->id,
            $detail->employee->jobInformation->job_title ?? 'N/A',
            $detail->employee->jobInformation->team->name ?? 'N/A',
            $detail->employee->bankInformation->bank_name ?? 'N/A',
            $detail->employee->bankInformation->account_number ?? 'N/A',
            $detail->employee->bankInformation->account_holder_name ?? 'N/A',
            $detail->working_days ?: 22,
            $detail->attended_days ?? 0,
            $detail->sick_days ?? 0,
            $detail->absent_days ?? 0,
            $detail->original_salary ?? 0,
            ($detail->original_salary - $detail->final_salary) ?? 0,
            $detail->final_salary ?? 0,
            $this->payroll->status === 'paid' ? 'Paid' : 'Pending',
            $detail->notes ?? '',
        ];
    }

    /**
     * Apply styles to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Style the header row
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 12,
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

    /**
     * Define the sheet title
     */
    public function title(): string
    {
        $month = \Carbon\Carbon::parse($this->payroll->salary_month)->format('F Y');

        return "Payroll {$month}";
    }

    /**
     * Register events for additional styling
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Add borders to all cells
                $sheet->getStyle("A1:{$highestColumn}{$highestRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);

                // Center align specific columns
                $sheet->getStyle("A2:A{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // No
                $sheet->getStyle("I2:L{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Attendance columns
                $sheet->getStyle("P2:P{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Status

                // Right align currency columns
                $sheet->getStyle("M2:O{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT); // Salary columns

                // Format currency columns
                $sheet->getStyle("M2:O{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

                // Set row height for header
                $sheet->getRowDimension(1)->setRowHeight(25);

                // Add summary information at the top (before the table)
                $sheet->insertNewRowBefore(1, 5);

                $month = \Carbon\Carbon::parse($this->payroll->salary_month)->format('F Y');
                $periodLabel = ($this->payroll->period_start && $this->payroll->period_end)
                    ? \Carbon\Carbon::parse($this->payroll->period_start)->format('d M Y').' - '.\Carbon\Carbon::parse($this->payroll->period_end)->format('d M Y')
                    : $month;
                $totalEmployees = $this->payroll->payrollDetails()->count();
                $totalAmount = $this->payroll->payrollDetails()->sum('final_salary');
                $avgSalary = $totalEmployees > 0 ? $totalAmount / $totalEmployees : 0;

                $sheet->setCellValue('A1', 'EMPLOYEE PAYROLL REPORT');
                $sheet->setCellValue('A2', "Period: {$periodLabel}");
                $sheet->setCellValue('A3', "Total Employees: {$totalEmployees}");
                $sheet->setCellValue('D3', 'Total Payroll: Rp '.number_format($totalAmount, 0, '.', ','));
                $sheet->setCellValue('A4', 'Average Salary: Rp '.number_format($avgSalary, 0, '.', ','));
                $sheet->setCellValue('D4', 'Status: '.($this->payroll->status === 'paid' ? 'Paid' : 'Pending'));

                $displayPayDate = $this->payroll->payment_date ?? $this->payroll->pay_date;
                if ($displayPayDate) {
                    $paymentDate = \Carbon\Carbon::parse($displayPayDate)->format('d F Y');
                    $sheet->setCellValue('A5', "Pay Date: {$paymentDate}");
                }

                // Style the summary section
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 16,
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                    ],
                ]);
                $sheet->mergeCells('A1:Q1');

                $sheet->getStyle('A2:Q5')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 11,
                    ],
                ]);
            },
        ];
    }
}
