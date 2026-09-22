const d = db.getSiblingDB("campus_digital");
print("Items OC eliminados: " + d.purchase_order_items.deleteMany({}).deletedCount);
print("OCs eliminadas: " + d.purchase_orders.deleteMany({}).deletedCount);
print("Receipts eliminados: " + d.goods_receipts.deleteMany({}).deletedCount);
print("ReceiptItems eliminados: " + d.goods_receipt_items.deleteMany({}).deletedCount);
print("=== LIMPIEZA COMPLETA ===");
