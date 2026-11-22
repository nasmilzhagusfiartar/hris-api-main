<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeEducation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'employee_educations';

    protected $fillable = [
        'employee_id',
        'level',
        'institution',
        'major',
        'start_year',
        'end_year',
        'gpa',
        'is_highest',
    ];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
            'gpa' => 'float',
            'is_highest' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
