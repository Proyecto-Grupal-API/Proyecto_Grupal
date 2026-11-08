// Datos de prueba para negocio activo, negocio aislado y souvenirs especializados.
// Ejecutar: mongosh "mongodb://127.0.0.1:27017" --file database/mongo/team4_specialized_demo.js
const d = db.getSiblingDB("campus_digital");
const now = new Date();
const defaultBusinessId = "BUS-CD-SOUV-001";
const secondBusinessId = "BUS-CD-SOUV-DEMO-002";

d.businesses.updateOne(
  { business_id: defaultBusinessId },
  {
    $setOnInsert: {
      business_id: defaultBusinessId,
      code: "CD-SOUV",
      name: "Tienda Campus Digital",
      type: "MIXED",
      active: true,
      created_at: now
    }
  },
  { upsert: true }
);
d.businesses.updateOne(
  { business_id: secondBusinessId },
  {
    $set: {
      code: "CD-SOUV-DEMO",
      name: "Tienda de demostracion multi-negocio",
      type: "MIXED",
      active: true,
      updated_at: now
    },
    $setOnInsert: { created_at: now }
  },
  { upsert: true }
);

function ensureCategory(businessId, slug, name) {
  d.categories.updateOne(
    { business_id: businessId, slug },
    {
      $setOnInsert: {
        _id: new ObjectId(),
        business_id: businessId,
        slug,
        name,
        active: true,
        created_at: now
      }
    },
    { upsert: true }
  );
}

function ensureStorage(businessId, warehouseCode, locationCode, name) {
  d.warehouses.updateOne(
    { business_id: businessId, code: warehouseCode },
    {
      $setOnInsert: {
        _id: new ObjectId(),
        business_id: businessId,
        code: warehouseCode,
        name,
        type: "MAIN",
        active: true,
        created_at: now
      }
    },
    { upsert: true }
  );
  const warehouse = d.warehouses.findOne({ business_id: businessId, code: warehouseCode });
  d.locations.updateOne(
    { business_id: businessId, code: locationCode },
    {
      $setOnInsert: {
        _id: new ObjectId(),
        business_id: businessId,
        warehouse_id: String(warehouse._id),
        code: locationCode,
        name,
        type: "STORAGE",
        capacity: 500,
        active: true,
        created_at: now
      }
    },
    { upsert: true }
  );
  return d.locations.findOne({ business_id: businessId, code: locationCode });
}

ensureCategory(defaultBusinessId, "souvenirs", "Souvenirs");
ensureCategory(secondBusinessId, "souvenirs", "Souvenirs de prueba");
const officialLocation = ensureStorage(
  defaultBusinessId,
  "ALM-DEMO-OFICIAL",
  "LOC-DEMO-OFICIAL",
  "Almacen de souvenirs oficiales"
);
const secondLocation = ensureStorage(
  secondBusinessId,
  "ALM-DEMO-MULTI",
  "LOC-DEMO-MULTI",
  "Ubicacion de negocio de prueba"
);

const officialProductId = new ObjectId("6f0000000000000000000001");
const officialSku = "SOUV-EDICION-OFICIAL-2026";
const officialBySku = d.products.findOne({ business_id: defaultBusinessId, sku: officialSku });
const officialById = d.products.findOne({ _id: officialProductId });
if ((officialBySku && String(officialBySku._id) !== String(officialProductId))
  || (officialById && (officialById.business_id !== defaultBusinessId || officialById.sku !== officialSku))) {
  throw new Error("Los identificadores demo de souvenirs ya pertenecen a otros productos.");
}
d.products.updateOne(
  { business_id: defaultBusinessId, sku: officialSku },
  {
    $setOnInsert: {
      _id: officialProductId,
      business_id: defaultBusinessId,
      sku: officialSku,
      name: "Sudadera oficial Campus 2026",
      description: "Edicion oficial de demostracion con tallas, colores y preventa.",
      category: "souvenirs",
      sizes: ["M", "G"],
      colors: ["Negro", "Azul"],
      seasons: ["Otono 2026"],
      collections: ["Generacion 2026"],
      packages: ["Kit de bienvenida"],
      preorder: {
        enabled: true,
        available_at: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000),
        limit: 24
      },
      official: true,
      cost: 420,
      price: 799,
      stock_min: 5,
      stock_max: 50,
      active: true,
      created_at: now
    }
  },
  { upsert: true }
);

const variants = [
  { id: "24c4f5463ef1d310d30e7426", size: "M", color: "Negro" },
  { id: "f0440370e67d2717a9c43e0f", size: "G", color: "Negro" },
  { id: "86977cce37eed77a1eedf8a9", size: "M", color: "Azul" },
  { id: "15171a2d5f8bf4ee2ad5a0bd", size: "G", color: "Azul" }
];
variants.forEach((variant) => {
  d.product_variants.updateOne(
    {
      business_id: defaultBusinessId,
      product_id: String(officialProductId),
      variant_id: variant.id
    },
    {
      $setOnInsert: {
        business_id: defaultBusinessId,
        product_id: String(officialProductId),
        variant_id: variant.id,
        sku: `${officialSku}-${variant.size}-${variant.color.toUpperCase()}`,
        size: variant.size,
        color: variant.color,
        active: true,
        created_at: now
      }
    },
    { upsert: true }
  );
  d.inventories.updateOne(
    {
      business_id: defaultBusinessId,
      product_id: String(officialProductId),
      variant_id: variant.id,
      location_id: String(officialLocation._id)
    },
    {
      $setOnInsert: {
        _id: new ObjectId(),
        business_id: defaultBusinessId,
        product_id: String(officialProductId),
        variant_id: variant.id,
        location_id: String(officialLocation._id),
        on_hand: 10,
        reserved: 0,
        available: 10,
        status: "AVAILABLE",
        created_at: now,
        updated_at: now
      }
    },
    { upsert: true }
  );
});

const secondProductId = new ObjectId("6f0000000000000000000002");
const secondBySku = d.products.findOne({ business_id: secondBusinessId, sku: "SOUV-MULTI-TEST-001" });
const secondById = d.products.findOne({ _id: secondProductId });
if ((secondBySku && String(secondBySku._id) !== String(secondProductId))
  || (secondById && (secondById.business_id !== secondBusinessId || secondById.sku !== "SOUV-MULTI-TEST-001"))) {
  throw new Error("Los identificadores demo multi-negocio ya pertenecen a otros productos.");
}
d.products.updateOne(
  { business_id: secondBusinessId, sku: "SOUV-MULTI-TEST-001" },
  {
    $setOnInsert: {
      _id: secondProductId,
      business_id: secondBusinessId,
      sku: "SOUV-MULTI-TEST-001",
      name: "Taza de prueba negocio dos",
      description: "Fixture para comprobar aislamiento por negocio.",
      category: "souvenirs",
      sizes: [],
      colors: [],
      seasons: ["Pruebas"],
      collections: ["Integracion"],
      packages: [],
      preorder: { enabled: false },
      official: false,
      cost: 35,
      price: 89,
      stock_min: 2,
      stock_max: 40,
      active: true,
      created_at: now
    }
  },
  { upsert: true }
);
d.inventories.updateOne(
  {
    business_id: secondBusinessId,
    product_id: String(secondProductId),
    variant_id: null,
    location_id: String(secondLocation._id)
  },
  {
    $setOnInsert: {
      _id: new ObjectId(),
      business_id: secondBusinessId,
      product_id: String(secondProductId),
      variant_id: null,
      location_id: String(secondLocation._id),
      on_hand: 20,
      reserved: 0,
      available: 20,
      status: "AVAILABLE",
      created_at: now,
      updated_at: now
    }
  },
  { upsert: true }
);

print("Fixtures de negocio y souvenirs especializados listos.");
