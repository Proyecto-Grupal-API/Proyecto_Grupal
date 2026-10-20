// Inicialización IDEMPOTENTE del replica set rs0 (un solo nodo).
// Las transacciones multi-documento de MongoDB (usadas por PointsLedgerService)
// solo funcionan en replica set. Este script puede ejecutarse N veces:
//   - si rs0 ya existe, solo espera a que haya PRIMARY;
//   - si no existe, lo inicializa.
const host = process.env.MONGO_RS_HOST || 'mongodb:27017';

let initialized = false;
try {
  const status = rs.status();
  initialized = status.ok === 1;
  print(`[rs-init] rs0 ya inicializado (set=${status.set}).`);
} catch (e) {
  if (e.codeName === 'NotYetInitialized' || /no replset config/i.test(String(e.message))) {
    print('[rs-init] rs0 no inicializado; ejecutando rs.initiate()...');
    rs.initiate({ _id: 'rs0', members: [{ _id: 0, host }] });
  } else {
    throw e;
  }
}

// Espera hasta 60 s a que el nodo sea PRIMARY (necesario para escrituras/transacciones).
for (let i = 0; i < 60; i++) {
  try {
    const st = rs.status();
    const primary = st.members.find((m) => m.stateStr === 'PRIMARY');
    if (primary) {
      print(`[rs-init] OK: PRIMARY=${primary.name}, myState=${st.myState}`);
      quit(0);
    }
  } catch (e) { /* aún arrancando */ }
  sleep(1000);
}
print('[rs-init] ERROR: rs0 no alcanzó estado PRIMARY a tiempo');
quit(1);
