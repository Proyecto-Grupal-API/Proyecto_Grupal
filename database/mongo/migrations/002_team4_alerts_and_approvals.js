const version = "002_team4_alerts_and_approvals";
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
    ensureIndex("stock_alerts",
        { business_id: 1, type: 1, status: 1, product_id: 1, location_id: 1 },
        { name: "idx_stock_alerts_active_scope" }
    );
    ensureIndex("stock_counts",
        { business_id: 1, status: 1, submitted_at: -1 },
        { name: "idx_stock_counts_pending_approval" }
    );
    ensureIndex("stock_movements",
        { business_id: 1, product_id: 1, created_at: -1 },
        { name: "idx_movements_product_activity" }
    );

    migrations.insertOne({
        _id: version,
        applied_at: new Date(),
    });
}
