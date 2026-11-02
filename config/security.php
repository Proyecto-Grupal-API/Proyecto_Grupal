<?php

use App\Models\Role;

return [
    // Team 1 policy decision: context does not change the 2FA requirement.
    'two_factor_required_roles' => [
        Role::ADMIN,
        Role::MAESTRO,
        Role::STUDENT_MANAGER,
    ],
];
