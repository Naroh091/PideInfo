"""PyInstaller hook for truststore.

truststore.inject_into_ssl() modifies Python's ssl module at runtime to use
OS certificate stores. It does not depend on bundled cert files, so no data
collection is needed — we just declare it as a hidden import to ensure
PyInstaller includes the module.
"""

hiddenimports = ["truststore"]
