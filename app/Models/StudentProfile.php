<?php

namespace App\Models;

use App\Enums\PreferredContactChannel;
use App\Enums\StudentStatus;
use MongoDB\Laravel\Eloquent\Model;

class StudentProfile extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'student_profiles';
    protected $fillable = [
        'user_id', 'enrollment_number', 'campus_id', 'academic_program_id',
        'current_semester', 'group_name', 'photo_path', 'academic_status',
        'personal_email', 'phone', 'preferred_contact_channel', 'locale',
    ];

    protected $casts = [
        'academic_status' => StudentStatus::class,
        'preferred_contact_channel' => PreferredContactChannel::class,
        'current_semester' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function academicProgram()
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(AcademicStatusHistory::class, 'student_profile_id')
            ->latest('changed_at');
    }
}
