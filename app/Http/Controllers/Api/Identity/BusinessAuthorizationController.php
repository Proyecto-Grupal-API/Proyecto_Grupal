<?php

namespace App\Http\Controllers\Api\Identity;

use App\Http\Controllers\Controller;
use App\Models\BusinessMembership;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\BusinessAuthorizationService;
use App\Services\InitialBusinessOwnerProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessAuthorizationController extends Controller
{
    private function identifier(): array
    {
        return ['required', 'string', 'max:100', 'regex:/^[^\x00-\x20\x7f]+$/'];
    }

    private function query(Request $request): array
    {
        return $request->validate(['subject_id' => $this->identifier(),
            'scope_type' => $this->identifier(), 'scope_id' => $this->identifier()]);
    }

    public function check(Request $request, BusinessAuthorizationService $authorization): JsonResponse
    {
        $data = $this->query($request);
        $capability = $request->validate(['capability' => $this->identifier()])['capability'];

        return response()->json(['authorized' => $authorization->allows(
            User::whereKey($data['subject_id'])->first(), $capability, $data['scope_type'], $data['scope_id']
        )]);
    }

    public function assignments(Request $request, BusinessAuthorizationService $authorization): JsonResponse
    {
        $data = $this->query($request);
        $request->validate(['scope_type' => ['in:business']]);
        $user = User::whereKey($data['subject_id'])->first();
        $membership = $user ? BusinessMembership::where('user_id', $data['subject_id'])
            ->where('business_id', $data['scope_id'])->orderByDesc('generation')->first() : null;
        $roles = $authorization->effectiveRoles($user, $data['scope_id']);
        $records = RoleAssignment::where('user_id', $data['subject_id'])->where('scope_type', 'business')
            ->where('scope_id', $data['scope_id'])->where('is_current', true)->whereIn('role_key', $roles)->get();

        return response()->json(['subject_id' => $data['subject_id'], 'scope_type' => 'business',
            'scope_id' => $data['scope_id'], 'membership_status' => $membership?->status,
            'roles' => $records->map(fn ($role) => ['role_key' => $role->role_key, 'status' => $role->status,
                'starts_at' => $role->starts_at?->toIso8601String(), 'ends_at' => $role->ends_at?->toIso8601String(),
                'assigned_at' => $role->assigned_at?->toIso8601String()])->values()->all()]);
    }

    public function provision(Request $request, InitialBusinessOwnerProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate(['subject_id' => $this->identifier(), 'business_id' => $this->identifier(),
            'operation_id' => $this->identifier()]);
        $created = $provisioner->provision($request->attributes->get('oauth_client_id'),
            $data['subject_id'], $data['business_id'], $data['operation_id']);

        return response()->json(['subject_id' => $data['subject_id'], 'business_id' => $data['business_id'],
            'result' => 'provisioned'], $created ? 201 : 200);
    }
}
