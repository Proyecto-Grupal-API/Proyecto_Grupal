<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Business;
use App\Services\ClienteModuloHttp;
use Illuminate\Support\Facades\Http;

class MarketplaceIntegration
{
    public function __construct(private ClienteModuloHttp $clients) {}

    private function oauth(string $module, string $scope, string $method, string $path, array $data): array
    {
        try {
            $response = $this->clients->para($module, $scope)->send($method, $path, [$method === 'GET' ? 'query' : 'json' => $data])->throw()->json();
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'El módulo asociado no está disponible. Intenta de nuevo más tarde.');
        }
        abort_unless(is_array($response), 503, 'El módulo asociado devolvió una respuesta inválida.');

        return $response;
    }

    public function allowed(string $subject, string $business, string $capability, bool $fresh = false): bool
    {
        $key = 'marketplace_permission:'.hash('sha256', $subject.'|'.$business.'|'.$capability);
        if (! $fresh && request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $scope = ['subject_id' => $subject, 'scope_type' => 'business', 'scope_id' => $business];
        $assignmentKey = 'marketplace_assignment:'.hash('sha256', $subject.'|'.$business);
        $assignments = (! $fresh && request()->attributes->has($assignmentKey)) ? request()->attributes->get($assignmentKey) : $this->oauth('marketplace_identity', 'identity:assignments:read', 'GET', '/api/v1/identity/assignments', $scope);
        abort_unless(($assignments['subject_id'] ?? null) === $subject && ($assignments['scope_type'] ?? null) === 'business'
            && ($assignments['scope_id'] ?? null) === $business, 503, 'Identidad devolvió un ámbito diferente.');
        request()->attributes->set($assignmentKey, $assignments);
        $allowed = false;
        if (($assignments['membership_status'] ?? null) === 'active' && ! empty($assignments['roles'])) {
            $check = $this->oauth('marketplace_identity', 'identity:authorization:check', 'POST', '/api/v1/identity/authorization/check', $scope + ['capability' => $capability]);
            abort_unless(array_key_exists('authorized', $check) && is_bool($check['authorized']), 503, 'Respuesta de autorización inválida.');
            $allowed = $check['authorized'];
        }
        request()->attributes->set($key, $allowed);

        return $allowed;
    }

    public function businesses(string $subject, string $capability): array
    {
        // ponytail: query known Marketplace businesses; replace with E1's scoped listing when that API exists.
        return Business::pluck('id')->map(fn ($id) => (string) $id)->filter(fn ($id) => $this->allowed($subject, $id, $capability))->values()->all();
    }

    public function provisionOwner(string $subject, string $business, string $operation): void
    {
        $result = $this->oauth('marketplace_identity', 'identity:business-owner:provision', 'POST', '/api/v1/identity/business-owner/provision', [
            'subject_id' => $subject, 'business_id' => $business, 'operation_id' => $operation]);
        abort_unless(($result['subject_id'] ?? null) === $subject && ($result['business_id'] ?? null) === $business
            && ($result['result'] ?? null) === 'provisioned', 503, 'Identidad no confirmó al propietario solicitado.');
        abort_unless($this->allowed($subject, $business, 'business.manage', true), 409, 'La asignación de propietario no está vigente en Identidad.');
    }

    public function organizations(string $subject): array
    {
        $result = $this->oauth('marketplace_community', 'community:organizations:read', 'GET', '/api/v1/comunidad/organizaciones/comercio', ['subject_id' => $subject]);
        abort_unless(($result['subject_id'] ?? null) === $subject && is_array($result['organizations'] ?? null), 503, 'Respuesta de Comunidad inválida.');

        return array_values(array_filter($result['organizations'], fn ($o) => is_array($o) && is_string($o['id'] ?? null) && ($o['can_request_store'] ?? false) === true));
    }

    public function requireOrganization(string $subject, string $organization): void
    {
        abort_unless(collect($this->organizations($subject))->contains('id', $organization), 403, 'Ya no tienes un cargo vigente que permita solicitar la tienda de esta organización.');
    }

    public function inventory(string $business, string $method, string $operation, array $data = []): array
    {
        $secret = (string) config('marketplace.inventory.secret');
        $url = (string) config('marketplace.inventory.url');
        abort_unless($secret !== '' && $url !== '', 503, 'La conexión con Inventarios está pendiente de configurar.');
        $path = $operation === 'register' ? '/api/equipo4/integration/businesses' : '/api/equipo4/businesses/'.rawurlencode($business).'/'.$operation;
        $body = $method === 'GET' ? '' : json_encode($data, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'|'.$method.'|'.$path.'|'.hash('sha256', $body), $secret);
        try {
            $response = Http::baseUrl(rtrim($url, '/'))->acceptJson()->connectTimeout(2)->timeout(10)
                ->withHeaders(['X-Team-Timestamp' => $timestamp, 'X-Team-Signature' => $signature])
                ->withBody($body, 'application/json')->send($method, $path);
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'Inventarios no confirmó la operación. El pedido conserva su estado para revisión.');
        }
        if ($response->status() === 409 && $response->json('status') === 'REJECTED') {
            abort(409, 'Inventarios rechazó la reserva por falta de disponibilidad.');
        }
        abort_unless($response->successful() && is_array($response->json()), 503, 'Inventarios no confirmó la operación.');

        return $response->json();
    }

    public function inventoryCatalog(string $business): array
    {
        $key = 'marketplace_inventory:'.$business;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $result = [];
        foreach (['products', 'locations', 'inventory'] as $resource) {
            $result[$resource] = $this->inventory($business, 'GET', $resource);
        }
        request()->attributes->set($key, $result);

        return $result;
    }

    public function validateInventory(string $business, array $data): void
    {
        abort_unless(($data['kind'] === 'product' && $data['inventory_required']) || ($data['kind'] === 'service' && ! $data['inventory_required']), 422, 'Los productos físicos requieren inventario; los servicios no reservan existencias.');
        if (! $data['inventory_required']) {
            return;
        }
        $catalog = $this->inventoryCatalog($business);
        $product = collect($catalog['products'])->first(fn ($p) => (string) ($p['id'] ?? $p['_id'] ?? '') === ($data['inventory_product_id'] ?? ''));
        $location = collect($catalog['locations'])->first(fn ($p) => (string) ($p['id'] ?? $p['_id'] ?? '') === ($data['inventory_location_id'] ?? ''));
        abort_unless($product && $location,422,'Selecciona un producto y una ubicación reales de Inventarios para este negocio.');
        abort_unless(($product['business_id'] ?? null) === $business && ($location['business_id'] ?? null) === $business,422,'El inventario pertenece a otro negocio.');
        abort_unless(($data['kind'] ?? 'product') === 'product',422,'Un servicio no puede reservar existencias físicas.');
    }
}
