// Recupera el catalogo que respalda inventarios historicos sin cambiar stock,
// reservas, movimientos ni identificadores usados por otros modulos.
// Ejecutar desde la raiz: mongosh "mongodb://127.0.0.1:27017" --file database/mongo/team4_restore_legacy_products.js
const dbName = db.getSiblingDB("campus_digital");
const businessId = "BUS-CD-SOUV-001";
const now = new Date();
const inventoryBefore = dbName.inventories
  .find({ business_id: businessId })
  .sort({ _id: 1 })
  .toArray()
  .map(({ _id, product_id, location_id, on_hand, reserved, available }) => ({
    _id: String(_id),
    product_id,
    location_id,
    on_hand,
    reserved,
    available
  }));

const categories = [
  { slug: "souvenirs", name: "Souvenirs" },
  { slug: "papeleria", name: "Papeleria" },
  { slug: "electronica", name: "Electronica" },
  { slug: "accesorios", name: "Accesorios" },
  { slug: "ropa", name: "Ropa" },
  { slug: "ejemplo", name: "Ejemplo" }
];

categories.forEach((category) => {
  dbName.categories.updateOne(
    { business_id: businessId, slug: category.slug },
    {
      $setOnInsert: {
        _id: new ObjectId(),
        business_id: businessId,
        slug: category.slug,
        name: category.name,
        active: true,
        created_at: now,
        updated_at: now
      }
    },
    { upsert: true }
  );
});

const catalog = [
  {
    id: "6abffdf0fc843e4c680551ef",
    sku: "SOUV-TAZA-001",
    name: "Taza Campus Digital",
    category: "souvenirs",
    cost: 35,
    price: 89,
    stock_min: 20,
    stock_max: 100
  },
  {
    id: "6abffdf0fc843e4c680551f0",
    sku: "SOUV-PLAY-001",
    name: "Playera Campus Negra",
    category: "souvenirs",
    cost: 120,
    price: 299,
    stock_min: 15,
    stock_max: 80
  },
  {
    id: "6abffdf0fc843e4c680551f1",
    sku: "SOUV-GORR-001",
    name: "Gorra Campus Azul",
    category: "souvenirs",
    cost: 90,
    price: 199,
    stock_min: 10,
    stock_max: 60
  },
  {
    id: "6abffdf0fc843e4c680551f2",
    sku: "SOUV-TERM-001",
    name: "Termo Acero 500ml",
    category: "souvenirs",
    cost: 150,
    price: 349,
    stock_min: 10,
    stock_max: 50
  },
  {
    id: "6abffdf0fc843e4c680551f3",
    sku: "SOUV-CUAD-001",
    name: "Cuaderno Profesional",
    category: "souvenirs",
    cost: 45,
    price: 99,
    stock_min: 25,
    stock_max: 120
  },
  {
    id: "6abffdf0fc843e4c680551f4",
    sku: "PAP-LAPIZ-001",
    name: "Lapiz HB x12",
    category: "papeleria",
    cost: 25,
    price: 55,
    stock_min: 50,
    stock_max: 300
  },
  {
    id: "6abffdf0fc843e4c680551f5",
    sku: "PAP-FOLDER-001",
    name: "Folder Manila x25",
    category: "papeleria",
    cost: 60,
    price: 149,
    stock_min: 20,
    stock_max: 100
  },
  {
    id: "6abffdf0fc843e4c680551f6",
    sku: "PAP-HOJAS-001",
    name: "Hojas Blancas 500",
    category: "papeleria",
    cost: 55,
    price: 129,
    stock_min: 30,
    stock_max: 150
  },
  {
    id: "6abffdf0fc843e4c680551f7",
    sku: "ELE-CALC-001",
    name: "Calculadora Cientifica",
    category: "electronica",
    cost: 220,
    price: 499,
    stock_min: 5,
    stock_max: 30
  },
  {
    id: "6abffdf0fc843e4c680551f8",
    sku: "ACC-MOCH-001",
    name: "Mochila Escolar",
    category: "accesorios",
    cost: 180,
    price: 399,
    stock_min: 10,
    stock_max: 40
  }
];

catalog.push(
  {
    id: "6abd84ec1ee0da00490a9002",
    sku: "EJE-001",
    name: "Ejemplo",
    description: "Recuperado de ordenes y recepciones historicas.",
    category: "ejemplo",
    cost: 35
  },
  {
    id: "PROD-TEST-001",
    sku: "PROD-TEST-001",
    name: "Producto de prueba de inventario",
    description: "Registro de prueba; no se recupero metadata comercial adicional.",
    category: "ejemplo"
  }
);

let restored = 0;
catalog.forEach((product) => {
  const productId = /^[a-f0-9]{24}$/i.test(product.id)
    ? new ObjectId(product.id)
    : product.id;
  const existingById = dbName.products.findOne({ _id: productId });
  if (existingById && (
    existingById.business_id !== businessId ||
    existingById.sku !== product.sku
  )) {
    throw new Error(
      `El ID ${product.id} ya esta asociado a otro producto; no se modifico el catalogo.`
    );
  }

  const existingBySku = dbName.products.findOne({
    business_id: businessId,
    sku: product.sku
  });
  if (existingBySku && String(existingBySku._id) !== product.id) {
    throw new Error(
      `El SKU ${product.sku} ya esta asociado a otro producto; no se modifico el catalogo.`
    );
  }

  const category = dbName.categories.findOne({
    business_id: businessId,
    slug: product.category
  });
  const productDocument = {
    _id: productId,
    business_id: businessId,
    sku: product.sku,
    name: product.name,
    description: product.description || "Producto de demostracion recuperado del catalogo del Equipo 4.",
    category: product.category,
    category_id: category ? String(category._id) : null,
    sizes: [],
    colors: [],
    seasons: [],
    collections: [],
    packages: [],
    preorder: { enabled: false },
    active: true,
    created_at: now
  };
  ["cost", "price", "stock_min", "stock_max"].forEach((field) => {
    if (product[field] !== undefined) {
      productDocument[field] = product[field];
    }
  });

  dbName.products.updateOne(
    { _id: productId },
    {
      $setOnInsert: productDocument
    },
    { upsert: true }
  );
  restored++;
});

const inventoryAfter = dbName.inventories
  .find({ business_id: businessId })
  .sort({ _id: 1 })
  .toArray()
  .map(({ _id, product_id, location_id, on_hand, reserved, available }) => ({
    _id: String(_id),
    product_id,
    location_id,
    on_hand,
    reserved,
    available
  }));
if (JSON.stringify(inventoryBefore) !== JSON.stringify(inventoryAfter)) {
  throw new Error("La restauracion modifico datos de inventario; se requiere revision.");
}

const referencedIds = dbName.inventories.distinct("product_id", { business_id: businessId });
const unresolved = referencedIds.filter((id) => !dbName.products.findOne({
  business_id: businessId,
  $expr: { $eq: [{ $toString: "$_id" }, String(id)] }
}));

print(`Catalogo restaurado de forma idempotente: ${restored} productos procesados.`);
print(`Referencias de inventario sin producto: ${unresolved.length}`);
if (unresolved.length > 0) {
  printjson(unresolved);
  quit(2);
}
