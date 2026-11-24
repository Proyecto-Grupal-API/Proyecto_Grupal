<?php

namespace App\Services\StudentServices;

use App\Models\User;
use App\Support\Authorization\ServiceStaff;

class ServiceAuthority
{
    // Team 1 publishes no library/operator role. Staff is the administrator authority Identity already assigns:
    // the global administrator, or the `admin` role granted in the service context of this module.
    // Do not infer operator access from student_manager, association positions or legacy arrays.
    public function staff(?User $user): bool
    {
        return ServiceStaff::manages($user, (string) config('integracion_servicios.contexto_personal', 'servicios-estudiante'));
    }

    public function propias($query)
    {
        $user = request()->user();

        return $this->staff($user) ? $query : $query->where('student_id', (string) $user?->id);
    }

    public function desdeAviso($query)
    {
        if (! request()->query->has('registro')) {
            return $query;
        }

        $id = request()->query('registro');
        abort_unless(is_string($id) && preg_match('/^[a-f0-9]{24}$/Di', $id), 404);

        return $query->where('student_id', (string) request()->user()->id)->whereKey(strtolower($id));
    }
}
