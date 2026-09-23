<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\UserSession;
use App\Services\IdentityService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Modulo 1.7 - Dispositivos y sesiones confiables.
 *
 * Lista dispositivos/sesiones del estudiante autenticado, permite
 * cerrar sesiones remotas, marcar/desmarcar dispositivos como
 * confiables y eliminarlos por completo. Las acciones sensibles
 * (revoke, trust, destroy) exigen una reautenticacion reciente
 * (middleware "reauth").
 */
class SecurityDeviceController extends Controller
{
    private const EVENTS_PAGE_SIZE = 20;

    public function __construct(private IdentityService $identity)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $currentSessionId = $request->session()->get('cd_session_id');

        $devices = Device::where('user_id', (string) $user->_id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(function (Device $device) use ($currentSessionId) {
                $sessions = UserSession::where('device_id', (string) $device->_id)
                    ->whereNull('revoked_at')
                    ->orderByDesc('last_activity_at')
                    ->get()
                    ->map(fn (UserSession $s) => [
                        'id' => (string) $s->_id,
                        'ip_address' => $s->ip_address,
                        'started_at' => optional($s->started_at)->format('d/m/Y H:i'),
                        'last_activity_at' => optional($s->last_activity_at)->diffForHumans(),
                        'is_current' => (string) $s->_id === (string) $currentSessionId,
                    ]);

                return [
                    'id' => (string) $device->_id,
                    'device_name' => $device->device_name,
                    'platform' => $device->platform,
                    'browser' => $device->browser,
                    'is_trusted' => (bool) $device->is_trusted,
                    'is_new' => (bool) $device->first_seen_at?->gt(now()->subHours(24)),
                    'last_ip_address' => $device->last_ip_address,
                    'first_seen_at' => optional($device->first_seen_at)->format('d/m/Y H:i'),
                    'last_seen_at' => optional($device->last_seen_at)->diffForHumans(),
                    'sessions' => $sessions,
                ];
            });

        return Inertia::render('Security/Devices', [
            'devices' => $devices,
            'events' => $this->mapEvents($this->eventsQuery($user)->limit(self::EVENTS_PAGE_SIZE)->get()),
            'eventsPageSize' => self::EVENTS_PAGE_SIZE,
            'reauthValidMinutes' => (int) env('REAUTH_VALID_MINUTES', 5),
            'maxActiveSessions' => (int) env('MAX_ACTIVE_SESSIONS_PER_USER', 5),
        ]);
    }

    /**
     * Bitácora de seguridad paginada (scroll "cargar más"), para no
     * traer siempre toda la historia del estudiante en el render
     * inicial de la pantalla.
     */
    public function events(Request $request)
    {
        $offset = max(0, (int) $request->query('offset', 0));

        $events = $this->eventsQuery($request->user())
            ->skip($offset)
            ->limit(self::EVENTS_PAGE_SIZE)
            ->get();

        return response()->json([
            'items' => $this->mapEvents($events),
            'next_offset' => $offset + self::EVENTS_PAGE_SIZE,
            'has_more' => $events->count() === self::EVENTS_PAGE_SIZE,
        ]);
    }

    /**
     * Latido ligero que el frontend consulta cada pocos segundos desde
     * CUALQUIER pantalla autenticada. No hace nada por si mismo: su
     * unico proposito es dar pie a que el middleware "session.active"
     * (ya presente en este grupo de rutas) detecte que esta sesion fue
     * revocada desde otra pestaña/dispositivo y actue de inmediato, en
     * vez de esperar a que el usuario navegue por su cuenta.
     */
    public function heartbeat(Request $request)
    {
        return response()->json(['ok' => true]);
    }

    public function revoke(Request $request, string $session)
    {
        $userSession = UserSession::where('_id', $session)
            ->where('user_id', (string) $request->user()->_id)
            ->firstOrFail();

        $this->identity->revokeSession($request->user(), $userSession, 'manual');

        return back()->with('success', 'Sesión revocada correctamente.');
    }

    public function revokeOthers(Request $request)
    {
        $user = $request->user();
        $currentSessionId = $request->session()->get('cd_session_id');

        $sessions = UserSession::where('user_id', (string) $user->_id)
            ->whereNull('revoked_at')
            ->where('_id', '!=', $currentSessionId)
            ->get();

        foreach ($sessions as $session) {
            $this->identity->revokeSession($user, $session, 'revoke_others');
        }

        return back()->with('success', 'Se cerraron todas las demás sesiones activas.');
    }

    public function trust(Request $request, string $device)
    {
        $data = $request->validate(['trusted' => ['required', 'boolean']]);

        $deviceModel = Device::where('_id', $device)
            ->where('user_id', (string) $request->user()->_id)
            ->firstOrFail();

        $this->identity->setDeviceTrust($request->user(), $deviceModel, $data['trusted']);

        return back()->with('success', $data['trusted']
            ? 'Dispositivo marcado como confiable.'
            : 'Dispositivo marcado como no confiable.');
    }

    /**
     * Elimina un dispositivo del listado del estudiante (revoca antes
     * cualquier sesión activa que dependa de él).
     */
    public function destroy(Request $request, string $device)
    {
        $deviceModel = Device::where('_id', $device)
            ->where('user_id', (string) $request->user()->_id)
            ->firstOrFail();

        $this->identity->forgetDevice($request->user(), $deviceModel);

        return back()->with('success', 'Dispositivo eliminado.');
    }

    private function eventsQuery($user)
    {
        return SecurityEvent::where('user_id', (string) $user->_id)
            ->orderByDesc('occurred_at');
    }

    private function mapEvents($events)
    {
        return $events->map(fn (SecurityEvent $e) => [
            'id' => (string) $e->_id,
            'type' => $e->type,
            'severity' => $e->severity,
            'ip_address' => $e->ip_address,
            'occurred_at' => optional($e->occurred_at)->diffForHumans() ?? '—',
        ]);
    }
}
