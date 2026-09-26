<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\QrToken;
use App\Models\QrValidation;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\Seeder;

/**
 * Datos de demostracion para probar de inmediato los modulos 1.6
 * (Identidad QR) y 1.7 (Dispositivos y sesiones confiables) sobre
 * MongoDB: dispositivos conectados, historial de seguridad y un
 * codigo QR activo para el primer usuario existente.
 */
class IdentityDemoSeeder extends Seeder
{
    public function run(): void
    {
             $user = User::where('email', 'test@example.com')->first()
            ?? User::first();

        if (! $user) {
            $this->command?->warn('No hay usuarios en la base; corre primero User::factory() o el seeder por defecto.');

            return;
        }

        // --- Dispositivo 1: Chrome / Windows (confiable, sesion actual) ---
        $chrome = Device::updateOrCreate(
            ['user_id' => (string) $user->_id, 'fingerprint' => 'demo-fingerprint-chrome-windows'],
            [
                'device_name' => 'Windows · Chrome',
                'device_type' => 'browser',
                'platform' => 'Windows',
                'browser' => 'Chrome',
                'is_trusted' => true,
                'first_seen_at' => now()->subMonths(3),
                'last_seen_at' => now(),
                'last_ip_address' => '187.190.12.44',
            ]
        );

        $chromeSession = UserSession::create([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $chrome->_id,
            'ip_address' => '187.190.12.44',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0',
            'started_at' => now()->subHours(2),
            'last_activity_at' => now(),
        ]);

        // --- Dispositivo 2: Safari / iPhone (no confiable, sesion vigente) ---
        $safari = Device::updateOrCreate(
            ['user_id' => (string) $user->_id, 'fingerprint' => 'demo-fingerprint-safari-iphone'],
            [
                'device_name' => 'iOS · Safari',
                'device_type' => 'browser',
                'platform' => 'iOS',
                'browser' => 'Safari',
                'is_trusted' => false,
                'first_seen_at' => now()->subDays(5),
                'last_seen_at' => now()->subHours(3),
                'last_ip_address' => '201.155.30.7',
            ]
        );

        UserSession::create([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $safari->_id,
            'ip_address' => '201.155.30.7',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Safari/604.1',
            'started_at' => now()->subHours(3),
            'last_activity_at' => now()->subHours(3),
        ]);

        // --- Dispositivo 3: Firefox / Linux (sesion ya revocada, historico) ---
        $firefox = Device::updateOrCreate(
            ['user_id' => (string) $user->_id, 'fingerprint' => 'demo-fingerprint-firefox-linux'],
            [
                'device_name' => 'Linux · Firefox',
                'device_type' => 'browser',
                'platform' => 'Linux',
                'browser' => 'Firefox',
                'is_trusted' => false,
                'first_seen_at' => now()->subDays(20),
                'last_seen_at' => now()->subDays(2),
                'last_ip_address' => '189.203.44.10',
            ]
        );

        UserSession::create([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $firefox->_id,
            'ip_address' => '189.203.44.10',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/126.0',
            'started_at' => now()->subDays(2)->subHours(1),
            'last_activity_at' => now()->subDays(2),
            'revoked_at' => now()->subDays(2),
            'revoked_reason' => 'manual',
        ]);

        // --- Bitacora de seguridad ---
        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $chrome->_id,
            'session_id' => (string) $chromeSession->_id,
            'type' => 'login_success',
            'severity' => 'info',
            'ip_address' => '187.190.12.44',
            'occurred_at' => now()->subHours(2),
        ]);

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $safari->_id,
            'type' => 'new_device',
            'severity' => 'warning',
            'ip_address' => '201.155.30.7',
            'metadata' => ['device_name' => 'iOS · Safari'],
            'occurred_at' => now()->subDays(5),
        ]);

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $firefox->_id,
            'type' => 'session_revoked',
            'severity' => 'info',
            'ip_address' => '189.203.44.10',
            'metadata' => ['reason' => 'manual'],
            'occurred_at' => now()->subDays(2),
        ]);

        // --- QR de identificacion (fijo) + validacion de ejemplo ---
        $idToken = QrToken::create([
            'user_id' => (string) $user->_id,
            'code' => QrToken::generateCode(),
            'type' => 'identification',
            'purpose' => 'identidad-estudiantil',
            'expires_at' => now()->addDay(),
        ]);

        QrValidation::create([
            'qr_token_id' => (string) $idToken->_id,
            'user_id' => (string) $user->_id,
            'result' => 'valid',
            'context' => 'biblioteca-central',
            'ip_address' => '187.190.12.44',
        ]);

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'type' => 'qr_validated',
            'severity' => 'info',
            'ip_address' => '187.190.12.44',
            'metadata' => ['context' => 'biblioteca-central'],
            'occurred_at' => now()->subHours(1),
        ]);

        $this->command?->info('Modulos 1.6/1.7: datos de demostracion creados para '.$user->email);
    }
}
