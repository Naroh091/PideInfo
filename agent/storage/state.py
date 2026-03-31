from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from pathlib import Path


@dataclass
class SyncState:
    """Tracks what has been synced to avoid duplicates."""

    # Set of notification IDs already synced (as strings for JSON compat)
    synced_notification_ids: set[str] = field(default_factory=set)

    # Set of "idExpediente:idDocumento" keys already synced
    synced_document_keys: set[str] = field(default_factory=set)

    # Set of expedition IDs (as strings) that PideInfo currently shows as having
    # pending notifications. Used to send explicit clears when they no longer do.
    pending_notification_expediente_ids: set[str] = field(default_factory=set)

    # Set of CTBG expediente refs (as strings, e.g. "666/2026") that PideInfo
    # currently shows as having pending notifications.
    ctbg_pending_expediente_refs: set[str] = field(default_factory=set)

    # Last successful sync timestamp
    last_sync_at: float = 0.0

    def is_notification_synced(self, notification_id: int) -> bool:
        return str(notification_id) in self.synced_notification_ids

    def is_document_synced(self, id_expediente: int, id_documento: int) -> bool:
        key = f"{id_expediente}:{id_documento}"
        return key in self.synced_document_keys

    def mark_notification_synced(self, notification_id: int) -> None:
        self.synced_notification_ids.add(str(notification_id))

    def mark_document_synced(self, id_expediente: int, id_documento: int) -> None:
        key = f"{id_expediente}:{id_documento}"
        self.synced_document_keys.add(key)

    def mark_sync_complete(self) -> None:
        self.last_sync_at = time.time()


def load_state(path: Path) -> SyncState:
    """Load sync state from a JSON file."""
    if not path.exists():
        return SyncState()

    try:
        data = json.loads(path.read_text())
        return SyncState(
            synced_notification_ids=set(data.get("synced_notification_ids", [])),
            synced_document_keys=set(data.get("synced_document_keys", [])),
            pending_notification_expediente_ids=set(data.get("pending_notification_expediente_ids", [])),
            ctbg_pending_expediente_refs=set(data.get("ctbg_pending_expediente_refs", [])),
            last_sync_at=data.get("last_sync_at", 0.0),
        )
    except (json.JSONDecodeError, KeyError):
        return SyncState()


def save_state(state: SyncState, path: Path) -> None:
    """Save sync state to a JSON file."""
    path.parent.mkdir(parents=True, exist_ok=True)
    data = {
        "synced_notification_ids": sorted(state.synced_notification_ids),
        "synced_document_keys": sorted(state.synced_document_keys),
        "pending_notification_expediente_ids": sorted(state.pending_notification_expediente_ids),
        "ctbg_pending_expediente_refs": sorted(state.ctbg_pending_expediente_refs),
        "last_sync_at": state.last_sync_at,
    }
    path.write_text(json.dumps(data, indent=2))
