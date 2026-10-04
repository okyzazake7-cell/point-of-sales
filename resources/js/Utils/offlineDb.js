import { openDB } from "idb";

const DB_NAME = "pos-offline";
const DB_VERSION = 2;

/**
 * Queue record shape (v2):
 * {
 *   id,                       // auto-increment key
 *   data,                     // canonical offline transaction payload
 *   client_uuid,              // stable UUID for idempotency
 *   scope_key,                // user + active warehouse isolation key
 *   status,                   // pending | syncing | synced | duplicate | conflict | failed
 *   attempts,                 // flush attempts so far
 *   last_error,               // server reason on last failure
 *   last_attempt_at,          // ISO timestamp of last sync attempt
 *   synced_at,                // ISO timestamp of confirmed sync
 *   created_at,               // ISO timestamp queued
 * }
 *
 * v1 records (no metadata) are normalized lazily on read/write so an
 * upgrade can never corrupt or drop queued sales.
 */

function normalizeQueueRecord(record) {
    return {
        ...record,
        client_uuid: record.client_uuid ?? record.data?.client_uuid ?? null,
        status: record.status ?? "pending",
        scope_key: record.scope_key ?? null,
        attempts: record.attempts ?? 0,
        last_error: record.last_error ?? null,
        last_attempt_at: record.last_attempt_at ?? null,
        synced_at: record.synced_at ?? null,
        created_at: record.created_at ?? new Date().toISOString(),
    };
}

/**
 * Mode banyak toko (Aishii POS, dokumen 24 §3 / AS12): SATU basis data
 * IndexedDB per toko. Nomor pengguna dan nomor gudang berulang di tiap toko,
 * jadi kunci cakupan `${user}:${gudang}` saja membuat penjualan luring toko A
 * yang belum terkirim ikut terkirim ke toko B begitu kasir B masuk di
 * perangkat yang sama — dan daftar produk luring kedua toko saling menimpa.
 * Mode satu toko tetap memakai nama hulu `pos-offline`, jadi antrean yang
 * sudah ada di perangkat tidak tertinggal.
 */
let tokoAktif = null;
let terbuka = { nama: null, janji: null };

export function setOfflineStore(tokoId) {
    tokoAktif = tokoId ? String(tokoId) : null;
}

export function offlineDbName() {
    return tokoAktif ? `${DB_NAME}-t${tokoAktif}` : DB_NAME;
}

function getDb() {
    const nama = offlineDbName();
    if (terbuka.nama !== nama) {
        terbuka = { nama, janji: bukaDb(nama) };
    }
    return terbuka.janji;
}

function bukaDb(nama) {
    return openDB(nama, DB_VERSION, {
        upgrade(db) {
            if (!db.objectStoreNames.contains("products")) {
                db.createObjectStore("products", { keyPath: "id" });
            }
            if (!db.objectStoreNames.contains("customers")) {
                db.createObjectStore("customers", { keyPath: "id" });
            }
            if (!db.objectStoreNames.contains("pricing")) {
                db.createObjectStore("pricing", { keyPath: "id" });
            }
            if (!db.objectStoreNames.contains("pending_transactions")) {
                db.createObjectStore("pending_transactions", {
                    keyPath: "id",
                    autoIncrement: true,
                });
            }
        },
    });
}

export async function cacheProducts(products) {
    const db = await getDb();
    const tx = db.transaction("products", "readwrite");
    for (const product of products) {
        await tx.store.put(product);
    }
    await tx.done;
}

export async function getCachedProducts() {
    const db = await getDb();
    return db.getAll("products");
}

export async function cacheCustomers(customers) {
    const db = await getDb();
    const tx = db.transaction("customers", "readwrite");
    for (const customer of customers) {
        await tx.store.put(customer);
    }
    await tx.done;
}

export async function getCachedCustomers() {
    const db = await getDb();
    return db.getAll("customers");
}

export async function queueTransaction(transactionData, scopeKey = null) {
    const db = await getDb();
    return db.add(
        "pending_transactions",
        normalizeQueueRecord({
            data: transactionData,
            scope_key: scopeKey,
            created_at: new Date().toISOString(),
        })
    );
}

export async function getPendingTransactions(scopeKey = null) {
    const db = await getDb();
    const rows = await db.getAll("pending_transactions");
    const staleSyncCutoff = Date.now() - 5 * 60 * 1000;

    return rows
        .map(normalizeQueueRecord)
        .map((row) => {
            const lastAttempt = Date.parse(row.last_attempt_at || "");

            return row.status === "syncing" &&
                Number.isFinite(lastAttempt) &&
                lastAttempt < staleSyncCutoff
                ? { ...row, status: "failed", last_error: "Sync sebelumnya terhenti." }
                : row;
        })
        // Null scope is a legacy v1 row. Keep it visible so an upgrade cannot
        // strand a sale; all newly queued rows are strictly scoped.
        .filter((row) => row.scope_key === null || row.scope_key === scopeKey);
}

export async function getPendingCount(scopeKey = null) {
    const rows = await getPendingTransactions(scopeKey);

    return rows.filter(
        (row) => !["synced", "duplicate"].includes(row.status)
    ).length;
}

export async function pruneExpiredPendingTransactions(maxAgeDays = 30) {
    const db = await getDb();
    const cutoff = Date.now() - maxAgeDays * 24 * 60 * 60 * 1000;
    const tx = db.transaction("pending_transactions", "readwrite");
    let cursor = await tx.store.openCursor();

    while (cursor) {
        const row = normalizeQueueRecord(cursor.value);
        const createdAt = Date.parse(row.created_at);
        const terminal = ["failed", "conflict"].includes(row.status);

        if (terminal && Number.isFinite(createdAt) && createdAt < cutoff) {
            await cursor.delete();
        }

        cursor = await cursor.continue();
    }

    await tx.done;
}

export async function updatePendingTransaction(id, changes) {
    const db = await getDb();
    const current = await db.get("pending_transactions", id);
    if (!current) return;
    await db.put(
        "pending_transactions",
        normalizeQueueRecord({ ...current, ...changes })
    );
}

export async function removePendingTransaction(id) {
    const db = await getDb();
    return db.delete("pending_transactions", id);
}

export async function clearPendingTransactions() {
    const db = await getDb();
    await db.clear("pending_transactions");
}
