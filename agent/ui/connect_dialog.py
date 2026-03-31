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


def _show_cert_dialog_macos() -> "tuple[str, str] | None":
    """File picker for .p12 + passphrase prompt. Returns (path, passphrase) or None."""
    code, path = _osascript(
        'set f to choose file with prompt '
        '"Selecciona tu certificado digital (.p12):" '
        'of type {"public.data"}\n'
        'return POSIX path of f'
    )
    if code != 0 or not path:
        return None

    code, passphrase = _osascript(
        'set t to text returned of (display dialog '
        '"Introduce la contraseña del certificado:" '
        'default answer "" '
        'with title "PideInfo Agent — Certificado" '
        'buttons {"Cancelar", "Guardar"} '
        'default button "Guardar" '
        'with hidden answer)\n'
        'return t'
    )
    if code != 0:
        return None

    return path, passphrase


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


def _show_cert_dialog_tk() -> "tuple[str, str] | None":
    """File picker for .p12 + passphrase (Tkinter). Returns (path, passphrase) or None."""
    import tkinter as tk
    from tkinter import ttk, filedialog

    result: list[tuple[str, str] | None] = [None]
    root = tk.Tk()
    root.title("PideInfo Agent — Certificado")
    root.geometry("520x220")
    root.resizable(False, False)
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 520) // 2
    y = (root.winfo_screenheight() - 220) // 2
    root.geometry(f"+{x}+{y}")

    ttk.Label(root, text="Configurar certificado digital", font=("", 14, "bold")).pack(pady=(20, 5))
    frame = ttk.Frame(root)
    frame.pack(padx=30, fill="x")

    ttk.Label(frame, text="Certificado (.p12):").pack(anchor="w")
    path_var = tk.StringVar()
    path_frame = ttk.Frame(frame)
    path_frame.pack(fill="x", pady=(2, 8))
    ttk.Entry(path_frame, textvariable=path_var, width=45).pack(side="left", fill="x", expand=True)

    def browse() -> None:
        f = filedialog.askopenfilename(
            title="Selecciona tu certificado digital",
            filetypes=[("PKCS#12", "*.p12 *.pfx"), ("Todos", "*.*")],
        )
        if f:
            path_var.set(f)

    ttk.Button(path_frame, text="Examinar…", command=browse).pack(side="left", padx=(5, 0))

    ttk.Label(frame, text="Contraseña:").pack(anchor="w")
    pass_var = tk.StringVar()
    ttk.Entry(frame, textvariable=pass_var, show="*", width=60).pack(fill="x", pady=(2, 0))

    btn_frame = ttk.Frame(root)
    btn_frame.pack(pady=15)

    def on_save() -> None:
        p = path_var.get().strip()
        if not p:
            return
        result[0] = (p, pass_var.get())
        root.destroy()

    ttk.Button(btn_frame, text="Cancelar", command=root.destroy).pack(side="left", padx=(0, 10))
    ttk.Button(btn_frame, text="Guardar", command=on_save).pack(side="left")
    root.bind("<Return>", lambda e: on_save())
    root.bind("<Escape>", lambda e: root.destroy())
    root.attributes("-topmost", True)
    root.lift()
    root.mainloop()
    return result[0]


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
# Public API — dispatches to the right backend
# ---------------------------------------------------------------------------

def show_connect_dialog() -> "str | None":
    if sys.platform == "darwin":
        return _show_connect_dialog_macos()
    return _show_connect_dialog_tk()


def show_connected_card(name: str, email: str) -> None:
    if sys.platform == "darwin":
        _show_connected_card_macos(name, email)
    else:
        _show_connected_card_tk(name, email)


def show_cert_dialog() -> "tuple[str, str] | None":
    """Show a dialog to select a .p12 certificate. Returns (path, passphrase) or None."""
    if sys.platform == "darwin":
        return _show_cert_dialog_macos()
    return _show_cert_dialog_tk()


def show_error_dialog(message: str) -> None:
    if sys.platform == "darwin":
        _show_error_dialog_macos(message)
    else:
        _show_error_dialog_tk(message)
