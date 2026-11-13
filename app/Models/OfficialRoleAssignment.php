<?php
namespace App\Models;

use App\Services\DesignationAuthorization;

/** Ordinary writer for the same lifecycle/collection; raw RoleAssignment stays pilot-guarded. */
final class OfficialRoleAssignment extends RoleAssignment
{
    protected $collection = 'role_assignments';
    protected $table = 'role_assignments';

    protected function validateContext(): void
    {
        $this->assertGrantWriter();
        parent::validateContext();
    }

    public function transitionTo(string $status,mixed $actorId=null,?string $reason=null): void
    {
        if ($status!=='revoked' || (string)$actorId!==(string)request()->user('web')?->getKey()
            || !is_string($reason) || trim($reason)==='') throw new \Illuminate\Auth\Access\AuthorizationException();
        $this->assertGrantWriter();
        parent::transitionTo($status,$actorId,$reason);
    }

    protected function assertGrantWriter(): void
    {
        app(DesignationAuthorization::class)->assertRecord(request(), $this);
    }
}
