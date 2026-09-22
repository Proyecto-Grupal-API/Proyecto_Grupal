// cleanup_oc.js - Elimina OCs y items huerfanos o invalidos
const d = db.getSiblingDB("campus_digital");

// Borrar items con purchase_order_id "undefined" o que apunten a OCs que no existen
const invalidItems = d.purchase_order_items.deleteMany({
  $or: [
    { purchase_order_id: "undefined" },
    { purchase_order_id: null },
    { purchase_order_id: { $exists: false } }
  ]
});
print("Items invalidos eliminados: " + invalidItems.deletedCount);

// Obtener los IDs de OCs validas
const validOrderIds = d.purchase_orders.find({}, { _id: 1 }).toArray().map(o => String(o._id));

// Borrar items que apunten a OCs que ya no existen
const orphanItems = d.purchase_order_items.deleteMany({
  purchase_order_id: { $nin: validOrderIds }
});
print("Items huerfanos eliminados: " + orphanItems.deletedCount);

// Borrar OCs sin folio
const invalidOrders = d.purchase_orders.deleteMany({
  $or: [
    { folio: { $exists: false } },
    { folio: null },
    { folio: "" }
  ]
});
print("OCs invalidas eliminadas: " + invalidOrders.deletedCount);

print("Items restantes: " + d.purchase_order_items.countDocuments({}));
print("OCs restantes: " + d.purchase_orders.countDocuments({}));
print("=== LIMPIEZA COMPLETA ===");
