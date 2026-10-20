"""Durable event outbox. Telegram authentication secrets are stored separately."""
import json
import sqlite3
from pathlib import Path


class Outbox:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(path)
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("CREATE TABLE IF NOT EXISTS outbox (id INTEGER PRIMARY KEY, event_id TEXT UNIQUE, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', error TEXT)")
        self.db.commit()

    def put(self, payload: dict):
        self.db.execute("INSERT OR IGNORE INTO outbox(event_id,payload) VALUES (?,?)", (payload['event_id'], json.dumps(payload, ensure_ascii=False)))
        self.db.commit()

    def pending(self, limit=100):
        return [(row[0], json.loads(row[1])) for row in self.db.execute("SELECT id,payload FROM outbox WHERE status='pending' ORDER BY id LIMIT ?", (limit,))]

    def acknowledge(self, row_id: int):
        self.db.execute("DELETE FROM outbox WHERE id=?", (row_id,))
        self.db.commit()

    def reject(self, row_id: int, reason: str):
        self.db.execute("UPDATE outbox SET status='failed',error=? WHERE id=?", (reason, row_id))
        self.db.commit()

    def counts(self):
        return dict(self.db.execute("SELECT status,count(*) FROM outbox GROUP BY status"))

    def retry_failed(self):
        self.db.execute("UPDATE outbox SET status='pending',error=NULL WHERE status='failed'")
        self.db.commit()
