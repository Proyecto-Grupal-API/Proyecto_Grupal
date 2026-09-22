// cleanup_undefined.js - Elimina documentos huerfanos con purchase_order_id "undefined"
const d = db.getSiblingDB("campus_digital");

const resultItems = d.purchase_order_items.deleteMany({ purchase_order_id: "undefined" });
print("Items huerfanos eliminados: " + resultItems.deletedCount);

const resultOrders = d.purchase_orders.deleteMany({
  $or: [
    { folio: { $exists: false } },
    { folio: null },
    { folio: "" }
  ]
});
print("OCs huerfanas eliminadas: " + resultOrders.deletedCount);

print("Items restantes: " + d.purchase_order_items.countDocuments({}));
print("OCs restantes: " + d.purchase_orders.countDocuments({}));
print("=== LIMPIEZA COMPLETA ===");
