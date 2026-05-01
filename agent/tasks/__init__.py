"""Type-keyed dispatcher for agent tasks.

Each task type registers a handler with signature: handler(task: dict, client) -> None
The handler is responsible for the full lifecycle: progress → complete (or failed).
The CALLER is responsible for claiming the task before invoking the handler;
see dispatch_action_id() which combines claim + dispatch.
"""

from __future__ import annotations

import logging
from typing import Callable, Protocol

logger = logging.getLogger(__name__)


class _ClientProto(Protocol):
    base_url: str
    def claim_task(self, task_id: str) -> dict | None: ...
    def progress_task(self, task_id: str, status: str, note: str | None = ...) -> None: ...
    def complete_task(self, task_id: str, success: bool, *, result: dict | None = ..., error: str | None = ...) -> None: ...
    def download_pdf(self, path: str) -> bytes: ...


_HANDLERS: dict[str, Callable[[dict, _ClientProto], None]] = {}


def register(task_type: str, handler: Callable[[dict, _ClientProto], None]) -> None:
    _HANDLERS[task_type] = handler


def dispatch_action_id(action: str, task_id: str, client: _ClientProto) -> None:
    """Resolve an action+task_id (from a pideinfo:// URL) to a task and dispatch it."""
    task = client.claim_task(task_id)
    if task is None:
        logger.info("Task %s already claimed; ignoring.", task_id)
        return
    handler = _HANDLERS.get(task["type"])
    if handler is None:
        logger.error("No handler for task type %r (id=%s)", task["type"], task_id)
        client.complete_task(task_id, success=False, error=f"no_handler:{task['type']}")
        return
    try:
        handler(task, client)
    except Exception as e:
        logger.exception("Handler for %s crashed: %s", task["type"], e)
        try:
            client.complete_task(task_id, success=False, error=f"handler_crashed:{e!s}"[:2000])
        except Exception:
            pass


def dispatch_existing(task: dict, client: _ClientProto) -> None:
    """Dispatch a task that was already claimed (e.g. discovered via pending poll)."""
    handler = _HANDLERS.get(task["type"])
    if handler is None:
        client.complete_task(task["id"], success=False, error=f"no_handler:{task['type']}")
        return
    try:
        handler(task, client)
    except Exception as e:
        logger.exception("Handler for %s crashed: %s", task["type"], e)
        try:
            client.complete_task(task["id"], success=False, error=f"handler_crashed:{e!s}"[:2000])
        except Exception:
            pass


# Auto-register handlers
from tasks.present_complaint import handle as _present_complaint_handle  # noqa: E402

register("present_complaint", _present_complaint_handle)
