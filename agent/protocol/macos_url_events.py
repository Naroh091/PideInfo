"""macOS URL Apple Event handler.

When a `pideinfo://...` link is clicked while the agent is already running,
Launch Services delivers a `kAEGetURL` Apple Event to the .app instead of
spawning a new process. The Unix-socket relay in `single_instance` never sees
it. This module installs an NSAppleEventManager handler so the running tray
agent can dispatch those URLs.

Cold launches are handled separately by PyInstaller's argv_emulation, which
inserts the URL into argv before main() runs.
"""

from __future__ import annotations

import logging
from typing import Callable

logger = logging.getLogger(__name__)

_HANDLER = None  # keep handler alive for the lifetime of the process
_FORWARD: Callable[[str], None] | None = None


def install(on_url: Callable[[str], None]) -> bool:
    """Install a kAEGetURL handler that forwards to *on_url*.

    Must be called before the NSApplication run loop starts (i.e. before the
    pystray icon's run()). Returns True on success, False if pyobjc/Foundation
    is unavailable or registration failed.
    """
    global _HANDLER, _FORWARD
    try:
        from Foundation import NSAppleEventManager, NSObject  # type: ignore
    except Exception as e:
        logger.warning("Foundation/pyobjc unavailable: %s", e)
        return False

    GURL = _fourcc("GURL")
    KEY_DIRECT_OBJECT = _fourcc("----")

    class _URLHandler(NSObject):
        def handleURL_withReplyEvent_(self, event, _reply):  # noqa: N802 (selector name)
            try:
                desc = event.paramDescriptorForKeyword_(KEY_DIRECT_OBJECT)
                if desc is None:
                    return
                url = desc.stringValue()
                if url and _FORWARD is not None:
                    _FORWARD(str(url))
            except Exception as exc:
                logger.exception("Apple Event URL handler raised: %s", exc)

    _FORWARD = on_url
    _HANDLER = _URLHandler.alloc().init()  # NSAppleEventManager retains weakly

    try:
        manager = NSAppleEventManager.sharedAppleEventManager()
        manager.setEventHandler_andSelector_forEventClass_andEventID_(
            _HANDLER, b"handleURL:withReplyEvent:", GURL, GURL,
        )
    except Exception as e:
        logger.warning("setEventHandler failed: %s", e)
        return False
    return True


def _fourcc(code: str) -> int:
    """Convert a 4-character OSType code (e.g. 'GURL') to a 32-bit int."""
    if len(code) != 4:
        raise ValueError(f"OSType code must be 4 chars: {code!r}")
    b = code.encode("macroman")
    return (b[0] << 24) | (b[1] << 16) | (b[2] << 8) | b[3]
