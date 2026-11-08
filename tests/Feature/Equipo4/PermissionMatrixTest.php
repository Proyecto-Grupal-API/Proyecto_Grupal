<?php

namespace Tests\Feature\Equipo4;

use App\Services\IdentityService;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    protected IdentityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(IdentityService::class);
    }

    /** @test */
    public function test_admin_puede_ajustar_inventario(): void
    {
        $this->assertTrue($this->svc->userCan('USR-ADMIN-001', 'inventario.adjust'));
    }

    /** @test */
    public function test_auditor_no_puede_ajustar_inventario(): void
    {
        $this->assertFalse($this->svc->userCan('USR-AUD-001', 'inventario.adjust'));
    }

    /** @test */
    public function test_buyer_no_autoriza_ordenes_de_compra(): void
    {
        $this->assertFalse($this->svc->userCan('USR-BUY-001', 'compras.authorize'));
        $this->assertTrue($this->svc->userCan('USR-BUY-001', 'compras.create'));
    }

    /** @test */
    public function test_inventory_manager_no_elimina_productos(): void
    {
        $this->assertFalse($this->svc->userCan('USR-INV-001', 'productos.delete'));
        $this->assertTrue($this->svc->userCan('USR-INV-001', 'productos.create'));
        $this->assertTrue($this->svc->userCan('USR-INV-001', 'productos.update'));
    }

    /** @test */
    public function test_auditor_solo_puede_leer(): void
    {
        $a = 'USR-AUD-001';
        $this->assertTrue($this->svc->userCan($a, 'productos.view'));
        $this->assertTrue($this->svc->userCan($a, 'inventario.view'));
        $this->assertFalse($this->svc->userCan($a, 'productos.create'));
        $this->assertFalse($this->svc->userCan($a, 'productos.update'));
        $this->assertFalse($this->svc->userCan($a, 'productos.delete'));
        $this->assertFalse($this->svc->userCan($a, 'inventario.adjust'));
        $this->assertFalse($this->svc->userCan($a, 'compras.authorize'));
    }

    /** @test */
    public function test_business_id_scope_default_es_global_en_stub(): void
    {
        $user = $this->svc->getUser('USR-BUY-001');
        $this->assertNotNull($user);
        $this->assertSame('global', $user['role_scope']);
    }

    /** @test */
    public function test_solo_administracion_puede_aprobar_ajustes_de_conteos(): void
    {
        $this->assertTrue($this->svc->userCan('USR-ADMIN-001', 'conteos.approve'));
        $this->assertFalse($this->svc->userCan('USR-INV-001', 'conteos.approve'));
        $this->assertFalse($this->svc->userCan('USR-BUY-001', 'conteos.approve'));
    }
}