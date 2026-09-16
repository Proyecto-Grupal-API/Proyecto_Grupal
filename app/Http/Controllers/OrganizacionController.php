<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizacionController extends Controller
{
    public function index()
    {
        try {
            $organizacion = DB::table('organizaciones')->first();
            $miembros = [];
            $roles = [];
            
            if ($organizacion) {
                // Filtramos los que no están eliminados
                $miembros = DB::table('miembros_organizacion')
                    ->where('organizacion_id', $organizacion->id)
                    ->whereNull('eliminado_en')
                    ->orderBy('creado_en', 'desc')
                    ->get();

                $roles = DB::table('asignaciones_roles_organizacion')
                    ->where('organizacion_id', $organizacion->id)
                    ->whereNull('eliminado_en')
                    ->orderBy('creado_en', 'desc')
                    ->get();
            }

            return response()->json([
                'status' => 'success',
                'data' => $organizacion ? [$organizacion] : [],
                'miembros' => $miembros,
                'roles' => $roles
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en Organizaciones: ' . $e->getMessage());
            return response()->json(['error' => 'Error interno del servidor'], 500);
        }
    }

    public function updatePerfil(Request $request) {
        DB::table('organizaciones')->where('id', 1)->update([
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            'email' => $request->email,
            'telefono' => $request->telefono,
            'actualizado_en' => now()
        ]);
        return response()->json(['message' => 'Perfil actualizado']);
    }

    public function addMiembro(Request $request) {
        DB::table('miembros_organizacion')->insert([
            'organizacion_id' => 1,
            'usuario_id' => $request->matricula,
            'rol_interno' => $request->rol_interno,
            'fecha_inicio' => $request->fecha_inicio,
            'estado' => 'activo',
            'creado_en' => now(),
            'actualizado_en' => now()
        ]);
        return response()->json(['message' => 'Miembro añadido']);
    }

    public function assignRol(Request $request) {
        // Soft delete previo si hay un reemplazo
        DB::table('asignaciones_roles_organizacion')
            ->where('organizacion_id', 1)
            ->whereNull('eliminado_en')
            ->where(function($query) use ($request) {
                $query->where('usuario_id', $request->usuario_id)
                      ->orWhere('slug_rol', $request->slug_rol);
            })
            ->update(['eliminado_en' => now(), 'actualizado_en' => now()]);

        DB::table('asignaciones_roles_organizacion')->insert([
            'organizacion_id' => 1,
            'usuario_id' => $request->usuario_id,
            'slug_rol' => $request->slug_rol,
            'fecha_inicio' => $request->fecha_inicio,
            'otorgado_por' => 1,
            'creado_en' => now(),
            'actualizado_en' => now()
        ]);
        
        return response()->json(['message' => 'Rol actualizado correctamente']);
    }

    // NUEVO: Eliminar (Baja lógica) a un miembro
    public function removeMiembro($id) {
        DB::table('miembros_organizacion')
            ->where('id', $id)
            ->update([
                'estado' => 'inactivo', // Lo pasamos a inactivo por historial
                'eliminado_en' => now(),
                'actualizado_en' => now()
            ]);
        return response()->json(['message' => 'Miembro dado de baja']);
    }

    // NUEVO: Eliminar un rol directivo
    public function removeRol($id) {
        DB::table('asignaciones_roles_organizacion')
            ->where('id', $id)
            ->update([
                'eliminado_en' => now(),
                'actualizado_en' => now()
            ]);
        return response()->json(['message' => 'Cargo removido']);
    }
}