<?php

namespace App\Models\StudentServices\Services;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property ObjectId|string $service_order_id
 * @property string $job_type
 * @property string|null $file_name
 * @property string|null $file_path
 * @property string|null $file_mime
 * @property string|null $color_mode
 * @property string|null $paper_size
 * @property string|null $sides
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PrintJob extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'print_jobs';

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
        mixed $value
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
