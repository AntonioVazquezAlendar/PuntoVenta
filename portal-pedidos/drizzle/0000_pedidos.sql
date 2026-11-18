CREATE TABLE catalog (id INTEGER PRIMARY KEY CHECK(id=1), version TEXT NOT NULL, installation TEXT NOT NULL, data TEXT NOT NULL, updated INTEGER NOT NULL);
--> statement-breakpoint
CREATE TABLE orders (seq INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE, request_key TEXT NOT NULL UNIQUE, payload_hash TEXT NOT NULL, folio TEXT NOT NULL UNIQUE, name TEXT NOT NULL, phone TEXT NOT NULL, notes TEXT NOT NULL, items TEXT NOT NULL, total_cents INTEGER NOT NULL, created INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'nuevo', pos_version INTEGER NOT NULL DEFAULT 0);
--> statement-breakpoint
CREATE INDEX orders_created ON orders(created);
--> statement-breakpoint
CREATE TABLE rate_limits (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL);
--> statement-breakpoint
CREATE INDEX limits_expires ON rate_limits(expires);
