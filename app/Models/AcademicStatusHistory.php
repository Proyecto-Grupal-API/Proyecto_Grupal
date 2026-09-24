<?php

namespace App\Models;

use App\Enums\StudentStatus;
use MongoDB\Laravel\Eloquent\Model;

class AcademicStatusHistory extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'academic_status_history';
    protected $fillable = [
        'student_profile_id', 'from_status', 'to_status', 'reason', 'changed_by', 'changed_at',
    ];
    protected $casts = [
        'from_status' => StudentStatus::class,
        'to_status' => StudentStatus::class,
        'changed_at' => 'datetime',
    ];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
