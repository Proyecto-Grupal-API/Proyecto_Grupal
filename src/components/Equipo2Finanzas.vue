<template>
  <div class="app-finanzas">
    <!-- BARRA LATERAL DEL EQUIPO 2 -->
    <aside>
      <div class="brand">Campus Digital</div>
      <div class="sub">Equipo 2 · Finanzas / Wallet</div>
      <nav>
        <button :class="{ active: moduloActivo === 'dashboard' }" @click="moduloActivo = 'dashboard'">🏠 Dashboard</button>
        <button :class="{ active: moduloActivo === 'wallet' }" @click="moduloActivo = 'wallet'">💳 2.1 Wallet</button>
        <button :class="{ active: moduloActivo === 'ledger' }" @click="moduloActivo = 'ledger'">📒 2.2 Ledger</button>
        <button :class="{ active: moduloActivo === 'topups' }" @click="moduloActivo = 'topups'">➕ 2.3 Recargas</button>
        <button :class="{ active: moduloActivo === 'withdrawals' }" @click="moduloActivo = 'withdrawals'">💵 2.4 Retiros</button>
        <button :class="{ active: moduloActivo === 'bonuses' }" @click="moduloActivo = 'bonuses'">🎁 2.5 Bonos</button>
        <button :class="{ active: moduloActivo === 'transfers' }" @click="moduloActivo = 'transfers'">↔ 2.6 Transferencias</button>
        <button :class="{ active: moduloActivo === 'reversals' }" @click="moduloActivo = 'reversals'">↩ 2.7 Reversos</button>
        <button :class="{ active: moduloActivo === 'cash' }" @click="moduloActivo = 'cash'">🏦 2.8 Caja y turnos</button>
        <button :class="{ active: moduloActivo === 'tickets' }" @click="moduloActivo = 'tickets'">🧾 2.9 Tickets</button>
        <button :class="{ active: moduloActivo === 'limits' }" @click="moduloActivo = 'limits'">📊 2.10 Límites / Conciliación</button>
      </nav>
    </aside>

    <!-- CONTENIDO PRINCIPAL -->
    <main>
      <!-- SECTION: DASHBOARD -->
      <section v-if="moduloActivo === 'dashboard'">
        <div class="top">
          <div>
            <h2>Centro financiero</h2>
            <div class="muted">Prototipo V2 — todos los módulos del Equipo 2</div>
          </div>
          <span class="badge">DEMO ACTIVA</span>
        </div>
        <div class="cards">
          <div class="card">
            <div class="label">Saldo disponible</div>
            <div class="money">{{ money(balance) }}</div>
          </div>
          <div class="card">
            <div class="label">Saldo retenido</div>
            <div class="money">$0.00</div>
          </div>
          <div class="card">
            <div class="label">Movimientos</div>
            <div class="money">{{ entries.length }}</div>
          </div>
          <div class="card">
            <div class="label">Estado caja</div>
            <div class="money" style="font-size:18px">ABIERTA</div>
          </div>
        </div>
        <div class="grid">
          <div class="panel">
            <h3>Actividad reciente</h3>
            <table>
              <thead>
                <tr>
                  <th>Folio</th>
                  <th>Tipo</th>
                  <th>Dirección</th>
                  <th>Monto</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in reversedEntries" :key="e.id">
                  <td>{{ e.ref }}</td>
                  <td>{{ e.type }}</td>
                  <td :class="e.dir === 'CREDIT' ? 'credit' : 'debit'">{{ e.dir }}</td>
                  <td :class="e.dir === 'CREDIT' ? 'credit' : 'debit'">
                    {{ e.dir === 'CREDIT' ? '+' : '-' }}{{ money(e.amount) }}
                  </td>
                  <td><span class="badge">{{ e.status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="panel">
            <h3>Acciones</h3>
            <div class="actions">
              <button class="primary" @click="moduloActivo = 'topups'">Recargar</button>
              <button class="secondary" @click="moduloActivo = 'withdrawals'">Retirar</button>
              <button class="secondary" @click="moduloActivo = 'transfers'">Transferir</button>
              <button class="secondary" @click="moduloActivo = 'bonuses'">Bonos</button>
            </div>
            <hr style="border:0;border-top:1px solid #eee;margin:18px 0">
            <div class="muted">Usuario demo: <b>20260001</b><br>Moneda: MXN · Wallet ACTIVA</div>
          </div>
        </div>
      </section>

      <!-- SECTION: WALLET -->
      <section v-if="moduloActivo === 'wallet'">
        <div class="top">
          <div>
            <h2>2.1 Cuentas Wallet</h2>
            <div class="muted">Saldo disponible, retenido y estado de la cuenta</div>
          </div>
        </div>
        <div class="cards">
          <div class="card"><div class="label">Disponible</div><div class="money">{{ money(balance) }}</div></div>
          <div class="card"><div class="label">Retenido</div><div class="money">$0.00</div></div>
          <div class="card"><div class="label">Moneda</div><div class="money">MXN</div></div>
          <div class="card"><div class="label">Estado</div><div class="money" style="font-size:18px">ACTIVA</div></div>
        </div>
        <div class="panel">
          <h3>Datos de wallet</h3>
          <table>
            <tbody>
              <tr><th>ID Wallet</th><td>WAL-00001</td></tr>
              <tr><th>Propietario</th><td>Estudiante 20260001</td></tr>
              <tr><th>Cuenta</th><td>DIGITAL</td></tr>
              <tr><th>Saldo</th><td>{{ money(balance) }}</td></tr>
              <tr><th>Estado</th><td><span class="badge">ACTIVA</span></td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- SECTION: LEDGER -->
      <section v-if="moduloActivo === 'ledger'">
        <div class="top">
          <div>
            <h2>2.2 Ledger de movimientos</h2>
            <div class="muted">Fuente de verdad de las operaciones financieras</div>
          </div>
        </div>
        <div class="notice">Cada operación genera una referencia, estado y entrada de ledger. Las operaciones demo no se eliminan del historial.</div>
        <div class="panel">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Referencia</th>
                <th>Tipo</th>
                <th>Dirección</th>
                <th>Monto</th>
                <th>Descripción</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in reversedEntries" :key="e.id">
                <td>{{ e.id }}</td>
                <td>{{ e.ref }}</td>
                <td>{{ e.type }}</td>
                <td>{{ e.dir }}</td>
                <td>{{ money(e.amount) }}</td>
                <td>{{ e.desc }}</td>
                <td><span class="badge">{{ e.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- SECTION: TOPUPS -->
      <section v-if="moduloActivo === 'topups'">
        <div class="top">
          <div>
            <h2>2.3 Recargas</h2>
            <div class="muted">Entrada de fondos a la wallet</div>
          </div>
        </div>
        <div class="panel">
          <div class="form">
            <label>Monto (MXN)<input v-model.number="formTopup.amt" type="number" min="1" step=".01" placeholder="500"></label>
            <label>Método
              <select v-model="formTopup.method">
                <option>Efectivo</option>
                <option>Transferencia</option>
                <option>Otro medio autorizado</option>
              </select>
            </label>
            <label>Referencia externa<input v-model="formTopup.refExt" placeholder="Opcional"></label>
            <button class="primary" @click="doTopup">Confirmar recarga</button>
          </div>
        </div>
      </section>

      <!-- SECTION: WITHDRAWALS -->
      <section v-if="moduloActivo === 'withdrawals'">
        <div class="top">
          <div>
            <h2>2.4 Retiros</h2>
            <div class="muted">Salida de fondos y validación de saldo/límites</div>
          </div>
        </div>
        <div class="panel">
          <div class="form">
            <label>Monto (MXN)<input v-model.number="formWith.amt" type="number" min="1" step=".01" placeholder="100"></label>
            <label>Agente / caja
              <select v-model="formWith.agent">
                <option>Caja 001 · Agente demo</option>
                <option>Caja 002 · Agente demo</option>
              </select>
            </label>
            <label>Motivo<input v-model="formWith.reason" placeholder="Retiro de efectivo"></label>
            <button class="primary" @click="doWithdraw">Solicitar retiro</button>
          </div>
        </div>
      </section>

      <!-- SECTION: BONUSES -->
      <section v-if="moduloActivo === 'bonuses'">
        <div class="top">
          <div>
            <h2>2.5 Bonos</h2>
            <div class="muted">Bonos con vigencia y restricciones; no retirables</div>
          </div>
          <button class="primary" @click="showBonusModal = true">Asignar bono</button>
        </div>
        <div class="grid3">
          <div class="card"><div class="label">Bono alimentación</div><div class="money">$800.00</div><div class="muted">Vigencia: 30 días · No retirable</div></div>
          <div class="card"><div class="label">Bono transporte</div><div class="money">$300.00</div><div class="muted">Vigencia: 15 días · No retirable</div></div>
          <div class="card"><div class="label">Bono demo</div><div class="money">{{ money(bonusTotal) }}</div><div class="muted">Asignaciones realizadas en esta sesión</div></div>
        </div>
        <div class="panel" style="margin-top:17px">
          <h3>Bonos asignados</h3>
          <table>
            <thead>
              <tr>
                <th>Folio</th>
                <th>Categoría</th>
                <th>Monto</th>
                <th>Vigencia</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in reversedBonuses" :key="b.ref">
                <td>{{ b.ref }}</td>
                <td>{{ b.cat }}</td>
                <td>{{ money(b.amount) }}</td>
                <td>{{ b.days }}</td>
                <td><span class="badge">ACTIVO</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- SECTION: TRANSFERS -->
      <section v-if="moduloActivo === 'transfers'">
        <div class="top">
          <div>
            <h2>2.6 Transferencias</h2>
            <div class="muted">Transferencia entre usuarios, sujeta a saldo y límites</div>
          </div>
        </div>
        <div class="panel">
          <div class="form">
            <label>Destinatario / matrícula<input v-model="formTr.recipient" placeholder="20260002"></label>
            <label>Monto (MXN)<input v-model.number="formTr.amt" type="number" min="1" step=".01" placeholder="100"></label>
            <label>Concepto<input v-model="formTr.concept" placeholder="Transferencia entre estudiantes"></label>
            <button class="primary" @click="doTransfer">Confirmar transferencia</button>
          </div>
        </div>
      </section>

      <!-- SECTION: REVERSALS -->
      <section v-if="moduloActivo === 'reversals'">
        <div class="top">
          <div>
            <h2>2.7 Retenciones, devoluciones y reversos</h2>
            <div class="muted">Correcciones sin eliminar la trazabilidad</div>
          </div>
        </div>
        <div class="grid3">
          <div class="panel">
            <h3>Retención</h3>
            <p class="muted">Simula fondos retenidos antes de una operación definitiva.</p>
            <button class="secondary" @click="alert('Demo: retención creada por $50.00')">Crear retención</button>
          </div>
          <div class="panel">
            <h3>Devolución</h3>
            <p class="muted">Genera un crédito asociado a una operación anterior.</p>
            <button class="secondary" @click="doRefund">Devolver $70</button>
          </div>
          <div class="panel">
            <h3>Reverso</h3>
            <p class="muted">Crea una operación compensatoria; no borra la original.</p>
            <button class="secondary" @click="doReverse">Reversar última operación</button>
          </div>
        </div>
        <div class="panel" style="margin-top:17px">
          <h3>Regla de trazabilidad</h3>
          <p class="muted">Las operaciones originales permanecen en el historial y las correcciones generan nuevas referencias.</p>
        </div>
      </section>

      <!-- SECTION: CASH -->
      <section v-if="moduloActivo === 'cash'">
        <div class="top">
          <div>
            <h2>2.8 Caja y turnos</h2>
            <div class="muted">Apertura, movimientos, cierre y arqueo</div>
          </div>
          <span class="badge">TURNO ABIERTO</span>
        </div>
        <div class="cards">
          <div class="card"><div class="label">Caja</div><div class="money">#001</div></div>
          <div class="card"><div class="label">Fondo inicial</div><div class="money">$2,000</div></div>
          <div class="card"><div class="label">Efectivo esperado</div><div class="money">$2,430</div></div>
          <div class="card"><div class="label">Diferencia</div><div class="money">$0</div></div>
        </div>
        <div class="grid">
          <div class="panel">
            <h3>Movimientos de caja</h3>
            <table>
              <thead>
                <tr><th>Tipo</th><th>Concepto</th><th>Monto</th></tr>
              </thead>
              <tbody>
                <tr><td>ENTRADA</td><td>Recarga</td><td class="credit">+$500</td></tr>
                <tr><td>SALIDA</td><td>Retiro</td><td class="debit">-$70</td></tr>
              </tbody>
            </table>
          </div>
          <div class="panel">
            <h3>Turno</h3>
            <p class="muted">Cajero: Usuario demo<br>Inicio: 08:00<br>Estado: ABIERTO</p>
            <div class="actions">
              <button class="secondary" @click="alert('Demo: arqueo realizado. Diferencia $0.00')">Realizar arqueo</button>
              <button class="danger" @click="alert('Demo: turno cerrado correctamente')">Cerrar turno</button>
            </div>
          </div>
        </div>
      </section>

      <!-- SECTION: TICKETS -->
      <section v-if="moduloActivo === 'tickets'">
        <div class="top">
          <div>
            <h2>2.9 Tickets y comprobantes</h2>
            <div class="muted">Folio y detalle de operaciones</div>
          </div>
        </div>
        <div class="grid">
          <div class="panel">
            <h3>Último comprobante</h3>
            <div class="receipt">{{ receiptText }}</div>
          </div>
          <div class="panel">
            <h3>Verificación</h3>
            <p class="muted">En una implementación completa, aquí se podría incorporar QR o código de verificación asociado al folio.</p>
            <input placeholder="Folio a verificar">
            <button class="primary" style="margin-top:10px" @click="alert('Demo: comprobante válido')">Verificar</button>
          </div>
        </div>
      </section>

      <!-- SECTION: LIMITS -->
      <section v-if="moduloActivo === 'limits'">
        <div class="top">
          <div>
            <h2>2.10 Límites, alertas y conciliación</h2>
            <div class="muted">Controles operativos del Equipo 2</div>
          </div>
        </div>
        <div class="grid">
          <div class="panel">
            <h3>Límites de demostración</h3>
            <div class="kpi">Recarga diaria <b>$5,000 / $10,000</b><div class="bar"><div class="fill" style="width:50%"></div></div></div>
            <div class="kpi">Transferencias <b>$1,000 / $2,000</b><div class="bar"><div class="fill" style="width:50%"></div></div></div>
            <div class="kpi">Retiros <b>$0 / $2,000</b><div class="bar"><div class="fill" style="width:3%"></div></div></div>
            <div class="notice">Los valores son provisionales para la demostración y deben sustituirse por las políticas reales del proyecto.</div>
          </div>
          <div class="panel">
            <h3>Conciliación</h3>
            <table>
              <tbody>
                <tr><th>Concepto</th><td>Valor</td></tr>
                <tr><th>Efectivo esperado</th><td>$2,430.00</td></tr>
                <tr><th>Efectivo contado</th><td>$2,430.00</td></tr>
                <tr><th>Diferencia</th><td class="credit">$0.00</td></tr>
                <tr><th>Estado</th><td><span class="badge">CONCILIADO</span></td></tr>
              </tbody>
            </table>
            <button class="primary" style="margin-top:14px" @click="alert('Conciliación guardada como demostración')">Guardar conciliación</button>
          </div>
        </div>
        <div class="panel" style="margin-top:17px">
          <h3>Alertas</h3>
          <table>
            <tbody>
              <tr><th>Tipo</th><th>Descripción</th><th>Estado</th></tr>
              <tr><td>OPERATIVA</td><td>Sin diferencias de caja</td><td><span class="badge">NORMAL</span></td></tr>
              <tr><td>LÍMITE</td><td>No se han superado límites</td><td><span class="badge">NORMAL</span></td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>

    <!-- MODAL BONOS -->
    <div class="modal" :class="{ open: showBonusModal }">
      <div class="modalbox">
        <h3>Asignar bono</h3>
        <div class="form">
          <label>Categoría<input v-model="formBonus.cat"></label>
          <label>Monto<input v-model.number="formBonus.amt" type="number"></label>
          <label>Vigencia
            <select v-model="formBonus.days">
              <option>15 días</option>
              <option>30 días</option>
              <option>60 días</option>
            </select>
          </label>
          <div class="actions">
            <button class="primary" @click="addBonus">Asignar</button>
            <button class="secondary" @click="showBonusModal = false">Cancelar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import '../assets/finanzas.css';

// Estado de navegación
const moduloActivo = ref('dashboard');

// Datos reactivos
const seq = ref(4);
const bonusTotal = ref(0);
const showBonusModal = ref(false);

const entries = ref([
  { id: 1, ref: 'TOPUP-0001', type: 'RECARGA', dir: 'CREDIT', amount: 500, desc: 'Recarga inicial', status: 'COMPLETED' },
  { id: 2, ref: 'PUR-0001', type: 'COMPRA', dir: 'DEBIT', amount: 70, desc: 'Compra de demostración', status: 'COMPLETED' },
  { id: 3, ref: 'REF-0001', type: 'DEVOLUCIÓN', dir: 'CREDIT', amount: 70, desc: 'Devolución de compra', status: 'COMPLETED' }
]);

const bonuses = ref([]);

const receiptText = ref(`CAMPUS DIGITAL
COMPROBANTE FINANCIERO
------------------------------
Folio: TOPUP-0001
Tipo: RECARGA
Usuario: 20260001
Monto: $500.00
Método: EFECTIVO
Estado: COMPLETED
------------------------------
Este es un comprobante de demostración.`);

// Formularios
const formTopup = ref({ amt: null, method: 'Efectivo', refExt: '' });
const formWith = ref({ amt: null, agent: 'Caja 001 · Agente demo', reason: '' });
const formTr = ref({ recipient: '', amt: null, concept: '' });
const formBonus = ref({ cat: 'Bono alimentación', amt: 100, days: '30 días' });

// Propiedades computadas
const balance = computed(() => {
  return entries.value.reduce((s, e) => s + (e.dir === 'CREDIT' ? e.amount : -e.amount), 0);
});

const reversedEntries = computed(() => [...entries.value].reverse());
const reversedBonuses = computed(() => [...bonuses.value].reverse());

// Funciones
function money(n) {
  return '$' + Number(n || 0).toFixed(2);
}

function generateRef(prefix) {
  const num = String(seq.value++).padStart(4, '0');
  return `${prefix}-${num}`;
}

function addEntry(type, dir, amount, desc, prefix) {
  const r = generateRef(prefix);
  entries.value.push({
    id: entries.value.length + 1,
    ref: r,
    type,
    dir,
    amount: Number(amount),
    desc,
    status: 'COMPLETED'
  });

  receiptText.value = `CAMPUS DIGITAL
COMPROBANTE FINANCIERO
------------------------------
Folio: ${r}
Tipo: ${type}
Usuario: 20260001
Monto: ${money(amount)}
Estado: COMPLETED
------------------------------
Demostración del Equipo 2`;

  return r;
}

function doTopup() {
  const a = formTopup.value.amt;
  if (!a || a <= 0) return alert('Ingresa un monto válido.');
  const r = addEntry('RECARGA', 'CREDIT', a, 'Recarga ' + formTopup.value.method, 'TOPUP');
  formTopup.value.amt = null;
  formTopup.value.refExt = '';
  alert('Recarga registrada. Folio: ' + r);
  moduloActivo.value = 'dashboard';
}

function doWithdraw() {
  const a = formWith.value.amt;
  if (!a || a <= 0) return alert('Ingresa un monto válido.');
  if (a > balance.value) return alert('Saldo insuficiente.');
  const r = addEntry('RETIRO', 'DEBIT', a, formWith.value.reason || 'Retiro de efectivo', 'WITH');
  formWith.value.amt = null;
  formWith.value.reason = '';
  alert('Retiro registrado. Folio: ' + r);
  moduloActivo.value = 'dashboard';
}

function doTransfer() {
  const to = formTr.value.recipient.trim();
  const a = formTr.value.amt;
  if (!to || !a || a <= 0) return alert('Completa destinatario y monto.');
  if (a > balance.value) return alert('Saldo insuficiente.');
  const r = addEntry('TRANSFERENCIA', 'DEBIT', a, 'Transferencia a ' + to, 'TRF');
  formTr.value.recipient = '';
  formTr.value.amt = null;
  formTr.value.concept = '';
  alert('Transferencia simulada. Folio: ' + r);
  moduloActivo.value = 'dashboard';
}

function doRefund() {
  const a = 70;
  const r = addEntry('DEVOLUCIÓN', 'CREDIT', a, 'Devolución de operación', 'REF');
  alert('Devolución registrada. Folio: ' + r);
  moduloActivo.value = 'dashboard';
}

function doReverse() {
  if (!entries.value.length) return;
  const last = entries.value[entries.value.length - 1];
  const dir = last.dir === 'CREDIT' ? 'DEBIT' : 'CREDIT';
  const r = addEntry('REVERSO', dir, last.amount, 'Reverso de ' + last.ref, 'REV');
  alert('Reverso registrado. Folio: ' + r);
  moduloActivo.value = 'dashboard';
}

function addBonus() {
  const a = formBonus.value.amt;
  const cat = formBonus.value.cat;
  if (!a || a <= 0 || !cat) return alert('Completa los datos.');
  const r = generateRef('BONUS');
  bonuses.value.push({
    ref: r,
    cat,
    amount: a,
    days: formBonus.value.days
  });
  bonusTotal.value += a;
  showBonusModal.value = false;
  alert('Bono asignado. Folio: ' + r);
}
</script>