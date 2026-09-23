<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AcademicProgram extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'academic_programs';
    protected $fillable = ['campus_id', 'code', 'name', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function studentProfiles()
    {
        return $this->hasMany(StudentProfile::class, 'academic_program_id');
    }
}
