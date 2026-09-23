<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Campus extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'campuses';
    protected $fillable = ['code', 'name', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function academicPrograms()
    {
        return $this->hasMany(AcademicProgram::class, 'campus_id');
    }
}
