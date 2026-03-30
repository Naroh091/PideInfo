"""
Tkinter dialogs for agent connection flow.

Uses tkinter because it ships with Python on macOS, Windows, and Linux
without requiring extra dependencies.
"""
from __future__ import annotations

import tkinter as tk
from tkinter import ttk


def show_connect_dialog() -> str | None:
    """Show a dialog asking the user to paste their JWT token.

    Returns the token string, or None if the user cancelled.
    """
    result: list[str | None] = [None]

    root = tk.Tk()
    root.title("PideInfo Agent — Conectar")
    root.geometry("520x280")
    root.resizable(False, False)

    # Center on screen
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 520) // 2
    y = (root.winfo_screenheight() - 280) // 2
    root.geometry(f"+{x}+{y}")

    # Title
    title = ttk.Label(root, text="Conectar con PideInfo", font=("", 14, "bold"))
    title.pack(pady=(20, 5))

    # Description
    desc = ttk.Label(
        root,
        text="Pega el token de conexion generado desde tu cuenta de PideInfo.",
        wraplength=460,
        justify="center",
    )
    desc.pack(pady=(0, 15))

    # Token input
    frame = ttk.Frame(root)
    frame.pack(padx=30, fill="x")

    label = ttk.Label(frame, text="Token:")
    label.pack(anchor="w")

    token_var = tk.StringVar()
    entry = ttk.Entry(frame, textvariable=token_var, width=60, show="*")
    entry.pack(fill="x", pady=(2, 0))
    entry.focus_set()

    # Error label
    error_var = tk.StringVar()
    error_label = ttk.Label(frame, textvariable=error_var, foreground="red", wraplength=460)
    error_label.pack(anchor="w", pady=(5, 0))

    # Buttons
    btn_frame = ttk.Frame(root)
    btn_frame.pack(pady=20)

    def on_save() -> None:
        token = token_var.get().strip()
        if not token:
            error_var.set("Introduce un token valido.")
            return
        result[0] = token
        root.destroy()

    def on_cancel() -> None:
        root.destroy()

    cancel_btn = ttk.Button(btn_frame, text="Cancelar", command=on_cancel)
    cancel_btn.pack(side="left", padx=(0, 10))

    save_btn = ttk.Button(btn_frame, text="Guardar", command=on_save, style="Accent.TButton")
    save_btn.pack(side="left")

    # Enter key submits
    root.bind("<Return>", lambda e: on_save())
    root.bind("<Escape>", lambda e: on_cancel())

    # Keep on top
    root.attributes("-topmost", True)
    root.lift()

    root.mainloop()
    return result[0]


def show_connected_card(name: str, email: str) -> None:
    """Show a small confirmation card with the user's info.

    Auto-closes after 4 seconds or on click.
    """
    root = tk.Tk()
    root.title("PideInfo Agent")
    root.geometry("360x180")
    root.resizable(False, False)

    # Center on screen
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 360) // 2
    y = (root.winfo_screenheight() - 180) // 2
    root.geometry(f"+{x}+{y}")

    # Success icon / title
    title = ttk.Label(root, text="Conexion exitosa", font=("", 14, "bold"), foreground="green")
    title.pack(pady=(25, 10))

    # User info
    name_label = ttk.Label(root, text=name, font=("", 12))
    name_label.pack()

    email_label = ttk.Label(root, text=email, font=("", 10), foreground="gray")
    email_label.pack(pady=(2, 15))

    # Close button
    close_btn = ttk.Button(root, text="Cerrar", command=root.destroy)
    close_btn.pack()

    # Auto-close after 4 seconds
    root.after(4000, root.destroy)
    root.attributes("-topmost", True)
    root.lift()

    root.mainloop()


def show_error_dialog(message: str) -> None:
    """Show a simple error dialog."""
    root = tk.Tk()
    root.title("PideInfo Agent — Error")
    root.geometry("400x150")
    root.resizable(False, False)

    root.update_idletasks()
    x = (root.winfo_screenwidth() - 400) // 2
    y = (root.winfo_screenheight() - 150) // 2
    root.geometry(f"+{x}+{y}")

    title = ttk.Label(root, text="Error de conexion", font=("", 13, "bold"), foreground="red")
    title.pack(pady=(20, 10))

    msg = ttk.Label(root, text=message, wraplength=360, justify="center")
    msg.pack(pady=(0, 15))

    close_btn = ttk.Button(root, text="Cerrar", command=root.destroy)
    close_btn.pack()

    root.attributes("-topmost", True)
    root.lift()
    root.mainloop()
