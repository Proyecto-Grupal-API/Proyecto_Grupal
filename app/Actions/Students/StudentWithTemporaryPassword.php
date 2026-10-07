<?php

namespace App\Actions\Students;

use App\Models\User;

final readonly class StudentWithTemporaryPassword
{
    public function __construct(
        public User $student,
        public string $temporaryPassword,
    ) {}
}
