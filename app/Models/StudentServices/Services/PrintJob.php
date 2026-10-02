<?php

namespace App\Models\StudentServices\Services;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class PrintJob extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'print_jobs';

    protected $fillable = [
        'service_order_id',
        'job_type',

        'file_name',
        'file_path',
        'file_mime',

        'color_mode',
        'paper_size',
        'sides',

        'created_at',
        'updated_at',
    ];

    public function setServiceOrderIdAttribute(
        $value
    ): void {
        $this->attributes[
        'service_order_id'
        ] = $value instanceof ObjectId
            ? $value
            : new ObjectId(
                (string) $value
            );
    }
}
