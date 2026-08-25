#!/usr/bin/env python3
"""Postfix policy service that limits outbound recipients per SASL account."""

from __future__ import annotations

import logging
import os
import re
import socketserver
import sqlite3
import time
from pathlib import Path

HOST = "127.0.0.1"
PORT = 10040
CONFIG_PATH = Path("/etc/xpanel-host/mail/send-limits")
STATE_PATH = Path("/var/lib/xpanel-host/mail-policy/counters.sqlite3")
EMAIL = re.compile(r"^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+$")
LOGGER = logging.getLogger("xpanel-mail-rate-policy")


class LimitConfig:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.mtime_ns = -1
        self.limits: dict[str, tuple[int, int]] = {}

    def get(self, account: str) -> tuple[int, int] | None:
        try:
            mtime_ns = self.path.stat().st_mtime_ns
        except OSError:
            self.limits = {}
            self.mtime_ns = -1
            return None
        if mtime_ns != self.mtime_ns:
            parsed: dict[str, tuple[int, int]] = {}
            for raw_line in self.path.read_text(encoding="utf-8").splitlines():
                line = raw_line.strip()
                if not line or line.startswith("#"):
                    continue
                parts = line.split()
                if len(parts) != 3 or not EMAIL.fullmatch(parts[0]):
                    raise ValueError("invalid outbound limit entry")
                hourly, daily = int(parts[1]), int(parts[2])
                if hourly < 1 or daily < hourly:
                    raise ValueError("invalid outbound limit values")
                parsed[parts[0].lower()] = (hourly, daily)
            self.limits = parsed
            self.mtime_ns = mtime_ns
        return self.limits.get(account.lower())


def initialize_database(path: Path) -> None:
    path.parent.mkdir(mode=0o750, parents=True, exist_ok=True)
    with sqlite3.connect(path) as database:
        database.execute("PRAGMA journal_mode=WAL")
        database.execute(
            "CREATE TABLE IF NOT EXISTS counters ("
            "account TEXT NOT NULL, period TEXT NOT NULL, bucket INTEGER NOT NULL, "
            "recipients INTEGER NOT NULL, PRIMARY KEY(account, period, bucket))"
        )
        database.execute(
            "CREATE TABLE IF NOT EXISTS events ("
            "instance TEXT NOT NULL, account TEXT NOT NULL, recipient TEXT NOT NULL, "
            "created_at INTEGER NOT NULL, PRIMARY KEY(instance, account, recipient))"
        )


def consume(account: str, recipient: str, instance: str, hourly: int, daily: int) -> str:
    now = int(time.time())
    hour_bucket = now - (now % 3600)
    day_bucket = now - (now % 86400)
    with sqlite3.connect(STATE_PATH, timeout=5) as database:
        database.execute("BEGIN IMMEDIATE")
        database.execute("DELETE FROM counters WHERE bucket < ?", (day_bucket - 172800,))
        database.execute("DELETE FROM events WHERE created_at < ?", (now - 7200,))
        duplicate = database.execute(
            "SELECT 1 FROM events WHERE instance = ? AND account = ? AND recipient = ?",
            (instance, account, recipient),
        ).fetchone()
        if duplicate:
            return "DUNNO"
        hour_count = database.execute(
            "SELECT recipients FROM counters WHERE account = ? AND period = 'hour' AND bucket = ?",
            (account, hour_bucket),
        ).fetchone()
        day_count = database.execute(
            "SELECT recipients FROM counters WHERE account = ? AND period = 'day' AND bucket = ?",
            (account, day_bucket),
        ).fetchone()
        if (hour_count[0] if hour_count else 0) >= hourly:
            return "REJECT 5.7.1 Límite horario de destinatarios salientes alcanzado"
        if (day_count[0] if day_count else 0) >= daily:
            return "REJECT 5.7.1 Límite diario de destinatarios salientes alcanzado"
        for period, bucket in (("hour", hour_bucket), ("day", day_bucket)):
            database.execute(
                "INSERT INTO counters(account, period, bucket, recipients) VALUES(?, ?, ?, 1) "
                "ON CONFLICT(account, period, bucket) DO UPDATE SET recipients = recipients + 1",
                (account, period, bucket),
            )
        database.execute(
            "INSERT INTO events(instance, account, recipient, created_at) VALUES(?, ?, ?, ?)",
            (instance, account, recipient, now),
        )
    return "DUNNO"


LIMITS = LimitConfig(CONFIG_PATH)


def evaluate(attributes: dict[str, str]) -> str:
    if attributes.get("request") != "smtpd_access_policy" or attributes.get("protocol_state") != "RCPT":
        return "DUNNO"
    account = attributes.get("sasl_username", "").strip().lower()
    recipient = attributes.get("recipient", "").strip().lower()
    instance = attributes.get("instance", "").strip()
    if not account:
        return "DUNNO"
    if not EMAIL.fullmatch(account) or not recipient or not instance:
        return "DEFER_IF_PERMIT 4.7.1 No se pudo validar la identidad SMTP"
    limits = LIMITS.get(account)
    if limits is None:
        return "DEFER_IF_PERMIT 4.7.1 La cuenta SMTP no tiene una política de envío"
    return consume(account, recipient, instance, limits[0], limits[1])


class PolicyHandler(socketserver.StreamRequestHandler):
    def handle(self) -> None:
        while True:
            attributes: dict[str, str] = {}
            while True:
                raw = self.rfile.readline(65537)
                if not raw:
                    return
                if len(raw) > 65536:
                    return
                line = raw.decode("utf-8", "replace").rstrip("\r\n")
                if line == "":
                    break
                if "=" in line:
                    key, value = line.split("=", 1)
                    attributes[key] = value
            try:
                action = evaluate(attributes)
            except Exception:
                LOGGER.exception("Outbound mail policy evaluation failed")
                action = "DEFER_IF_PERMIT 4.7.1 El control de envío no está disponible"
            if action.startswith("REJECT"):
                LOGGER.warning("Outbound recipient rejected for account=%s", attributes.get("sasl_username", "unknown"))
            self.wfile.write(("action=" + action + "\n\n").encode("utf-8"))
            self.wfile.flush()


class PolicyServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def main() -> None:
    os.umask(0o027)
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    initialize_database(STATE_PATH)
    with PolicyServer((HOST, PORT), PolicyHandler) as server:
        server.serve_forever(poll_interval=0.5)


if __name__ == "__main__":
    main()
