// Campus Digital - Equipo 4
// Seed de desarrollo. Los IDs de negocio usados por las APIs son strings
// estables y no ObjectId.
//
// Ejecutar:
// mongosh "mongodb://127.0.0.1:27017" database/mongo/team4_seed.js

const d = db.getSiblingDB("campus_digital");
const now = new Date();

const BUSINESS_ID = "BUS-CD-SOUV-001";

d.businesses.updateOne(
  { code: "CD-SOUV" },
  {
    $set: {
      business_id: BUSINESS_ID,
      code: "CD-SOUV",
      name: "Tienda Campus Digital",
      type: "MIXED",
      active: true,
      updated_at: now
    },
    $setOnInsert: { created_at: now }
  },
  { upsert: true }
);

const products = [
  {
    sku: "PR-001",
    name: "Playera Campus Digital",
    category: "Ropa",
    active: true,
    stock_min: 10,
    stock_max: 100,
    business_id: BUSINESS_ID
  },
  {
    sku: "PR-002",
    name: "Taza Campus Digital",
    category: "Souvenirs",
    active: true,
    stock_min: 8,
    stock_max: 60,
    business_id: BUSINESS_ID
  },
  {
    sku: "PR-003",
    name: "Libreta institucional",
    category: "Papelería",
    active: true,
    stock_min: 15,
    stock_max: 120,
    business_id: BUSINESS_ID
  }
];

products.forEach(p => d.products.updateOne(
  { sku: p.sku },
  {
    $set: { ...p, updated_at: now },
    $setOnInsert: { created_at: now }
  },
  { upsert: true }
));

const warehouseData = [
  { code: "ALM-001", name: "Almacén principal", type: "MAIN" },
  { code: "ALM-002", name: "Bodega souvenirs", type: "STORAGE" }
];

warehouseData.forEach(w => d.warehouses.updateOne(
  { business_id: BUSINESS_ID, code: w.code },
  {
    $set: {
      business_id: BUSINESS_ID,
      code: w.code,
      name: w.name,
      type: w.type,
      active: true,
      updated_at: now
    },
    $setOnInsert: { created_at: now }
  },
  { upsert: true }
));

const warehouses = d.warehouses.find({ business_id: BUSINESS_ID }).toArray();

warehouses.forEach((w, i) => {
  d.locations.updateOne(
    { warehouse_id: String(w._id), code: "LOC-" + String(i + 1).padStart(3, "0") },
    {
      $set: {
        warehouse_id: String(w._id),
        business_id: BUSINESS_ID,
        code: "LOC-" + String(i + 1).padStart(3, "0"),
        name: i === 0 ? "Zona general" : "Estantería souvenirs",
        type: i === 0 ? "STORAGE" : "DISPLAY",
        capacity: 200,
        active: true,
        updated_at: now
      },
      $setOnInsert: { created_at: now }
    },
    { upsert: true }
  );
});

const locations = d.locations.find({ business_id: BUSINESS_ID }).toArray();
const ps = d.products.find({ business_id: BUSINESS_ID }).toArray();

ps.forEach((p, i) => {
  const loc = locations[i % locations.length];
  const qty = [35, 22, 50][i];

  d.inventories.updateOne(
    {
      business_id: BUSINESS_ID,
      product_id: String(p._id),
      variant_id: null,
      location_id: String(loc._id)
    },
    {
      $set: {
        business_id: BUSINESS_ID,
        product_id: String(p._id),
        variant_id: null,
        location_id: String(loc._id),
        on_hand: qty,
        reserved: 0,
        available: qty,
        status: "AVAILABLE",
        updated_at: now
      },
      $setOnInsert: { created_at: now }
    },
    { upsert: true }
  );
});

const firstProduct = d.products.findOne({ business_id: BUSINESS_ID, sku: "PR-001" });
const firstLocation = d.locations.findOne({ business_id: BUSINESS_ID });

d.supplier_returns.updateOne(
  { business_id: BUSINESS_ID, folio: "DEV-PROV-0001" },
  {
    $setOnInsert: {
      business_id: BUSINESS_ID,
      folio: "DEV-PROV-0001",
      supplier_id: null,
      status: "CONFIRMED",
      reason: "Producto defectuoso",
      source_type: "PURCHASE_ORDER",
      source_reference: "OC-00025",
      created_by: "USR-ADMIN-001",
      created_at: now,
      updated_at: now
    }
  },
  { upsert: true }
);

d.customer_returns.updateOne(
  { business_id: BUSINESS_ID, folio: "DEV-CLI-0001" },
  {
    $setOnInsert: {
      business_id: BUSINESS_ID,
      folio: "DEV-CLI-0001",
      customer_id: "ALU-0001",
      customer_type: "ALUMNO",
      status: "RECEIVED",
      reason: "Producto no deseado",
      sale_reference: "VENTA-00041",
      resolution: "RESTOCK",
      created_by: "USR-ADMIN-001",
      created_at: now,
      updated_at: now
    }
  },
  { upsert: true }
);

const cr = d.customer_returns.findOne({
  business_id: BUSINESS_ID,
  folio: "DEV-CLI-0001"
});

if (firstProduct && firstLocation) {
  d.customer_return_items.updateOne(
    { customer_return_id: String(cr._id), line: 1 },
    {
      $setOnInsert: {
        customer_return_id: String(cr._id),
        line: 1,
        product_id: String(firstProduct._id),
        variant_id: null,
        location_id: String(firstLocation._id),
        quantity: 1,
        condition: "GOOD",
        resolution: "RESTOCK"
      }
    },
    { upsert: true }
  );
}

print("Seed de Equipo 4 completado.");
