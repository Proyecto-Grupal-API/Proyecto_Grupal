<?php

namespace Database\Seeders\Demo;

/**
 * E2 Finanzas: caja de asociación con funciones separadas (presidencia que delega, operador, supervisor) y dos
 * alumnos titulares de monedero. Ningún flujo de producción crea el monedero personal: aquí se siembra como dato.
 */
class DemoE2Seeder extends DemoTeamSeeder
{
    public function run(): void
    {
        $this->guard();
        $president = $this->account('demo.e2.presidencia');
        $operator = $this->account('demo.e2');
        $supervisor = $this->account('demo.e2.supervisor');
        $holder = $this->account('demo.e2.alumno');
        $recipient = $this->account('demo.e2.alumno2');
        foreach ([$president, $operator, $supervisor, $holder, $recipient] as $user) {
            $this->student($user);
        }

        $org = $this->organization(DemoTeams::E2_ORGANIZATION_ID, 'demo-e2-caja', 'Asociación Demo E2', $president, [$operator, $supervisor]);
        // Las capacidades de caja sólo son delegables en organizaciones habilitadas por configuración
        // (FINANCIAL_COMMUNITY_CASH_ASSOCIATIONS). Se habilita para este proceso de siembra; el valor persistente lo
        // escribe la configuración de laboratorio del instalador.
        config(['financial.cash.community_associations' => array_values(array_unique([
            ...(array) config('financial.cash.community_associations', []), DemoTeams::E2_ORGANIZATION_ID]))]);
        $this->delegate($org, $president, $operator, ['caja.administrar', 'caja.ajustar', 'caja.operar']);
        $this->delegate($org, $president, $supervisor, ['caja.consultar', 'caja.revisar']);

        $this->personalWallet($holder);
        $this->personalWallet($recipient);
        // El operador también es titular de un monedero: la caja exige un operador distinto del titular y así puede demostrarse.
        $this->personalWallet($operator);
        // Controles financieros (límites, alertas y conciliaciones): cargos de servicio de Identidad, sin administrador global.
        // Qué cargos abren los controles lo declara la instalación (FINANCIAL_CONTROL_*_ROLES).
        $this->serviceRole($this->account('demo.e2.controles'), 'finanzas');
        $this->serviceRole($this->account('demo.e2.consulta'), 'finanzas-consulta');
    }
}
