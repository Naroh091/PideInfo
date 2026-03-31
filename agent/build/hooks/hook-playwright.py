"""PyInstaller hook for Playwright.

Ensures the Playwright driver (Node.js binary + package/) is collected
and that dynamic libraries are included. The browser itself is NOT bundled —
it is downloaded on first run via ensure_firefox() in runtime.py.
"""

from PyInstaller.utils.hooks import collect_data_files, collect_dynamic_libs

# Collect all data files from the playwright package.
# This includes the driver/ directory with Node.js and cli.js.
datas = collect_data_files("playwright")

# Collect any native .so/.dylib/.dll files playwright ships
binaries = collect_dynamic_libs("playwright")
