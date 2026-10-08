db = db.getSiblingDB('campus_digital');

const collections = [
  'users',
  'businesses',
  'business_members',
  'business_applications',
  'storefronts',
  'products',
  'carts',
  'orders',
  'payment_intents',
  'return_requests'
];

collections.forEach((name) => {
  if (!db.getCollectionNames().includes(name)) db.createCollection(name);
});

db.businesses.createIndex({ slug: 1 }, { unique: true });
db.products.createIndex({ slug: 1 });
db.products.createIndex({ business_id: 1, active: 1, available: 1 });
db.orders.createIndex({ folio: 1 }, { unique: true });
db.orders.createIndex({ buyer_id: 1, created_at: -1 });
db.payment_intents.createIndex({ idempotency_key: 1 }, { unique: true });
