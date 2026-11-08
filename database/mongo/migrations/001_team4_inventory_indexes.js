const version = "001_team4_inventory_indexes";
const migrations = db.getCollection("team4_migrations");

function ensureIndex(collectionName, keys, options) {
    const collection = db.getCollection(collectionName);
    const keyNames = Object.keys(keys);
    const exists = collection.getIndexes().some((index) => {
        const existingNames = Object.keys(index.key);
        return existingNames.length === keyNames.length
            && keyNames.every((key, position) => existingNames[position] === key && index.key[key] === keys[key]);
    });
    if (!exists) {
        collection.createIndex(keys, options);
    }
}

if (!migrations.findOne({ _id: version })) {
    ensureIndex("inventories",
        { business_id: 1, product_id: 1, variant_id: 1, location_id: 1 },
        { name: "idx_inventory_business_product_variant_location" }
    );
    ensureIndex("stock_movements",
        { business_id: 1, product_id: 1, location_id: 1, created_at: -1 },
        { name: "idx_movements_product_location_date" }
    );
    ensureIndex("product_variants",
        { business_id: 1, product_id: 1, variant_id: 1 },
        { unique: true, name: "uniq_product_variant" }
    );
    ensureIndex("purchase_orders",
        { business_id: 1, status: 1, created_at: -1 },
        { name: "idx_purchase_orders_business_status" }
    );
    ensureIndex("stock_counts",
        { business_id: 1, status: 1, created_at: -1 },
        { name: "idx_stock_counts_business_status" }
    );

    migrations.insertOne({
        _id: version,
        applied_at: new Date(),
    });
}
