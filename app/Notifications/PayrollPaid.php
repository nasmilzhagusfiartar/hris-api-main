<?php

namespace App\Notifications;

use App\Models\PayrollDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollPaid extends Notification implements ShouldQueue
{
    use Queueable;

    protected PayrollDetail $payrollDetail;

    /**
     * Create a new notification instance.
     */
    public function __construct(PayrollDetail $payrollDetail)
    {
        $this->payrollDetail = $payrollDetail;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $payroll = $this->payrollDetail->payroll;
        $salaryMonth = \Carbon\Carbon::parse($payroll->salary_month)->format('F Y');
        $paymentDate = \Carbon\Carbon::parse($payroll->payment_date)->format('d F Y');

        return (new MailMessage)
            ->subject('Payslip for '.$salaryMonth.' Has Been Paid')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your salary for **'.$salaryMonth.'** has been paid.')
            ->line('**Salary Details:**')
            ->line('Base Salary: Rp '.number_format($this->payrollDetail->original_salary, 0, '.', ','))
            ->line('Deductions: Rp '.number_format($this->payrollDetail->original_salary - $this->payrollDetail->final_salary, 0, '.', ','))
            ->line('Net Salary Received: Rp '.number_format($this->payrollDetail->final_salary, 0, '.', ','))
            ->line('Payment Date: '.$paymentDate)
            ->line('**Attendance Summary:**')
            ->line('Present: '.$this->payrollDetail->attended_days.' day(s)')
            ->line('Sick: '.$this->payrollDetail->sick_days.' day(s)')
            ->line('Absent: '.$this->payrollDetail->absent_days.' day(s)')
            ->when($this->payrollDetail->notes, function ($message) {
                return $message->line('Notes: '.$this->payrollDetail->notes);
            })
            ->action('View Payslip', url('/admin/my-payslips'))
            ->line('Thank you for your contribution.')
            ->salutation('Regards, HIP HRIS Finance Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $salaryMonth = $this->payrollDetail->payroll?->salary_month
            ? \Carbon\Carbon::parse($this->payrollDetail->payroll->salary_month)->format('F Y')
            : '';

        return [
            'type' => 'payroll_paid',
            'title' => "Payslip {$salaryMonth} Paid",
            'message' => 'Your salary of Rp '.number_format((float) $this->payrollDetail->final_salary, 0, '.', ',')." for {$salaryMonth} has been paid.",
            'route_name' => 'employee.payslips',
            'payroll_detail_id' => $this->payrollDetail->id,
            'payroll_id' => $this->payrollDetail->payroll_id,
            'salary_month' => $this->payrollDetail->payroll?->salary_month,
            'payment_date' => $this->payrollDetail->payroll?->payment_date,
            'original_salary' => $this->payrollDetail->original_salary,
            'final_salary' => $this->payrollDetail->final_salary,
            'attended_days' => $this->payrollDetail->attended_days,
            'sick_days' => $this->payrollDetail->sick_days,
            'absent_days' => $this->payrollDetail->absent_days,
        ];
    }
}
