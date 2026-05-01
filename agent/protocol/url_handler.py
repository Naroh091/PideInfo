"""Parse pideinfo:// URLs and dispatch them to the agent task system.

Format: pideinfo://<action>/<task_id>

Currently only `present-complaint` is supported. Unknown actions log a warning
and are dropped.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from typing import Callable

logger = logging.getLogger(__name__)

_URL_RE = re.compile(r"^pideinfo://(?P<action>[a-z0-9-]+)/(?P<task_id>[0-9a-f-]{36})$")


@dataclass(frozen=True)
class ParsedUrl:
    action: str
    task_id: str


def parse(url: str) -> ParsedUrl | None:
    """Return ParsedUrl or None for malformed inputs."""
    m = _URL_RE.match(url.strip())
    if m is None:
        logger.warning("Ignoring malformed pideinfo URL: %r", url)
        return None
    return ParsedUrl(action=m.group("action"), task_id=m.group("task_id"))


def handle(url: str, dispatch: Callable[[str, str], None]) -> None:
    """Parse and forward to the dispatcher.

    The dispatcher receives (action, task_id). Side effects (network, browser)
    happen there, not here — this function is pure-ish for testability.
    """
    parsed = parse(url)
    if parsed is None:
        return
    dispatch(parsed.action, parsed.task_id)
