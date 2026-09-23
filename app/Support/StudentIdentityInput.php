<?php

namespace App\Support;

use Illuminate\Support\Str;

class StudentIdentityInput
{
    public static function normalize(array $data): array
    {
        foreach (['email', 'personal_email'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = Str::lower(trim($data[$field]));
            }
        }

        if (isset($data['enrollment_number']) && is_string($data['enrollment_number'])) {
            $data['enrollment_number'] = Str::upper(trim($data['enrollment_number']));
        }

        return $data;
    }
}
