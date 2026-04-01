"""
Native dialogs for the agent connection flow.

On macOS the tray menu callbacks run inside the AppKit run loop, so Tkinter's
mainloop() cannot be nested on top of it.  We use osascript (a subprocess) on
macOS so the dialog runs completely outside the run loop.  Tkinter is used as
the fallback on Windows and Linux, where there is no such conflict.
"""
from __future__ import annotations

import subprocess
import sys


# ---------------------------------------------------------------------------
# macOS — osascript implementation
# ---------------------------------------------------------------------------

def _osascript(script: str, timeout: int = 120) -> "tuple[int, str]":
    """Run an AppleScript snippet and return (returncode, stdout)."""
    try:
        r = subprocess.run(
            ["osascript", "-e", script],
            capture_output=True, text=True, timeout=timeout,
        )
        return r.returncode, r.stdout.strip()
    except Exception:
        return 1, ""


def _show_connect_dialog_macos() -> "str | None":
    code, token = _osascript(
        'set t to text returned of (display dialog '
        '"Pega el token de conexión generado desde tu cuenta de PideInfo:" '
        'default answer "" '
        'with title "PideInfo Agent — Conectar" '
        'buttons {"Cancelar", "Conectar"} '
        'default button "Conectar" '
        'with hidden answer)'
        '\nreturn t'
    )
    if code != 0:
        return None
    return token or None


def _show_connected_card_macos(name: str, email: str) -> None:
    _osascript(
        f'display notification "Conectado como {email}" '
        f'with title "PideInfo Agent" '
        f'subtitle "{name}"'
    )


def _show_error_dialog_macos(message: str) -> None:
    safe = message.replace('"', '\\"')
    _osascript(
        f'display alert "Error de conexión" message "{safe}" '
        'buttons {"Cerrar"} default button "Cerrar" as critical'
    )


# ---------------------------------------------------------------------------
# Windows / Linux — Tkinter implementation
# ---------------------------------------------------------------------------

def _show_connect_dialog_tk() -> "str | None":
    import tkinter as tk
    from tkinter import ttk

    result: list[str | None] = [None]
    root = tk.Tk()
    root.title("PideInfo Agent — Conectar")
    root.geometry("520x260")
    root.resizable(False, False)
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 520) // 2
    y = (root.winfo_screenheight() - 260) // 2
    root.geometry(f"+{x}+{y}")

    ttk.Label(root, text="Conectar con PideInfo", font=("", 14, "bold")).pack(pady=(20, 5))
    ttk.Label(
        root,
        text="Pega el token de conexión generado desde tu cuenta de PideInfo.",
        wraplength=460, justify="center",
    ).pack(pady=(0, 15))

    frame = ttk.Frame(root)
    frame.pack(padx=30, fill="x")
    ttk.Label(frame, text="Token:").pack(anchor="w")
    token_var = tk.StringVar()
    entry = ttk.Entry(frame, textvariable=token_var, width=60, show="*")
    entry.pack(fill="x", pady=(2, 0))
    entry.focus_set()

    error_var = tk.StringVar()
    ttk.Label(frame, textvariable=error_var, foreground="red", wraplength=460).pack(anchor="w", pady=(5, 0))

    btn_frame = ttk.Frame(root)
    btn_frame.pack(pady=20)

    def on_save() -> None:
        t = token_var.get().strip()
        if not t:
            error_var.set("Introduce un token válido.")
            return
        result[0] = t
        root.destroy()

    ttk.Button(btn_frame, text="Cancelar", command=root.destroy).pack(side="left", padx=(0, 10))
    ttk.Button(btn_frame, text="Guardar", command=on_save).pack(side="left")
    root.bind("<Return>", lambda e: on_save())
    root.bind("<Escape>", lambda e: root.destroy())
    root.attributes("-topmost", True)
    root.lift()
    root.mainloop()
    return result[0]


def _show_connected_card_tk(name: str, email: str) -> None:
    import tkinter as tk
    from tkinter import ttk

    root = tk.Tk()
    root.title("PideInfo Agent")
    root.geometry("360x160")
    root.resizable(False, False)
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 360) // 2
    y = (root.winfo_screenheight() - 160) // 2
    root.geometry(f"+{x}+{y}")
    ttk.Label(root, text="Conexión exitosa", font=("", 14, "bold"), foreground="green").pack(pady=(25, 8))
    ttk.Label(root, text=name, font=("", 12)).pack()
    ttk.Label(root, text=email, font=("", 10), foreground="gray").pack(pady=(2, 12))
    ttk.Button(root, text="Cerrar", command=root.destroy).pack()
    root.after(4000, root.destroy)
    root.attributes("-topmost", True)
    root.lift()
    root.mainloop()


def _show_error_dialog_tk(message: str) -> None:
    import tkinter as tk
    from tkinter import ttk

    root = tk.Tk()
    root.title("PideInfo Agent — Error")
    root.geometry("400x150")
    root.resizable(False, False)
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 400) // 2
    y = (root.winfo_screenheight() - 150) // 2
    root.geometry(f"+{x}+{y}")
    ttk.Label(root, text="Error de conexión", font=("", 13, "bold"), foreground="red").pack(pady=(20, 10))
    ttk.Label(root, text=message, wraplength=360, justify="center").pack(pady=(0, 15))
    ttk.Button(root, text="Cerrar", command=root.destroy).pack()
    root.attributes("-topmost", True)
    root.lift()
    root.mainloop()


# ---------------------------------------------------------------------------
# Settings dialog
# ---------------------------------------------------------------------------

def _show_settings_dialog_macos(current_url: str) -> "str | None":
    safe_url = current_url.replace('"', '\\"')
    code, url = _osascript(
        f'set r to display dialog '
        f'"URL del servidor PideInfo:\\n(deja vacío para restaurar el valor por defecto)" '
        f'default answer "{safe_url}" '
        'with title "PideInfo Agent — Configuración" '
        'buttons {"Cancelar", "Guardar"} '
        'default button "Guardar"\n'
        'return text returned of r'
    )
    if code != 0:
        return None  # cancelled
    return url.strip()


def _show_settings_dialog_tk(current_url: str) -> "str | None":
    import tkinter as tk
    from tkinter import ttk

    result: list[str | None] = [None]
    root = tk.Tk()
    root.title("PideInfo Agent — Configuración")
    root.geometry("520x185")
    root.resizable(False, False)
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 520) // 2
    y = (root.winfo_screenheight() - 185) // 2
    root.geometry(f"+{x}+{y}")

    ttk.Label(root, text="Configuración", font=("", 14, "bold")).pack(pady=(20, 12))

    frame = ttk.Frame(root)
    frame.pack(padx=30, fill="x")
    ttk.Label(frame, text="URL del servidor PideInfo:").pack(anchor="w")
    url_var = tk.StringVar(value=current_url)
    entry = ttk.Entry(frame, textvariable=url_var, width=62)
    entry.pack(fill="x", pady=(2, 2))
    ttk.Label(
        frame,
        text="Deja vacío para usar el valor por defecto.",
        foreground="#888888",
        font=("", 9),
    ).pack(anchor="w")
    entry.focus_set()

    btn_frame = ttk.Frame(root)
    btn_frame.pack(pady=15)

    def on_save() -> None:
        result[0] = url_var.get().strip()
        root.destroy()

    ttk.Button(btn_frame, text="Cancelar", command=root.destroy).pack(side="left", padx=(0, 10))
    ttk.Button(btn_frame, text="Guardar", command=on_save).pack(side="left")
    root.bind("<Return>", lambda e: on_save())
    root.bind("<Escape>", lambda e: root.destroy())
    root.attributes("-topmost", True)
    root.lift()
    root.mainloop()
    return result[0]


# ---------------------------------------------------------------------------
# Public API — dispatches to the right backend
# ---------------------------------------------------------------------------

def show_settings_dialog(current_url: str) -> "str | None":
    """Show the settings modal. Returns the new URL string (may be empty to
    clear the override), or None if the user cancelled."""
    if sys.platform == "darwin":
        return _show_settings_dialog_macos(current_url)
    return _show_settings_dialog_tk(current_url)


def show_connect_dialog() -> "str | None":
    if sys.platform == "darwin":
        return _show_connect_dialog_macos()
    return _show_connect_dialog_tk()


def show_connected_card(name: str, email: str) -> None:
    if sys.platform == "darwin":
        _show_connected_card_macos(name, email)
    else:
        _show_connected_card_tk(name, email)


def show_error_dialog(message: str) -> None:
    if sys.platform == "darwin":
        _show_error_dialog_macos(message)
    else:
        _show_error_dialog_tk(message)
