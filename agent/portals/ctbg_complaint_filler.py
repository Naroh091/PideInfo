"""Drive the CTBG complaint Wicket wizard from step 1 (Identificación) all
the way through step 6 (Acuse de recibo), downloading the receipt and the
signed instance.

Spec reference: docs/CTBG_RECLAMACIONES.md (mapped via MCP on 2026-05-01).
"""
from __future__ import annotations

import re
from pathlib import Path
from typing import Optional

from playwright.async_api import (
    FrameLocator,
    Page,
    TimeoutError as PlaywrightTimeoutError,
)
from rich.console import Console

console = Console()


# Requisito de validez (paso 1 del modal de subida)
VALIDITY_ORIGINAL = "EE01"          # documento original electrónico (PDFs PideInfo)
VALIDITY_AUTHENTIC_COPY = "EE03"    # copia auténtica
VALIDITY_SIMPLE_COPY = "EEG02"      # copia simple

# Forma de aportación
ORIGIN_BRING_MYSELF = "I_DECIDE_TO_BRING_IT_MYSELF"
ORIGIN_PRESENTED_ELSEWHERE = "WAS_PRESENTED_IN_ANOTHER_ADMINISTRATION"


class CtbgFillerError(RuntimeError):
    """Raised when the filler cannot complete a step."""


class CtbgComplaintFiller:
    """Fill the CTBG complaint form for one task.

    `payload` keys (from `ComplaintController::presentViaAgent`):
        public_body_name, complaint_branch ('yes'|'no'),
        complaint_reason (4 literals or None), notification_date (dd/mm/yyyy or None),
        complaint_body (full text), request_external_id

    `files`:
        reclamacion: Path  – PDF of the complaint argument (always)
        solicitud:   Path  – original request PDF (always)
        respuesta:   Path|None – agency's resolution PDF (branch=yes)
        notificacion: Path|None – agency's notification PDF (branch=yes)
    """

    def __init__(
        self,
        page: Page,
        payload: dict,
        files: dict[str, Optional[Path]],
        download_dir: Optional[Path] = None,
    ):
        self.page = page
        self.payload = payload
        self.files = files
        self.download_dir = download_dir

    async def run(self) -> dict:
        await self._step1_identification()
        await self._step2_form()
        await self._step3_documents()
        await self._step4_declaro()
        await self._step5_sign()
        return await self._step6_acuse()

    # ------------------------------------------------------------------
    # Steps
    # ------------------------------------------------------------------

    async def _step1_identification(self) -> None:
        await self._wait_for_step("1")
        # "Para <NOMBRE> (NIF)" radio: name=representationMode, value=PERSONAL.
        # The native radio is visually hidden; the styled <label> intercepts
        # clicks. force=True bypasses Playwright's actionability gate so the
        # check goes straight to the underlying input.
        await self.page.locator(
            'input[type="radio"][name*="representationMode"]'
        ).first.check(force=True)
        await self._click_save_continue()

    async def _step2_form(self) -> None:
        await self._wait_for_step("2")
        # I. DATOS DE LA RECLAMACIÓN
        await self._wicket_write(
            label_re=re.compile(r"ENTIDAD RECLAMADA", re.I),
            value=self.payload["public_body_name"] or "",
        )
        # Wicket label has appeared as both "Nº expediente del Portal de
        # Transparencia" and "Indique el nº expediente del Portal de
        # Transparencia" — match the invariant substring with a tolerant gap
        # in case of additional articles ("del Portal **de la** Transparencia").
        await self._wicket_write(
            label_re=re.compile(r"expediente.{0,20}Portal.{0,20}Transparencia", re.I),
            value=self.payload["request_external_id"] or "",
        )

        # B. RESPUESTA A SU SOLICITUD
        branch = self.payload["complaint_branch"]
        b_option = (
            "Sí he recibido respuesta a la solicitud" if branch == "yes"
            else "No he recibido respuesta a la solicitud"
        )
        await self._wicket_select(
            label_re=re.compile(r"Se.ale la opci.n correspondiente", re.I),
            option_text=b_option,
        )

        if branch == "yes":
            # B.1 — fecha + razones
            await self._wicket_set_date(
                label_re=re.compile(r"Fecha de notificaci", re.I),
                dmy=self.payload["notification_date"] or "",
            )
            await self._wicket_select(
                label_re=re.compile(r"RAZONES DE LA RECLAMACI", re.I),
                option_text=self.payload["complaint_reason"] or "",
            )

        # Motivos (común a ambas ramas)
        await self._wicket_write(
            label_re=re.compile(r"Exponga brevemente los motivos", re.I),
            value=self.payload["complaint_body"] or "",
        )

        await self._click_save_continue()

    async def _step3_documents(self) -> None:
        await self._wait_for_step("3")

        branch = self.payload["complaint_branch"]
        if branch == "yes":
            # Card 0: Resolución frente a la que se reclama
            await self._attach_required_doc(0, self.files["respuesta"])
            # Card 1: Notificación de la resolución
            await self._attach_required_doc(1, self.files["notificacion"])
            # Card 2: Solicitud de información
            await self._attach_required_doc(2, self.files["solicitud"])
        else:
            # Single card: Solicitud de información
            await self._attach_required_doc(0, self.files["solicitud"])

        # No subimos el PDF de la reclamación como adicional: su texto ya va
        # en el campo "Exponga brevemente los motivos" del paso 2.

        await self._click_save_continue()

    async def _step4_declaro(self) -> None:
        await self._wait_for_step("4")
        await self.page.locator(
            'input[type="checkbox"][name="declarationList:0:check"]'
        ).check(force=True)
        await self._click_save_continue()
        await self._wait_for_step("5")  # parked on Firmar

    async def _step5_sign(self) -> None:
        """Confirm the truth declaration and submit the form to register it.

        El paso 5 NO usa AutoFirma ni cliente PKI: la firma es la propia
        identidad Cl@ve con la que se entró al wizard. El submit "Firmar" es
        un POST Wicket normal que avanza al paso 6.
        """
        await self._wait_for_step("5")
        await self.page.locator(
            'input[type="checkbox"][name="viewFolderAdmissible:declareThatItsTrue"]'
        ).check(force=True)
        await self.page.locator(
            'button[name="viewFolderAdmissible:confirm"]'
        ).click()
        await self.page.wait_for_load_state("domcontentloaded")
        await self._wait_for_step("6")

    async def _step6_acuse(self) -> dict:
        """Extract the registry number/CSV and download the two PDFs.

        Returns ``{registry_no, csv, downloads: {recibo: Path, instancia: Path}}``.
        """
        await self._wait_for_step("6")
        body_text = await self.page.locator("body").inner_text()

        registry_no = None
        m = re.search(r"\b(\d{4}-E-RE-\d+)\b", body_text)
        if m:
            registry_no = m.group(1)

        csv = None
        m = re.search(r"CSV:\s*([A-Z0-9]+)", body_text)
        if m:
            csv = m.group(1)

        downloads: dict[str, Path] = {}
        target_dir = self.download_dir or Path.cwd()
        target_dir.mkdir(parents=True, exist_ok=True)

        # The two "Descargar Recibo" / "Descargar Instancia firmada" buttons
        # trigger Wicket form posts that stream a PDF back. Use Playwright's
        # download capture to grab them deterministically.
        for label, slug in (
            ("Descargar Recibo", "recibo"),
            ("Descargar Instancia firmada", "instancia_firmada"),
        ):
            try:
                async with self.page.expect_download(timeout=60_000) as dl_info:
                    await self.page.locator(f'button:has-text("{label}")').first.click()
                d = await dl_info.value
                dest = target_dir / f"{slug}.pdf"
                await d.save_as(str(dest))
                downloads[slug] = dest
                console.print(f"[green]CTBG: bajado {slug} → {dest}[/]")
            except PlaywrightTimeoutError as e:
                raise CtbgFillerError(
                    f"No se pudo descargar '{label}' del paso 6"
                ) from e

        return {
            "registry_no": registry_no,
            "csv": csv,
            "downloads": downloads,
        }

    # ------------------------------------------------------------------
    # Wicket helpers
    # ------------------------------------------------------------------

    async def _wait_for_step(self, number: str) -> None:
        """Wait until the current step heading shows `<n>. ...`."""
        try:
            await self.page.locator(f'h2:has-text("{number}. ")').first.wait_for(
                state="visible", timeout=30_000,
            )
        except PlaywrightTimeoutError as e:
            raise CtbgFillerError(f"Paso {number} no apareció a tiempo") from e

    async def _click_save_continue(self) -> None:
        """Click 'Guardar y continuar' and wait for the page to advance."""
        await self.page.locator('button:has-text("Guardar y continuar")').first.click()
        # Wicket re-renders fully; give it a beat before the next assertion.
        await self.page.wait_for_load_state("domcontentloaded")
        await self.page.wait_for_timeout(800)

    async def _wait_wicket_idle(self) -> None:
        """Wait until no inline edit panel is open. Signals the previous
        `Aceptar` click's Ajax round-trip has finished and the form has been
        re-rendered, so subsequent field lookups see the new DOM."""
        try:
            await self.page.locator('[name*=":modal:"]').first.wait_for(
                state="detached", timeout=15_000,
            )
        except PlaywrightTimeoutError:
            # Either there was never a panel, or the page just timed out.
            # Don't fail here — the caller's locator will raise a more
            # specific error if the page really is broken.
            pass

    async def _open_wicket_field(self, label_re: re.Pattern[str]) -> None:
        """Click the 'Escribir' / 'Seleccionar' anchor whose parent's label
        matches *label_re*.

        Each field on the Wicket form is structured as::

            <p> {label text} <a class="inputTextDiv personalizedField">Escribir</a> </p>

        so we read the anchor's *immediate parent* text and subtract the
        anchor's own text — no `closest()` walking, which was matching wider
        containers after Wicket replaced filled fields.

        Retries up to 3 times because Wicket can finish rendering after our
        idle wait completes.
        """
        await self._wait_wicket_idle()
        last_labels: list[str] = []
        for _attempt in range(3):
            anchors = self.page.locator("a.inputTextDiv.personalizedField")
            count = await anchors.count()
            last_labels = []
            for i in range(count):
                a = anchors.nth(i)
                label = await a.evaluate(
                    """el => {
                        const p = el.parentElement;
                        if (!p) return '';
                        const own = (p.innerText || '').replace(/\\s+/g, ' ').trim();
                        const link = (el.innerText || '').trim();
                        return link ? own.replace(link, '').trim() : own;
                    }"""
                )
                last_labels.append(label or "")
                text = (await a.inner_text()).strip()
                if label_re.search(label or "") and text in {"Escribir", "Seleccionar"}:
                    await a.click()
                    return
            await self.page.wait_for_timeout(400)
        sample = " | ".join(repr(l)[:90] for l in last_labels[:8])
        raise CtbgFillerError(
            f"No encontré campo para {label_re.pattern}. "
            f"Anchors visibles ({len(last_labels)}): {sample}"
        )

    async def _accept_modal(self) -> None:
        """Click the inline panel's 'Aceptar' and wait until the panel is gone.

        Waiting on the `:modal:` input being detached is deterministic — a
        fixed `wait_for_timeout` raced Wicket's Ajax round-trip and was the
        root cause of the field-not-found error David hit in production."""
        await self.page.locator('button:has-text("Aceptar")').first.click()
        await self._wait_wicket_idle()

    async def _wicket_write(self, label_re: re.Pattern[str], value: str) -> None:
        await self._open_wicket_field(label_re)
        # The freshly-opened panel always contains exactly one editable element.
        ta = self.page.locator('textarea, input[type="text"]').filter(
            has_not=self.page.locator('[disabled]'),
        )
        # Pick the visible one whose name contains ":modal:" (our inline panel).
        candidates = self.page.locator('textarea[name*=":modal:"], input[type="text"][name*=":modal:"]')
        await candidates.first.fill(value)
        await self._accept_modal()

    async def _wicket_select(self, label_re: re.Pattern[str], option_text: str) -> None:
        await self._open_wicket_field(label_re)
        sel = self.page.locator('select[name*=":select-field"]').first
        await sel.wait_for(state="attached", timeout=10_000)
        # Wicket may hide the native <select>; set the value by matching option
        # text via JS and dispatch change so the framework's listener fires.
        await sel.evaluate(
            """(el, label) => {
                const opt = Array.from(el.options).find(o => o.text.trim() === label);
                if (!opt) throw new Error('option not found: ' + label);
                el.value = opt.value;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }""",
            option_text,
        )
        await self._accept_modal()

    async def _wicket_set_date(self, label_re: re.Pattern[str], dmy: str) -> None:
        await self._open_wicket_field(label_re)
        date_input = self.page.locator('input[name*=":field:date"]').first
        await date_input.fill(dmy)
        await self._accept_modal()

    # ------------------------------------------------------------------
    # Step 3 helpers (uploads)
    # ------------------------------------------------------------------

    async def _attach_required_doc(
        self,
        slot_index: int,
        file_path: Optional[Path],
        validity: str = VALIDITY_ORIGINAL,
    ) -> None:
        if file_path is None:
            raise CtbgFillerError(f"Slot obligatorio {slot_index} sin fichero")

        # Pick "Decido aportarlo yo mismo" on the slot's select. Wicket may
        # hide the native <select> behind a custom widget — set the value via
        # JS and dispatch change so its listener fires.
        select_locator = self.page.locator(
            f'select[name*="requiredDocumentationList:{slot_index}:"]'
        ).first
        await select_locator.wait_for(state="attached", timeout=10_000)
        await select_locator.evaluate(
            """(el, value) => {
                el.value = value;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }""",
            ORIGIN_BRING_MYSELF,
        )
        await self.page.wait_for_timeout(800)  # Wicket re-render

        # The "Adjuntar" button now exists for this slot. We can rely on its
        # name attribute carrying the same path index.
        cards = self.page.locator(".c-card-document")
        await cards.nth(slot_index).locator('button:has-text("Adjuntar")').click()

        await self._upload_via_modal(file_path, validity)

    async def _upload_via_modal(self, file_path: Path, validity: str) -> None:
        """Drive the two-step "Cargar documento" iframe modal.

        Selectors are kept generic because Wicket assigns ids per render
        (`id17d` from the mapping session is not the same id at runtime).
        The modal contains exactly one select (Requisito de validez), one
        textarea (Descripción, prefilled) and one file input, so positional
        selectors are unambiguous.
        """
        iframe_selector = 'iframe[id^="_wicket_window_"]'
        # Wait for the modal iframe to actually appear (Wicket Ajax can take
        # 1–2 s) before trying to enter it.
        try:
            await self.page.locator(iframe_selector).wait_for(state="attached", timeout=15_000)
        except PlaywrightTimeoutError as e:
            raise CtbgFillerError(f"El modal de Cargar documento no apareció tras pulsar Adjuntar (subiendo {file_path.name})") from e

        iframe: FrameLocator = self.page.frame_locator(iframe_selector)

        # Step 1 of modal: pick "Requisito de validez". Wicket may wrap the
        # native <select> in a custom widget that hides it (display:none /
        # zero size), so Playwright's actionability checks fail. Set the value
        # via JS and dispatch a change event so Wicket's listener fires.
        console.print(f"[dim]CTBG: cargar {file_path.name} — step 1 (validez={validity})[/]")
        select_loc = iframe.locator("select").first
        await select_loc.wait_for(state="attached", timeout=10_000)
        await select_loc.evaluate(
            """(el, value) => {
                el.value = value;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }""",
            validity,
        )
        # Description is prefilled — leave as is.
        await iframe.locator('button:has-text("Siguiente")').first.click()
        await self.page.wait_for_timeout(1000)

        # Step 2 of modal: file input + Cargar.
        console.print(f"[dim]CTBG: cargar {file_path.name} — step 2 (set_input_files)[/]")
        file_input = iframe.locator('input[type="file"]').first
        await file_input.wait_for(state="attached", timeout=10_000)
        await file_input.set_input_files(str(file_path))
        await iframe.locator('button:has-text("Cargar")').first.click()

        # Wait for the iframe to close (modal removed from DOM).
        try:
            await self.page.locator(iframe_selector).first.wait_for(
                state="detached", timeout=60_000,
            )
            console.print(f"[green]CTBG: {file_path.name} subido[/]")
        except PlaywrightTimeoutError as e:
            raise CtbgFillerError(f"El modal de subida no cerró tras subir {file_path.name}") from e

        await self.page.wait_for_timeout(700)
