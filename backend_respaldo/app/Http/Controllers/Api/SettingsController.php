<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\Catalog;
use App\Models\ConfigurationVersion;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController
{
    /**
     * Listado de configuraciones del sistema (módulo 7.11):
     * Filtra o enmascara configuraciones sensibles si el usuario no tiene permiso explícito.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewSensitive = $user && ($user->role === User::ROLE_PLATFORM_ADMIN || $user->hasPermission('settings.manage_sensitive'));

        $settings = Setting::all()->map(function (Setting $s) use ($canViewSensitive) {
            if ($s->is_sensitive && ! $canViewSensitive) {
                $s->value = '******** (Protegido)';
            }
            return $s;
        });

        return response()->json($settings);
    }

    /**
     * Actualización de configuración sensible (requiere autorización reforzada y auditoría).
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $user = $request->user();

        $setting = Setting::where('key', $key)->firstOrFail();

        if ($setting->is_sensitive && $user->role !== User::ROLE_PLATFORM_ADMIN) {
            return response()->json([
                'success' => false,
                'error'   => 'Acceso denegado: solo el Administrador de Plataforma puede modificar parámetros sensibles.',
            ], 403);
        }

        $validated = $request->validate([
            'value'       => 'required',
            'description' => 'nullable|string|max:500',
        ]);

        $before = $setting->toArray();

        $setting->update([
            'value'       => $validated['value'],
            'description' => $validated['description'] ?? $setting->description,
        ]);

        AuditLog::create([
            'actor_id'      => (string) $user->getKey(),
            'actor_role'    => $user->role,
            'action'        => 'setting.updated',
            'entity_type'   => 'Setting',
            'entity_id'     => $key,
            'before'        => $setting->is_sensitive ? ['key' => $key, 'value' => '***'] : $before,
            'after'         => $setting->is_sensitive ? ['key' => $key, 'value' => '***'] : $setting->fresh()->toArray(),
            'ip_address'    => $request->ip(),
            'device'        => $request->userAgent(),
            'source_domain' => 'settings',
        ]);

        return response()->json([
            'success' => true,
            'setting' => $setting->fresh(),
        ]);
    }

    /**
     * Historial de versiones de configuración (módulo 7.11).
     */
    public function versions(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $query = ConfigurationVersion::orderBy('created_at', 'desc');

        if ($configKey = $request->query('config_key')) {
            $query->where('config_key', $configKey);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Proponer nueva versión de configuración (draft).
     */
    public function createVersion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'config_key' => 'required|string|max:100',
            'value'      => 'required',
        ]);

        $user = $request->user();
        $lastVersion = ConfigurationVersion::where('config_key', $validated['config_key'])
            ->max('version') ?? 0;

        $version = ConfigurationVersion::create([
            'config_key' => $validated['config_key'],
            'version'    => $lastVersion + 1,
            'value'      => $validated['value'],
            'status'     => 'draft',
            'created_by' => (string) $user->getKey(),
        ]);

        AuditLog::create([
            'actor_id'      => (string) $user->getKey(),
            'actor_role'    => $user->role,
            'action'        => 'config_version.drafted',
            'entity_type'   => 'ConfigurationVersion',
            'entity_id'     => (string) $version->_id,
            'after'         => $version->toArray(),
            'ip_address'    => $request->ip(),
            'device'        => $request->userAgent(),
            'source_domain' => 'settings',
        ]);

        return response()->json(['success' => true, 'version' => $version], 201);
    }

    /**
     * Transición de ciclo de vida de versión de configuración
     * (draft -> pending_approval -> approved -> active).
     */
    public function transitionVersion(Request $request, string $id): JsonResponse
    {
        $version = ConfigurationVersion::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'target_status'   => 'required|string|in:pending_approval,approved,rejected,active',
            'rejected_reason' => 'nullable|string|max:500',
        ]);

        $next = $validated['target_status'];

        if (! $version->canTransitionTo($next)) {
            return response()->json([
                'success' => false,
                'error'   => "Transición inválida: de [{$version->status}] a [{$next}].",
            ], 422);
        }

        $updates = ['status' => $next];

        if ($next === 'approved') {
            $updates['approved_by'] = ['user_id' => (string) $user->getKey(), 'name' => $user->name];
            $updates['approved_at'] = now();
        } elseif ($next === 'rejected') {
            $updates['rejected_reason'] = $validated['rejected_reason'] ?? 'Rechazado por administración';
        } elseif ($next === 'active') {
            $updates['activated_at'] = now();

            // Desactiva versión previa si existe
            ConfigurationVersion::where('config_key', $version->config_key)
                ->where('status', 'active')
                ->where('_id', '!=', $version->_id)
                ->update([
                    'status'                => 'superseded',
                    'superseded_at'         => now(),
                    'superseded_by_version' => $version->version,
                ]);

            // Actualiza también la tabla de settings activa
            Setting::updateOrCreate(
                ['key' => $version->config_key],
                ['value' => $version->value]
            );
        }

        $version->update($updates);

        AuditLog::create([
            'actor_id'      => (string) $user->getKey(),
            'actor_role'    => $user->role,
            'action'        => "config_version.{$next}",
            'entity_type'   => 'ConfigurationVersion',
            'entity_id'     => (string) $version->_id,
            'after'         => $updates,
            'ip_address'    => $request->ip(),
            'device'        => $request->userAgent(),
            'source_domain' => 'settings',
        ]);

        return response()->json(['success' => true, 'version' => $version->fresh()]);
    }

    /**
     * Catálogos generales del sistema (módulo 7.7).
     */
    public function catalogs(Request $request): JsonResponse
    {
        $catalogs = Catalog::all();
        return response()->json($catalogs);
    }

    public function storeCatalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'required|string|max:50|unique:mongodb.catalogs,code',
            'items'       => 'required|array',
            'description' => 'nullable|string',
        ]);

        $catalog = Catalog::create($validated);
        return response()->json(['success' => true, 'catalog' => $catalog], 201);
    }
}
