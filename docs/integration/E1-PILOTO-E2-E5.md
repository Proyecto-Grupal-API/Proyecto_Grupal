# Ampliaciones E1 para el piloto E2/E5

Ampliaciones preparadas para la rama E1; su publicación requiere autorización. Base grupal revisada: `42496fa6dd738907bacca5092fe1528180d63755`; rama E1 consultada: `485921a20806315e5f6e9ad5100fc1f7c7e1ecba`. No se modifican los contratos BECA/Bonos ni los endpoints E2/E5.

## Segundo factor reciente

`POST /seguridad/confirmar-segundo-factor` (`security.two-factor.confirm`) recibe JSON `{"code":"<TOTP de seis dígitos>"}` dentro de la sesión web autenticada, verificada y vigente de E1. Tiene CSRF web y límite de cinco intentos por minuto. Usa el proveedor Fortify; `login.id`, OAuth y `reauth_at` no acreditan este paso. Los recovery codes continúan disponibles en el login histórico, pero se rechazan en este piloto. La respuesta de validación es JSON y no guarda el código en old input.

`RecentTwoFactor::isRecent(Request)` comprueba la sesión E1 en cada llamada y una evidencia guardada sólo en `user_sessions`, oculta y no asignable masivamente. Se vincula al usuario web, documento de sesión E1, hash del ID de sesión Laravel y configuración confirmada del factor. Sólo es vigente durante los 300 segundos posteriores al TOTP válido, con expiración exclusiva. Una sesión Laravel nueva, otro usuario, cambio de factor o revocación impide reutilizarla. El cierre histórico ya revoca la sesión E1; la revocación ahora también borra la evidencia. No se guardan TOTP, recovery codes ni secretos en auditoría.

La ruta no habilita automáticamente operaciones financieras. No se ha añadido una UI ni modificado el login ordinario.

## Comprobaciones internas confiables

El consumidor llama a `App\Services\PilotOperationAuthorization` por el contenedor Laravel, dentro del proceso web autenticado; no es una API OAuth ni recibe un actor libre del navegador. El servicio obtiene al humano de `Request::user('web')`, comprueba su elegibilidad mediante `IdentityService` y exige la sesión E1 activa. Cada decisión vuelve a consultar el catálogo persistente y `EffectiveRoleAssignments`: activo/current, inicio inclusivo, fin exclusivo y sin revocación. Ausencia de catálogo o permiso activo deniega. Una falla de infraestructura o auditoría no devuelve una autorización positiva.

### E2: revisión y ejecución de devoluciones

```php
$allowed = app(PilotOperationAuthorization::class)
    ->allowsRefund($request, $trustedWalletPublicId, 'approve');
```

E2 obtiene `$trustedWalletPublicId` del abono original y valida su correspondencia contable antes de la llamada. Es un UUID canónico en minúsculas. E1 no consulta wallets ni convierte el ámbito a business.

| Operación interna | Rol y ámbito exactos | TOTP reciente |
| --- | --- | --- |
| `approve` | `financial_refund_reviewer`, `wallet=Wallet.public_id`, permiso `refunds.review` | Sí |
| `reject` | El mismo revisor y wallet | No |
| `execute` | `financial_refund_executor`, misma wallet, permiso independiente `refunds.execute` | Sí |

No existe fallback a un grant global ni a admin/cashier. Revisar no permite ejecutar. E2 conserva separación solicitante/revisor, motivo del rechazo, evidencia contable, estados, importes y ejecución. La acción financiera `complete` debe solicitar explícitamente la decisión interna `execute`; no se cambió su endpoint ni se certificó ese flujo.

### E5: cargo por daños de renta 5.7 desde mostrador 5.11

```php
if ($damageChargeCents > 0) {
    abort_unless(app(PilotOperationAuthorization::class)->allowsRentalDamage(
        $request, $trustedRentalId, $trustedHoldId, $damageChargeCents,
        'rentals.deposit.settle'
    ), 403);
}
// Con importe cero, aplicar la autorización de mostrador existente de E5.
```

Requiere el rol global `operador_servicios`, permiso `student_services.rentals.damage.charge` y TOTP reciente. No hereda admin/cashier ni habilita otras operaciones financieras. El importe debe ser un entero positivo en centavos; se rechazan strings, floats, importes negativos y otras operaciones. La auditoría vincula humano, sesión, operación, renta, retención e importe exacto. Una liberación sin cargo queda fuera de esta autoridad positiva: el método devuelve false para cero y el consumidor conserva su política de mostrador, sin exigir TOTP por esta ampliación.

El lugar mínimo para integrar la llamada es el backend E5 antes de registrar el cargo y solicitar la liquidación, usando la renta/retención y el importe calculados y validados por E5. La firma actual `StudentPaymentGateway::settleDeposit(holdReference, amountCents, reason, idempotencyKey)` no transporta al operador ni la renta. No se modificó ese contrato ni `POST /api/v1/financial/student-services/rentals/{rentalId}/deposit/{holdId}/settle`.

La decisión es síncrona, puntual y no portable. E5 debe conservar exactamente los parámetros comprobados y revalidar antes de una nueva ejecución; no debe tratar un booleano guardado como permiso futuro. Reintentos, procesamiento diferido o una llamada E5→E2 por HTTP no transportan una sesión humana acreditada por sí solos. E2/E5 deben acordar ese límite antes de habilitarlo; E1 no crea un token ni garantiza atomicidad entre MongoDB de identidad y SQL financiero.

## Concesiones y auditoría

Los nuevos roles/permisos son definiciones técnicas: no se añaden seeders, migraciones de concesión, bindings de autoridad ni endpoints administrativos. Los catálogos legacy, mappings financieros/BECA y autoridades existentes no cambian. Las nuevas asignaciones pasan por `AuthorizationLifecycleRecord` y sus transiciones, generaciones, revisiones y trazabilidad; `RoleAssignment` rechaza su creación y transiciones fuera del testing sintético explícito. Los fixtures son sintéticos y la ejecución persistente exige además las guardas E1 originales.

Finanzas y Servicios aún deben designar institucionalmente quién concede/revoca estas atribuciones. No se asignaron privilegios a personas reales. La evaluación de permisos no sustituye esa decisión.

## Validación y límites

Las pruebas unitarias nuevas no heredan `Tests\TestCase`, no arrancan Laravel ni usan MongoDB: ejercitan el proveedor TOTP real con caché en memoria, evidencia de sesión y política con dobles explícitos. Los casos de ciclo de vida reutilizan el filtro `EffectiveRoleAssignments::activeCurrent`. La validación original acreditó 90 pruebas puras con 210 aserciones y 12 pruebas Feature con 30 aserciones, utilizando Laravel real y MongoDB sintético aislado. La consulta del catálogo y la persistencia/auditoría se verificaron mediante `PilotOperationAuthorizationTest` sobre ese perfil acreditado. Esa prueba usa el TestCase protegido, crea sólo fixtures E1 y no ejecuta migraciones generales ni SQL.

Las pruebas de E1 no acreditan separación de personas, liquidación contable, reglas de renta o recepción real de E2/E5. Conectar los consumidores y validar esa integración queda pendiente de sus responsables y de un entorno aislado reacreditado.
