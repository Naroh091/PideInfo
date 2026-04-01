# -*- mode: python ; coding: utf-8 -*-
"""
PyInstaller spec for PideInfo Agent.

Build from the agent/ directory:
    pyinstaller build/pideinfo-agent.spec

Produces a --onedir bundle in dist/PideInfo Agent/ (Unix) or
dist/PideInfo Agent.app/ (macOS .app bundle).
"""

import sys
import os
from pathlib import Path
import inspect

# ---------------------------------------------------------------------------
# Locate the Playwright driver bundled with the pip package
# ---------------------------------------------------------------------------
try:
    import playwright
    _playwright_pkg = Path(inspect.getfile(playwright)).parent
    _playwright_driver = _playwright_pkg / "driver"
except Exception:
    raise RuntimeError(
        "Playwright package not found. Install it: pip install playwright"
    )

_node_bin = "node.exe" if sys.platform == "win32" else "node"
_node_path = _playwright_driver / _node_bin
if not _node_path.exists():
    raise FileNotFoundError(
        f"Playwright Node binary not found at {_node_path}. "
        "Run: pip install playwright"
    )

# ---------------------------------------------------------------------------
# Platform-specific settings
# ---------------------------------------------------------------------------
_is_mac = sys.platform == "darwin"
_is_win = sys.platform == "win32"
_is_linux = sys.platform.startswith("linux")

_hidden_imports = [
    "truststore",
    "pydantic_settings",
    "apscheduler.schedulers.asyncio",
    "apscheduler.triggers.interval",
    "bs4",
    "packaging.version",
]

if _is_mac:
    _hidden_imports += [
        "keyring.backends.macOS",
        "keyring.backends.fail",
    ]
elif _is_win:
    _hidden_imports += [
        "keyring.backends.Windows",
        "keyring.backends.fail",
    ]
else:
    _hidden_imports += [
        "keyring.backends.SecretService",
        "keyring.backends.fail",
    ]

_excludes = []
if _is_mac:
    _excludes += ["tkinter", "_tkinter"]
if not _is_mac:
    _excludes += ["AppKit", "Foundation", "Cocoa"]

# Icon — optional: if the file doesn't exist the build proceeds without one
_icon_candidate = (
    "macos/icon.icns" if _is_mac
    else ("windows/icon.ico" if _is_win else "linux/icon.png")
)
_icon_path = _icon_candidate if Path(_icon_candidate).exists() else None

# ---------------------------------------------------------------------------
# Data files to bundle
# ---------------------------------------------------------------------------
_datas = [
    # Playwright driver: Node.js binary + package (cli.js, etc.)
    (str(_playwright_driver / _node_bin), "playwright/driver"),
    (str(_playwright_driver / "package"), "playwright/driver/package"),
]

# ---------------------------------------------------------------------------
# Analysis
# ---------------------------------------------------------------------------
a = Analysis(
    ["../main.py"],
    pathex=[str(Path(".").resolve())],
    binaries=[],
    datas=_datas,
    hiddenimports=_hidden_imports,
    hookspath=["build/hooks"],
    hooksconfig={},
    runtime_hooks=[],
    excludes=_excludes,
    noarchive=False,
    optimize=0,
)

pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name="pideinfo-agent",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=_is_mac,
    target_arch=None,
    codesign_identity=None,
    entitlements_file="macos/entitlements.plist" if _is_mac else None,
    icon=_icon_path,
)

coll = COLLECT(
    exe,
    a.binaries,
    a.datas,
    strip=False,
    upx=False,
    upx_exclude=[],
    name="PideInfo Agent",
)

if _is_mac:
    app = BUNDLE(
        coll,
        name="PideInfo Agent.app",
        icon=_icon_path,
        bundle_identifier="com.pideinfo.agent",
        info_plist={
            "CFBundleName": "PideInfo Agent",
            "CFBundleDisplayName": "PideInfo Agent",
            "CFBundleIdentifier": "com.pideinfo.agent",
            "CFBundleVersion": "1.0.0",
            "CFBundleShortVersionString": "1.0.0",
            "NSHighResolutionCapable": True,
            # Accessory app: no Dock icon, no menu bar app icon in Mission Control
            "LSUIElement": True,
            "NSHumanReadableCopyright": "PideInfo",
        },
    )
