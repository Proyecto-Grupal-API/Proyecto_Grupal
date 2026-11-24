<?php

// Barra superior de módulos (Modulo6Layout): la tarjeta activa y su aria-current deben salir de UNA sola regla. Antes
// eran dos expresiones duplicadas; la de la clase no tenía ramas para Cartera ni Recompensas y en /modulo6 se marcaban
// tres tarjetas (Cartera, Comunidad, Recompensas) y en /finanzas ninguna. La prueba de navegador está en
// VALIDACION-FUNCIONAL-CAMPUS-VIRTUAL/scripts/prueba-navegacion-activa.cjs (RED 3/9 → GREEN 9/9).

function modulo6Layout(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/Modulo6Layout.vue');
}

test('the active module card and aria-current share one rule', function () {
    $layout = modulo6Layout();

    expect($layout)->toContain(":class=\"{ 'is-active': moduloActivo(modulo) }\"")
        ->and($layout)->toContain(":aria-current=\"moduloActivo(modulo) ? 'true' : undefined\"");
});

test('the rule has a branch for every module card', function () {
    preg_match('/const moduloActivo = \(modulo\) => \{(.*?)\n\};/s', modulo6Layout(), $rule);

    expect($rule[1] ?? '')->not->toBe('');
    foreach (['perfil' => 'esPerfil', 'finanzas' => 'esFinanzas', 'recompensas' => 'esRecompensas', 'servicios' => 'esServicios',
        'inventario' => 'esInventario', 'marketplace' => 'esMarketplace'] as $flag => $computed) {
        expect($rule[1])->toContain("if (modulo.$flag) return $computed.value;");
    }
});
