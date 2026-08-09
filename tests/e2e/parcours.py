#!/usr/bin/env python3
# Screenshot-Parcours für den Hengstverzeichnis-Testlauf.
# Läuft im crawl4ai-Image (Playwright 1.61 + Chromium). Fehlertolerant:
# jede Phase fängt ihre Fehler, protokolliert sie und lässt den Lauf weiterlaufen.
#
# Konfiguration per Umgebungsvariablen (siehe unten). Wird für beide Läufe
# (Docker + nativ) mit unterschiedlicher BASE_URL/OUT_DIR verwendet.

import os, re, time, hmac, hashlib, base64, struct, json, traceback
from playwright.sync_api import sync_playwright

BASE = os.environ["BASE_URL"].rstrip("/")
OUT = os.environ.get("OUT_DIR", "/out")
LABEL = os.environ.get("RUN_LABEL", "lauf")
ADMIN_EMAIL = os.environ.get("ADMIN_EMAIL", "hvadmin@dev.firestrike.de")
ADMIN_PW = os.environ["ADMIN_PASSWORD"]
DO_SETUP = os.environ.get("DO_SETUP", "auto")  # auto|wizard|skip  (wizard = Formular zeigen)
PHASES = os.environ.get("PHASES", "all")       # z.B. "extern,sso" oder "all"
IGNORE_TLS = os.environ.get("IGNORE_TLS", "0") == "1"

# Backup-Ziele / externe Dienste
MINIO_ENDPOINT = os.environ.get("MINIO_ENDPOINT", "http://testlauf-minio:9000")
MINIO_KEY = os.environ.get("MINIO_KEY", "testlaufadmin")
MINIO_SECRET = os.environ.get("MINIO_SECRET", "")
WEBDAV_URL = os.environ.get("WEBDAV_URL", "http://testlauf-webdav:80/hengst")
WEBDAV_USER = os.environ.get("WEBDAV_USER", "testdav")
WEBDAV_PW = os.environ.get("WEBDAV_PW", "")
FTPS_HOST = os.environ.get("FTPS_HOST", "testlauf-ftps")
FTPS_PORT = os.environ.get("FTPS_PORT", "21")
FTPS_USER = os.environ.get("FTPS_USER", "testftp")
FTPS_PW = os.environ.get("FTPS_PW", "")
SMTP_HOST = os.environ.get("SMTP_HOST", "mailserver")
SMTP_PORT = os.environ.get("SMTP_PORT", "587")
SMTP_USER = os.environ.get("SMTP_USER", "hvadmin@dev.firestrike.de")
SMTP_PW = os.environ.get("SMTP_PW", "")
ROUNDCUBE_URL = os.environ.get("ROUNDCUBE_URL", "")
OIDC_LABEL = os.environ.get("OIDC_LABEL", "")

os.makedirs(OUT, exist_ok=True)
index = []
counter = [0]
log_lines = []
click_audit = []   # (seite, anzahl_buttons, sichtbar_enabled, problematisch)
dark_audit = []    # (seite, anzahl_kontrastfunde, funde[:8])

def log(msg):
    line = f"[{time.strftime('%H:%M:%S')}] {msg}"
    print(line, flush=True)
    log_lines.append(line)

def totp(secret):
    key = base64.b32decode(secret + "=" * (-len(secret) % 8))
    msg = struct.pack(">Q", int(time.time() // 30))
    h = hmac.new(key, msg, hashlib.sha1).digest()
    o = h[19] & 15
    return f"{(struct.unpack('>I', h[o:o+4])[0] & 0x7fffffff) % 1000000:06d}"

# Ermittelt den zugänglichen Namen eines Elements so, wie es der
# Accessibility-Baum tut (aria-label -> aria-labelledby -> textContent ->
# title -> value) und dazu, WARUM es ggf. nicht sichtbar ist. Bewusst NICHT
# über inner_text(): das liefert bei nicht gerenderten Elementen (Inhalt eines
# geschlossenen <details>) einen leeren String und erzeugte früher ein
# irreführendes "?" - obestehen kein Screenreader je so sieht.
_ACC_JS = r"""el => {
  const t = s => (s || '').replace(/\s+/g, ' ').trim();
  let name = t(el.getAttribute('aria-label'));
  if (!name) {
    const lb = el.getAttribute('aria-labelledby');
    if (lb) name = t(lb.split(/\s+/).map(id => {
      const n = document.getElementById(id); return n ? n.textContent : '';
    }).join(' '));
  }
  if (!name) name = t(el.textContent);
  if (!name) name = t(el.getAttribute('title'));
  if (!name) name = t(el.value);
  const cs = getComputedStyle(el);
  return {
    name: name,
    inClosedDetails: !!el.closest('details:not([open])'),
    displayNone: cs.display === 'none' || cs.visibility === 'hidden'
  };
}"""

# Dark-Mode-Kontrast-Audit, bewusst REGRESSIONS-spezifisch: meldet nur Text,
# der im DUNKLEN Theme zu wenig Kontrast hat (< 3.0 gegen die effektive
# Hintergrundfläche) UND im HELLEN Theme lesbar wäre (>= 3.0). So werden genau
# die Dark-Mode-Fehler gefangen (hartkodierte helle Fläche + geerbte helle
# Theme-Textfarbe usw.), nicht aber pre-existing allgemeine Kontrast-Themen wie
# ein Brand-Button, der in beiden Modi grenzwertig ist - die gehören nicht ins
# Dark-Mode-Gate. Das Skript togglet data-theme selbst (dunkel -> hell -> dunkel).
_DARK_AUDIT = r"""() => {
  const parse = c => { const m=(c||'').match(/rgba?\(([^)]+)\)/); if(!m) return null;
    const p=m[1].split(',').map(x=>parseFloat(x)); return {r:p[0],g:p[1],b:p[2],a:p.length>3?p[3]:1}; };
  const lum = ({r,g,b}) => { const f=v=>{v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)};
    return 0.2126*f(r)+0.7152*f(g)+0.0722*f(b); };
  const ratio = (a,b) => { const L1=lum(a),L2=lum(b); return (Math.max(L1,L2)+0.05)/(Math.min(L1,L2)+0.05); };
  const effBg = el => { let e=el; while(e){ const c=parse(getComputedStyle(e).backgroundColor); if(c&&c.a>0.5) return c; e=e.parentElement; } return {r:22,g:24,b:28,a:1}; };
  const root=document.documentElement;
  root.setAttribute('data-theme','dark');
  const cand=[];
  document.querySelectorAll('body *').forEach(el=>{
    const txt=[...el.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent.trim()).join(' ').trim();
    if(!txt||txt.length<2) return;
    const r=el.getBoundingClientRect(); if(r.width<2||r.height<2) return;
    const st=getComputedStyle(el); if(st.visibility==='hidden'||st.display==='none'||parseFloat(st.opacity)<0.3) return;
    const fg=parse(st.color); if(!fg) return;
    const cr=ratio(fg,effBg(el));
    if(cr<3.0) cand.push({el, txt:txt.slice(0,45), color:st.color, bg:st.backgroundColor, dr:Math.round(cr*100)/100});
  });
  root.setAttribute('data-theme','light');
  const out=[]; const seen=new Set();
  cand.forEach(c=>{
    const fg=parse(getComputedStyle(c.el).color); if(!fg) return;
    const lr=ratio(fg,effBg(c.el));
    if(lr>=3.0){ const key=c.txt.slice(0,30);
      if(seen.has(key)) return; seen.add(key);
      out.push({text:c.txt, color:c.color, bg:c.bg, ratio:c.dr, lightRatio:Math.round(lr*100)/100}); }
  });
  root.setAttribute('data-theme','dark');
  return out;
}"""

def audit_clickable(page, slug):
    """Prüft alle interaktiven Bedienelemente der aktuellen Seite auf echte
    Klickbarkeit und protokolliert Auffälligkeiten mit ihrem zugänglichen
    Namen. Eingeklappte Bereiche (native <details>) werden zuerst AUFGEKLAPPT,
    damit auch ihre Bedienelemente geprüft werden. Die Klickbarkeit wird per
    Actionability-Probe (trial=True) verifiziert - das scrollt hin und prüft
    sichtbar/stabil/treffbar/enabled, OHNE die Aktion auszulösen (ein echter
    Klick auf 'Anwenden'/'Suchen' würde die Seite wegnavigieren). Meldet
    zusätzlich einen a11y-Defekt: einen sichtbaren Knopf ohne Namen."""
    try:
        # 1) Eingeklappte <details> öffnen, damit ihr Inhalt gerendert und
        #    damit prüfbar wird (Screenshot ist zu diesem Zeitpunkt schon weg).
        try:
            page.eval_on_selector_all(
                'details:not([open])', 'els => els.forEach(d => { d.open = true; })')
            page.wait_for_timeout(250)
        except Exception:
            pass
        els = page.query_selector_all(
            'button, input[type="submit"], input[type="button"], a.btn, [role="button"]')
        total = len(els)
        clickable = 0
        problems = []
        for e in els:
            try:
                vis = e.is_visible()
                ena = e.is_enabled()
                info = e.evaluate(_ACC_JS)
                name = info.get("name") or ""
                aufgeklappt = info.get("inClosedDetails")  # war im <details>, jetzt offen
                if vis and ena:
                    # Echte Klickbarkeit: Actionability-Probe ohne Auslösen.
                    try:
                        e.click(trial=True, timeout=2500)
                        clickable += 1
                        if not name:
                            problems.append("⚠ sichtbarer Bedienknopf OHNE zugänglichen Namen")
                        elif aufgeklappt:
                            problems.append(f"{name[:40]} [nach Aufklappen klickbar ✓]")
                    except Exception:
                        label = (name or "(ohne Namen)")[:40]
                        problems.append(f"{label} [sichtbar, aber nicht klickbar/verdeckt]")
                    continue
                # nicht sichtbar bzw. nicht enabled - auch nach dem Aufklappen
                if not ena:
                    grund = "disabled"
                elif info.get("displayNone"):
                    grund = "display:none (bedingt eingeblendet)"
                else:
                    grund = "unsichtbar"
                label = (name or "(ohne Namen)")[:40]
                problems.append(f"{label} [{grund}]")
            except Exception:
                pass
        click_audit.append((slug, total, clickable, "; ".join(problems[:6])))
        return total, clickable, problems
    except Exception as e:
        click_audit.append((slug, -1, -1, f"audit-fehler: {e}"))
        return 0, 0, []

def shot(page, slug, desc, url=None, wait="load"):
    counter[0] += 1
    n = f"{counter[0]:03d}"
    fn = f"{n}-{slug}.png"
    status = "ok"
    try:
        if url is not None:
            resp = page.goto(BASE + url, wait_until=wait, timeout=30000)
            page.wait_for_timeout(500)
        page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
    except Exception as e:
        status = f"FEHLER: {type(e).__name__}: {str(e)[:120]}"
        try:
            page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
        except Exception:
            fn = "(kein Screenshot)"
    total, clickable, problems = audit_clickable(page, slug)
    btn = f" · Buttons {clickable}/{total} klickbar" + (f" ⚠ {problems[0]}" if problems else "")
    index.append((n, fn, url or "(aktuelle Seite)", desc + btn, status))
    log(f"{n} {slug}: {status}{btn}")
    return status

def click_submit(page, action_suffix, desc, slug, expect=None):
    """Verifiziert Klickbarkeit UND führt eine echte Button-Aktion aus: findet
    den Submit-Button des Formulars mit action*=suffix, prüft enabled, klickt,
    prüft das Ergebnis. Screenshot des Resultats."""
    counter[0] += 1
    n = f"{counter[0]:03d}"
    fn = f"{n}-{slug}.png"
    status = "ok"
    try:
        sel = f'form[action*="{action_suffix}"] button, form[action*="{action_suffix}"] input[type="submit"]'
        btn = page.query_selector(sel)
        if not btn:
            status = "FEHLER: Button nicht gefunden"
        elif not btn.is_enabled():
            status = "FEHLER: Button disabled"
        else:
            btn.scroll_into_view_if_needed()
            try:
                btn.click(timeout=12000)
            except Exception:
                # Klick abgesetzt; langsame Server-Aktion (Backup/Cron/Mail/Update)
                # kann die Actionability-Prüfung überdauern - Aktion läuft trotzdem.
                pass
            try:
                page.wait_for_load_state("domcontentloaded", timeout=55000)
            except Exception:
                pass
            page.wait_for_timeout(1200)
            if expect and expect not in page.url and expect not in page.content():
                status = "Button geklickt (Ergebnis abweichend)"
            else:
                status = "Button geklickt ✓"
        page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
    except Exception as e:
        status = f"FEHLER: {str(e)[:100]}"
        try: page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
        except Exception: fn = "(kein Screenshot)"
    index.append((n, fn, f"Klick: {action_suffix}", desc, status))
    log(f"{n} {slug}: {status}")
    return status

def get_csrf(page, path="/admin/users/create"):
    page.goto(BASE + path, wait_until="domcontentloaded", timeout=30000)
    val = page.get_attribute('input[name="csrf_token"]', "value")
    return val or ""

def post(page, path, data, multipart=None):
    """Session-authentifizierter POST über den Browser-Cookie-Store."""
    try:
        if multipart is not None:
            r = page.request.post(BASE + path, multipart=multipart)
        else:
            r = page.request.post(BASE + path, form=data)
        return r.status, r.url
    except Exception as e:
        log(f"  POST {path} Fehler: {e}")
        return 0, ""

def find_id(page, list_path, name):
    """ID eines Datensatzes aus einer Admin-Liste über den Namen holen."""
    try:
        page.goto(BASE + list_path, wait_until="domcontentloaded", timeout=30000)
        html = page.content()
        for m in re.finditer(r"<tr[^>]*>(.*?)</tr>", html, re.S):
            row = m.group(1)
            if f"<strong>{name}</strong>" in row:
                mid = re.search(r"<td[^>]*>\s*(\d+)\s*</td>", row)
                if mid:
                    return int(mid.group(1))
    except Exception as e:
        log(f"  find_id({name}) Fehler: {e}")
    return None

def phase_enabled(name):
    return PHASES == "all" or name in PHASES.split(",")

# ---------------------------------------------------------------------------

def run():
    with sync_playwright() as p:
        browser = p.chromium.launch(args=["--no-sandbox", "--disable-dev-shm-usage"])
        ctx = browser.new_context(viewport={"width": 1440, "height": 1024},
                                  ignore_https_errors=IGNORE_TLS)
        page = ctx.new_page()
        page.set_default_timeout(15000)

        csrf = {"v": ""}

        # ---- Phase: Setup / 2FA --------------------------------------------
        def phase_setup():
            if DO_SETUP == "wizard":
                # Server-Umgebung: Wizard von Hand zeigen
                shot(page, "setup-wizard", "Ersteinrichtungs-Assistent (leere DB)", "/setup")
                # Formular ausfüllen und absenden
                try:
                    page.fill('[name="site_name"]', "Testverband Hengstverzeichnis")
                except Exception:
                    pass
                # Datenbank-Abschnitt des Wizards (Server-Umgebung: echte DB-Angaben)
                for sel, val in [('[name="db_host"]', os.environ.get("DB_HOST", "127.0.0.1")),
                                 ('[name="db_port"]', os.environ.get("DB_PORT", "3306")),
                                 ('[name="db_name"]', os.environ.get("DB_NAME", "")),
                                 ('[name="db_user"]', os.environ.get("DB_USER", "")),
                                 ('[name="db_pass"]', os.environ.get("DB_PASS", ""))]:
                    if val:
                        try: page.fill(sel, val)
                        except Exception: pass
                for sel, val in [('[name="username"]', "hvadmin"),
                                 ('[name="email"]', ADMIN_EMAIL),
                                 ('[name="password"]', ADMIN_PW),
                                 ('[name="password_confirm"]', ADMIN_PW)]:
                    try: page.fill(sel, val)
                    except Exception: pass
                shot(page, "setup-wizard-ausgefuellt", "Assistent ausgefüllt")
                try:
                    page.click('button[type="submit"]')
                    page.wait_for_load_state("domcontentloaded", timeout=30000)
                except Exception as e:
                    log(f"  Wizard-Submit: {e}")
                # OIDC app-lokal nachrüsten (Server-Umgebung nutzt den gemeinsamen
                # www-Pool, daher nicht über Pool-Env): db_config.php um die OIDC-
                # Schlüssel ergänzen, sofern der Wizard sie (erwartungsgemäß) nicht setzt.
                dbcfg = os.environ.get("OIDC_DBCONFIG_PATH", "")
                issuer = os.environ.get("OIDC_ISSUER_URL", "")
                if dbcfg and issuer and os.path.exists(dbcfg):
                    try:
                        txt = open(dbcfg).read()
                        if "oidc_issuer_url" not in txt:
                            inj = ("  'oidc_issuer_url' => %r,\n"
                                   "  'oidc_client_id' => %r,\n"
                                   "  'oidc_client_secret' => %r,\n"
                                   "  'oidc_provider_label' => %r,\n)"
                                   % (issuer, os.environ.get("OIDC_CLIENT_ID",""),
                                      os.environ.get("OIDC_CLIENT_SECRET",""),
                                      os.environ.get("OIDC_LABEL","SSO")))
                            idx = txt.rstrip().rfind(")")
                            txt = txt[:idx] + inj + ";\n"
                            open(dbcfg, "w").write(txt)
                            log("  OIDC in db_config.php ergänzt (Server-Umgebung)")
                    except Exception as e:
                        log(f"  OIDC-Injektion fehlgeschlagen: {e}")
            elif DO_SETUP != "skip":
                # Auto-Provisionierung IN DIESER Session auslösen (hält pending_2fa_user_id,
                # damit /2fa/setup danach das Secret rendert). GET /setup provisioniert bei
                # leerer DB und leitet auf /2fa/setup.
                page.goto(BASE + "/setup", wait_until="domcontentloaded", timeout=30000)
                log(f"  nach GET /setup auf {page.url}")
            # 2FA abschließen (beide Wege enden hier)
            page.goto(BASE + "/2fa/setup", wait_until="domcontentloaded", timeout=30000)
            body = page.content()
            m = re.search(r"Geheimer Schlüssel:\s*<strong>([A-Z2-7]+)</strong>", body)
            if not m:
                m = re.search(r"<strong>([A-Z2-7]{16,})</strong>", body)
            if not m:
                log("  2FA-Secret NICHT gefunden — Abbruch der Setup-Phase")
                shot(page, "2fa-setup-fehler", "2FA-Setup: Secret nicht gefunden")
                return False
            secret = m.group(1)
            log(f"  2FA-Secret gescraped ({secret[:4]}…)")
            # Vollständiges Secret in eine Sidecar-Datei (NICHT in Chat/INDEX/Share) —
            # wird nach dem Lauf in die KeePass-Datei übernommen, damit der Benutzer
            # sich an der bleibenden Instanz mit 2FA anmelden kann.
            try:
                with open(os.path.join(OUT, "totp_secret.txt"), "w") as fsec:
                    fsec.write(secret + "\n")
            except Exception:
                pass
            shot(page, "2fa-setup", "2FA-Einrichtung mit QR-Code und Backup-Codes")
            page.check('[name="confirm_backup"]')
            page.fill('[name="totp_code"]', totp(secret))
            page.click('button[type="submit"]')
            page.wait_for_load_state("domcontentloaded", timeout=30000)
            log(f"  2FA-Enable abgesendet, jetzt auf {page.url}")
            # Harte Verifikation: wirklich eingeloggt?
            page.goto(BASE + "/admin", wait_until="domcontentloaded", timeout=30000)
            if "/login" in page.url:
                log("  KRITISCH: nach 2FA nicht eingeloggt (/admin -> /login). Abbruch der Setup-Phase.")
                shot(page, "login-fehlgeschlagen", "KRITISCH: Login/2FA fehlgeschlagen")
                return False
            csrf["v"] = get_csrf(page)
            log(f"  Login bestätigt (auf {page.url}), CSRF={'ja' if csrf['v'] else 'nein'}")
            return True

        # ---- Phase: leere Ansichten ----------------------------------------
        def phase_leer():
            shot(page, "admin-dashboard-leer", "Admin-Dashboard (vor Daten/Addons)", "/admin")
            for slug, url, desc in [
                ("settings", "/admin/settings", "Verbands-/Branding-Einstellungen"),
                ("system-settings", "/admin/system-settings", "Systemeinstellungen (Sprache, Registrierung, Sichtbarkeit)"),
                ("mail-settings", "/admin/mail-settings", "Mail-/SMTP-Einstellungen"),
                ("groups", "/admin/groups", "Gruppen-/Berechtigungsmatrix"),
                ("users", "/admin/users", "Benutzerverwaltung"),
                ("logs", "/admin/logs", "Audit-Log"),
                ("plugins-leer", "/admin/plugins", "Plugin-Verwaltung (vor Installation)"),
                ("backups", "/admin/backups", "Backup-Ziele"),
                ("digest", "/admin/digest", "E-Mail-Digest"),
                ("cron", "/admin/cron", "Zeitgesteuerte Aufgaben"),
                ("updates", "/admin/updates", "Update-Verwaltung"),
                ("api-keys", "/api-keys", "API-Schlüssel (Selfservice)"),
            ]:
                shot(page, slug, desc, url)

        # ---- Phase: Addon-Store --------------------------------------------
        SLUGS = ["besucherstatistik","inzuchtkoeffizient","statistik-dashboard","katalog-export",
                 "pedigree-export","qr-code","zuchtschau-ergebnisse","deckanfrage","verkaufsboerse",
                 "genealogie-vergleich","farbvererbung","anpaarungs-empfehlung","galerie",
                 "gesundheitstests","merkliste"]

        def phase_store():
            # Install-Buttons des Stores haben onsubmit confirm() -> Dialog bestätigen,
            # damit der KLICK-NACHWEIS die Installation wirklich auslöst.
            page.on("dialog", lambda d: d.accept())
            shot(page, "store-katalog", "Addon-Store: Katalog aus dem GitHub-Repo", "/admin/plugins/store")
            # Katalog laden (repo_id ermitteln)
            page.goto(BASE + "/admin/plugins/store?refresh=1", wait_until="domcontentloaded", timeout=90000)
            html = page.content()
            mrepo = re.search(r'name="repo_id"\s+value="(\d+)"', html)
            repo_id = mrepo.group(1) if mrepo else "1"
            log(f"  Store repo_id={repo_id}")
            # KLICK-NACHWEIS: ein Addon per echtem Store-Button installieren
            click_submit(page, "/admin/plugins/store/install",
                         "KLICK-NACHWEIS: Addon per Store-Button installiert", "store-install-klick",
                         expect="installed")
            installed = 0
            for slug in SLUGS:
                st, _ = post(page, "/admin/plugins/store/install",
                             {"csrf_token": csrf["v"], "repo_id": repo_id, "slug": slug})
                if st and st < 400:
                    installed += 1
                else:
                    log(f"  install {slug}: status {st}")
                time.sleep(0.4)
            log(f"  {installed}/{len(SLUGS)} Addons installiert")
            shot(page, "store-nach-install", "Store nach Installation aller Addons", "/admin/plugins/store")
            # aktivieren
            activated = 0
            for slug in SLUGS:
                st, _ = post(page, "/admin/plugins/toggle",
                             {"csrf_token": csrf["v"], "slug": slug, "enable": "1"})
                if st and st < 400:
                    activated += 1
                time.sleep(0.3)
            log(f"  {activated}/{len(SLUGS)} Addons aktiviert")
            # HARTE VERIFIKATION: POST-2xx heißt nicht, dass der Tarball-Download
            # klappte. Tatsächlich installierte Addons aus /admin/plugins zählen.
            page.goto(BASE + "/admin/plugins", wait_until="domcontentloaded", timeout=30000)
            plugins_html = page.content()
            real = sum(1 for s in SLUGS if s in plugins_html)
            counter[0] += 1; n = f"{counter[0]:03d}"; fn = f"{n}-plugins-verifiziert.png"
            page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
            vst = f"{real}/{len(SLUGS)} Addons real installiert" + ("" if real == len(SLUGS) else " — FEHLER: unvollständig")
            index.append((n, fn, "/admin/plugins", "Verifikation: real installierte Addons", vst))
            log(f"{n} plugins-verifiziert: {vst}")
            shot(page, "plugins-aktiv", "Plugin-Verwaltung: alle 15 Addons aktiv", "/admin/plugins")
            shot(page, "admin-dashboard-voll", "Admin-Dashboard mit Plugin-Kacheln", "/admin")
            shot(page, "groups-mit-plugins", "Berechtigungsmatrix mit Plugin-Modulen", "/admin/groups")

        # ---- Phase: Stammdaten ---------------------------------------------
        ids = {}
        def phase_daten():
            c = csrf["v"]
            # KLICK-NACHWEIS: eine Person komplett per echtem Formular-Klick anlegen
            try:
                page.goto(BASE + "/admin/persons/create", wait_until="domcontentloaded", timeout=30000)
                audit_clickable(page, "persons-create-form")
                page.fill('[name="name"]', "Klicknachweis Person")
                if page.query_selector('[name="contact_info"]'):
                    page.fill('[name="contact_info"]', "klick@example.org")
                if page.query_selector('[name="is_published"]'):
                    page.check('[name="is_published"]')
                page.click('form button[type="submit"], form input[type="submit"]')
                page.wait_for_load_state("domcontentloaded", timeout=30000)
                ok = "success" in page.url or "personen" in page.url.lower()
                counter[0]+=1; nn=f"{counter[0]:03d}"
                page.screenshot(path=os.path.join(OUT, f"{nn}-klicknachweis-person.png"), full_page=True, timeout=15000)
                index.append((nn, f"{nn}-klicknachweis-person.png", "/admin/persons/create → store",
                              "KLICK-NACHWEIS: Person per echtem Formular-Klick angelegt",
                              "Button geklickt ✓" if ok else f"geklickt, Ergebnis: {page.url}"))
                log(f"  Klick-Nachweis Person: {'OK' if ok else 'unklar'} ({page.url})")
            except Exception as e:
                log(f"  Klick-Nachweis Person Fehler: {e}")
            # Personen
            for pname, role in [("Züchterin Andrea Nord", "breeder"), ("Besitzer Bernd Süd", "owner")]:
                post(page, "/admin/persons/store",
                     {"csrf_token": c, "name": pname, "contact_info": pname.split()[-1] + "@example.org",
                      "is_published": "1"})
            ids["breeder"] = find_id(page, "/admin/persons", "Züchterin Andrea Nord")
            ids["owner"] = find_id(page, "/admin/persons", "Besitzer Bernd Süd")
            shot(page, "admin-personen", "Personenverwaltung mit Datensätzen", "/admin/persons")

            # Deckstation (veröffentlicht, mit Mail -> Deckanfrage)
            post(page, "/admin/breeding-stations/store",
                 {"csrf_token": c, "name": "Gestüt Nordlicht", "contact_person": "A. Nord",
                  "address": "Weideweg 1\n24000 Kiel", "phone": "0431 12345",
                  "email": "deckstation@dev.firestrike.de", "website": "https://gestuet-nordlicht.example.org",
                  "is_published": "1"})
            ids["station"] = find_id(page, "/admin/breeding-stations", "Gestüt Nordlicht")
            shot(page, "admin-stationen", "Deckstationsverwaltung", "/admin/breeding-stations")

            # Stammbaum mit gemeinsamem Vorfahren (COI > 0):
            #  Urvater -> Vater, Urvater -> Mutter, (Vater x Mutter) -> Hauptpferd
            def horse(name, extra):
                d = {"csrf_token": c, "name": name, "status": "active", "is_published": "1"}
                d.update(extra)
                post(page, "/admin/horses/store", d)
                return find_id(page, "/admin/horses", name)
            ids["urvater"] = horse("Urvater vom Nordlicht", {"color": "Braun", "birth_year": "2005"})
            ids["vater"] = horse("Nordwind", {"color": "Fuchs", "birth_year": "2012",
                                              "sire_id": str(ids["urvater"] or "")})
            ids["mutter"] = horse("Nordsee", {"color": "Rappe", "birth_year": "2013",
                                              "sire_id": str(ids["urvater"] or "")})
            # Hauptpferd: Eltern + Station + Personen-Rollen
            hd = {"csrf_token": c, "name": "Hengst Nordstern", "status": "active", "is_published": "1",
                  "ueln": "DE000000000001", "color": "Falbe", "birth_year": "2020",
                  "description": "Vorführpferd des Testlaufs mit vollständigem Stammbaum.",
                  "sire_id": str(ids["vater"] or ""), "dam_id": str(ids["mutter"] or ""),
                  "breeding_station_id": str(ids["station"] or ""),
                  "persons[0][person_id]": str(ids["breeder"] or ""), "persons[0][role]": "breeder",
                  "persons[1][person_id]": str(ids["owner"] or ""), "persons[1][role]": "owner",
                  "persons[1][breeding_station_id]": str(ids["station"] or "")}
            post(page, "/admin/horses/store", hd)
            ids["haupt"] = find_id(page, "/admin/horses", "Hengst Nordstern")
            # ein zweites veröffentlichtes Pferd ohne Verwandtschaft (Katalog-Vielfalt)
            ids["solo"] = horse("Stute Ostwind", {"color": "Schimmel", "birth_year": "2019"})
            # Pferd mit Text-Eltern -> /admin/matches
            horse("Fohlen Westwind", {"color": "Braun", "birth_year": "2023",
                                      "sire_name": "Nordwind", "dam_name": "Nordsee"})
            shot(page, "admin-pferde", "Pferdeverwaltung mit Stammbaum-Datensätzen", "/admin/horses")
            shot(page, "admin-matches", "Verknüpfungs-/Match-Vorschläge (Text-Eltern)", "/admin/matches")
            if ids.get("haupt"):
                shot(page, "admin-pferd-edit", "Pferd bearbeiten (Detailformular)",
                     f"/admin/horses/edit?id={ids['haupt']}")

            # CSV-Import (Preview + Commit)
            csv = ("name;ueln;birth_year;color;status\n"
                   "Import Alpha;DE000000000101;2018;Fuchs;active\n"
                   "Import Beta;DE000000000102;2019;Rappe;active\n"
                   "Import Gamma;DE000000000103;2021;Braun;active\n")
            st, _ = post(page, "/admin/import/horses/preview", None,
                         multipart={"csrf_token": c,
                                    "csv_file": {"name": "import.csv", "mimeType": "text/csv",
                                                 "buffer": csv.encode()}})
            shot(page, "import-vorschau", "CSV-Import: Vorschau", "/admin/import/horses" if st==0 else None)
            post(page, "/admin/import/horses/commit", {"csrf_token": c, "is_published": "1"})
            log(f"  CSV-Import commit ausgeführt (preview-status {st})")

            # Galerie: Bild-Upload zum Hauptpferd
            if ids.get("haupt"):
                png = base64.b64decode(
                    "iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAADdOtwvAAAARUlEQVR4nO3PMQ0AMAwDsPBHhQ"
                    "z9k7ZQSXsg8AJ2vXwFAAA=")  # klein, valide? sicherheitshalber echt erzeugen
                # zuverlässiges PNG per Playwright-Canvas gibt es nicht; nutze eingebettetes
                mp = {"csrf_token": c, "horse_id": str(ids["haupt"]), "caption": "Testfoto Nordstern",
                      "image": {"name": "foto.png", "mimeType": "image/png", "buffer": png}}
                st, _ = post(page, "/plugin/galerie/verwaltung/store", None, multipart=mp)
                log(f"  Galerie-Upload status {st}")
                # Video-Fallback (immer erlaubt)
                post(page, "/plugin/galerie/verwaltung/store",
                     {"csrf_token": c, "horse_id": str(ids["haupt"]),
                      "video_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
                      "caption": "Vorführvideo"})
                shot(page, "galerie-verwaltung", "Galerie-Verwaltung", "/plugin/galerie/verwaltung")

                # Gesundheitstest mit PDF
                pdf = b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \ntrailer<</Root 1 0 R/Size 4>>\nstartxref\n0\n%%EOF"
                st, _ = post(page, "/plugin/gesundheitstests/verwaltung/store", None,
                             multipart={"csrf_token": c, "horse_id": str(ids["haupt"]),
                                        "test_type": "Röntgen OCD", "result_summary": "ohne Befund",
                                        "issued_by": "Tierklinik Nord", "issued_at": "2024-05-01",
                                        "is_public": "1",
                                        "document": {"name": "befund.pdf", "mimeType": "application/pdf",
                                                     "buffer": pdf}})
                log(f"  Gesundheitstest status {st}")
                shot(page, "gesundheit-verwaltung", "Gesundheitstests-Verwaltung", "/plugin/gesundheitstests/verwaltung")

                # Zuchtschau-Ergebnis
                post(page, "/plugin/zuchtschau-ergebnisse/ergebnisse/store",
                     {"csrf_token": c, "horse_id": str(ids["haupt"]), "event_name": "Landeskörung 2024",
                      "event_date": "2024-09-15", "category": "Hengstkörung", "score": "8.5",
                      "judge": "Dr. Richter", "placement": "1. Reserve", "comment": "Sehr guter Bewegungsablauf."})
                shot(page, "zuchtschau-verwaltung", "Zuchtschau-Ergebnisverwaltung", "/plugin/zuchtschau-ergebnisse/ergebnisse")

                # Verkaufsinserat
                post(page, "/plugin/verkaufsboerse/verwaltung/store",
                     {"csrf_token": c, "horse_id": str(ids["solo"] or ids["haupt"]),
                      "price": "15000", "description": "Umgänglich, gut geritten.",
                      "contact_email": "verkauf@dev.firestrike.de", "listed_until": "2026-12-31"})
                shot(page, "verkauf-verwaltung", "Verkaufsbörse-Verwaltung", "/plugin/verkaufsboerse/verwaltung")

            # DSGVO-Anfrage (öffentlich) -> /admin/gdpr
            post(page, "/dsgvo", {"name": "Interessent Meyer", "email": "meyer@example.org",
                                  "request_type": "info", "message": "Bitte um Auskunft."})
            shot(page, "admin-gdpr", "DSGVO-Anfragenverwaltung", "/admin/gdpr")

            # Papierkorb füllen: das Solo-Pferd löschen
            if ids.get("solo"):
                post(page, "/admin/horses/delete", {"csrf_token": c, "id": str(ids["solo"])})
            shot(page, "admin-trash", "Papierkorb mit soft-gelöschtem Datensatz", "/admin/trash")

        # ---- Phase: öffentliche + Addon-Ansichten (befüllt) ----------------
        def phase_ansichten():
            shot(page, "public-start", "Öffentliche Startseite", "/")
            shot(page, "public-katalog", "Öffentlicher Katalog", "/katalog")
            hid = ids.get("haupt")
            if hid:
                shot(page, "public-hengst-detail", "Öffentliche Pferde-Detailseite (Stammbaum, COI, Addon-Abschnitte)",
                     f"/hengst?id={hid}")
            if ids.get("station"):
                shot(page, "public-station", "Öffentliche Deckstationsseite", f"/station?id={ids['station']}")
            shot(page, "public-impressum", "Impressum", "/impressum")
            shot(page, "public-datenschutz", "Datenschutz", "/datenschutz")
            shot(page, "public-dsgvo", "DSGVO-Kontaktformular", "/dsgvo")

            # Addon-Ansichten (Admin-Sicht, alle Rechte)
            addon_urls = [
                ("addon-besucherstatistik", "/plugin/besucherstatistik/statistik", "Besucherstatistik"),
                ("addon-statistik-dashboard", "/plugin/statistik-dashboard/statistik", "Statistik-Dashboard"),
                ("addon-katalog-export", "/plugin/katalog-export/formular", "Katalog-Export (Formular)"),
                ("addon-farbvererbung", "/plugin/farbvererbung/rechner", "Farbvererbungsrechner"),
                ("addon-merkliste", "/plugin/merkliste", "Merkliste"),
                ("addon-genealogie", "/plugin/genealogie-vergleich", "Genealogie-Vergleich"),
            ]
            for slug, url, desc in addon_urls:
                shot(page, slug, desc, url)
            hid = ids.get("haupt")
            if hid and ids.get("vater") and ids.get("mutter"):
                shot(page, "addon-inzucht-rechner",
                     "Verpaarungsrechner (Inzuchtkoeffizient)",
                     f"/plugin/inzuchtkoeffizient/rechner?sire_id={ids['vater']}&dam_id={ids['mutter']}&depth=6")
                shot(page, "addon-anpaarung",
                     "Anpaarungs-Empfehlung",
                     f"/plugin/anpaarungs-empfehlung/empfehlung?base_id={hid}&depth=5&limit=10")
                shot(page, "addon-pedigree-export",
                     "Pedigree-Export (Druckansicht)", f"/plugin/pedigree-export/export?id={hid}&depth=6")
                shot(page, "addon-qr-aushang", "QR-Code-Aushang", f"/plugin/qr-code/aushang?id={hid}")
                shot(page, "addon-genealogie-paar",
                     "Genealogie-Vergleich zweier Pferde",
                     f"/plugin/genealogie-vergleich?horse_a={hid}&horse_b={ids['vater']}&depth=5")
            shot(page, "addon-verkauf-liste", "Verkaufsbörse (öffentliche Liste)", "/plugin/verkaufsboerse/liste")

        # ---- Phase: Gast-Sicht (ohne Login) --------------------------------
        def phase_gast():
            gctx = browser.new_context(viewport={"width": 1440, "height": 1024}, ignore_https_errors=IGNORE_TLS)
            gp = gctx.new_page()
            def gshot(slug, desc, url):
                counter[0] += 1
                n = f"{counter[0]:03d}"; fn = f"{n}-{slug}.png"; status="ok"
                try:
                    gp.goto(BASE + url, wait_until="domcontentloaded", timeout=30000); gp.wait_for_timeout(400)
                    gp.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
                except Exception as e:
                    status = f"FEHLER: {str(e)[:100]}"
                index.append((n, fn, url, desc, status)); log(f"{n} {slug}: {status}")
            gshot("gast-katalog", "Katalog als anonymer Gast", "/katalog")
            if ids.get("haupt"):
                gshot("gast-hengst-detail", "Pferde-Detailseite als Gast (Deckanfrage-Formular sichtbar)",
                      f"/hengst?id={ids['haupt']}")
            gshot("gast-login", "Login-Seite" + (f" mit SSO-Button ({OIDC_LABEL})" if OIDC_LABEL else ""), "/login")
            gctx.close()

        # ---- Phase: Filter + Reset -----------------------------------------
        def phase_filter():
            # Testet den Katalog-Filter UND den Reset-Button. Der Reset ist per
            # display:none verborgen, solange kein Filter aktiv ist - er lässt
            # sich also nur prüfen, indem man erst filtert. Ablauf: ungefiltert
            # zählen -> Volltext-Filter setzen (Treffer grenzen sich ein) ->
            # den nun sichtbaren Reset-Button auf Klickbarkeit prüfen UND echt
            # klicken -> prüfen, dass die Trefferliste zurück auf voll ist.
            def cards():
                return len(page.query_selector_all('a.btn:has-text("Profil ansehen")'))
            page.goto(BASE + "/katalog", wait_until="load", timeout=30000); page.wait_for_timeout(500)
            vorher = cards()
            shot(page, "filter-katalog-voll", f"Katalog ungefiltert ({vorher} Hengste)", None)
            # Filter anwenden: Volltextsuche nach 'Nordstern'
            try:
                page.fill('input[placeholder*="Volltextsuche"], input[type="text"]', "Nordstern")
                page.click('button:has-text("Suchen")')
                page.wait_for_load_state("load", timeout=15000); page.wait_for_timeout(600)
            except Exception as e:
                log(f"  Filter anwenden Fehler: {e}")
            nachher = cards()
            counter[0] += 1; n = f"{counter[0]:03d}"; fn = f"{n}-filter-aktiv.png"
            page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
            ok_filter = ("search=Nordstern" in page.url) and (0 < nachher < vorher)
            st = (f"Filter wirkt: {vorher}→{nachher} Treffer ✓" if ok_filter
                  else f"FEHLER: Filter ohne Wirkung ({vorher}→{nachher}, URL {page.url})")
            index.append((n, fn, "/katalog?search=Nordstern", "Katalog-Filter angewandt (Volltextsuche)", st))
            log(f"{n} filter-aktiv: {st}")
            # Reset-Button ist jetzt eingeblendet -> Klickbarkeit prüfen + klicken
            reset = page.query_selector('#btn-reset-filters')
            rvis = rena = False
            counter[0] += 1; n = f"{counter[0]:03d}"; fn = f"{n}-filter-reset-klick.png"
            try:
                rvis = reset.is_visible() if reset else False
                rena = reset.is_enabled() if reset else False
                if reset and rvis and rena:
                    reset.click(trial=True, timeout=3000)   # klickbar? (ohne Auslösen)
                    reset.click(timeout=5000)                # wirklich zurücksetzen
                    page.wait_for_load_state("load", timeout=15000); page.wait_for_timeout(600)
                page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
            except Exception as e:
                try: page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
                except Exception: pass
                log(f"  Reset-Klick Fehler: {e}")
            zurueck = cards()
            ok_reset = bool(reset) and rvis and rena and (zurueck == vorher)
            st2 = (f"Reset-Button sichtbar+klickbar, Treffer zurück {nachher}→{zurueck} ✓" if ok_reset
                   else f"FEHLER: Reset unwirksam (sichtbar={rvis} enabled={rena} Treffer {nachher}→{zurueck}, erwartet {vorher})")
            index.append((n, fn, "Klick: #btn-reset-filters", "Filter-Reset per echtem Button-Klick", st2))
            log(f"{n} filter-reset-klick: {st2}")

        # ---- Phase: API ----------------------------------------------------
        def phase_api():
            # Formular per echtem Klick abschicken; der Klartext-Schlüssel wird
            # nur EINMALIG direkt danach angezeigt (nicht beim Neuladen).
            page.goto(BASE + "/api-keys", wait_until="domcontentloaded", timeout=30000)
            try:
                page.fill('[name="label"]', "Testlauf-Schlüssel")
            except Exception: pass
            try:
                page.click('form[action*="/api-keys/create"] button[type="submit"], '
                           'form[action*="/api-keys/create"] input[type="submit"], '
                           'form[action*="/api-keys/create"] button')
                page.wait_for_load_state("domcontentloaded", timeout=30000)
                page.wait_for_timeout(500)
            except Exception as e:
                log(f"  API-Key Klick: {e}")
            shot(page, "api-keys-erstellt", "API-Schlüssel per echtem Klick angelegt (Klartext einmalig sichtbar)")
            key = None
            m = re.search(r"(hv_[0-9a-fA-F]{20,})", page.content())
            if m: key = m.group(1)
            if key:
                try:
                    r = page.request.get(BASE + "/api/horses", headers={"Authorization": f"Bearer {key}"})
                    data = r.json()
                    with open(os.path.join(OUT, "api-horses-response.json"), "w") as f:
                        json.dump(data, f, indent=2, ensure_ascii=False)
                    log(f"  API /api/horses: {r.status}, {len(data.get('data', []))} Pferde")
                    # Screenshot der JSON-Antwort im Browser
                    page.goto("data:application/json," + json.dumps(data)[:1500], wait_until="domcontentloaded")
                    shot(page, "api-antwort", "JSON-API-Antwort (mit Bearer-Schlüssel)")
                except Exception as e:
                    log(f"  API-Abfrage Fehler: {e}")
            else:
                log("  Kein API-Schlüssel im HTML gefunden")

        # ---- Phase: externe Dienste (Mail/Backup/Cron/Update) --------------
        def phase_extern():
            c = csrf["v"]
            # SMTP konfigurieren
            post(page, "/admin/mail-settings",
                 {"csrf_token": c, "mail_driver": "smtp", "smtp_host": SMTP_HOST, "smtp_port": SMTP_PORT,
                  "smtp_encryption": "tls", "smtp_user": SMTP_USER, "smtp_pass": SMTP_PW,
                  "mail_from_email": "hvadmin@dev.firestrike.de", "mail_from_name": "Hengstverzeichnis Test",
                  "admin_notification_email": "hvadmin@dev.firestrike.de"})
            shot(page, "mail-konfiguriert", "SMTP-Einstellungen konfiguriert", "/admin/mail-settings")
            try:
                if page.query_selector('form[action*="/admin/mail-settings/test"] [name="test_email"]'):
                    page.fill('form[action*="/admin/mail-settings/test"] [name="test_email"]', "hvadmin@dev.firestrike.de")
            except Exception: pass
            click_submit(page, "/admin/mail-settings/test", "SMTP-Test per echtem Button-Klick", "mail-test", expect="erfolgreich versendet")

            # Backup: S3 (MinIO)
            post(page, "/admin/backups",
                 {"csrf_token": c, "backup_enabled": "1", "backup_target": "s3",
                  "backup_s3_endpoint": MINIO_ENDPOINT, "backup_s3_region": "us-east-1",
                  "backup_s3_bucket": "hengst-backups", "backup_s3_access_key": MINIO_KEY,
                  "backup_s3_secret_key": MINIO_SECRET, "backup_s3_path_style": "1",
                  "backup_s3_use_https": "0", "backup_interval_hours": "24", "backup_retention_count": "14"})
            shot(page, "backup-s3-konfiguriert", "Backup-Ziel S3 (MinIO) konfiguriert", "/admin/backups")
            click_submit(page, "/admin/backups/test", "S3-Backup-Lauf per echtem Button-Klick", "backup-s3-test", expect="erfolgreich manuell ausgeführt")

            # Backup: WebDAV
            post(page, "/admin/backups",
                 {"csrf_token": c, "backup_enabled": "1", "backup_target": "webdav",
                  "backup_webdav_url": WEBDAV_URL, "backup_webdav_user": WEBDAV_USER,
                  "backup_webdav_pass": WEBDAV_PW, "backup_interval_hours": "24", "backup_retention_count": "14"})
            shot(page, "backup-webdav-konfiguriert", "Backup-Ziel WebDAV konfiguriert", "/admin/backups")
            click_submit(page, "/admin/backups/test", "WebDAV-Backup-Lauf per echtem Button-Klick", "backup-webdav-test", expect="erfolgreich manuell ausgeführt")

            # Backup: FTPS (nativer Lauf: Host-Port; Docker-Image ohne FTP-SSL, siehe Issue #157)
            post(page, "/admin/backups",
                 {"csrf_token": c, "backup_enabled": "1", "backup_target": "ftps",
                  "backup_ftps_host": FTPS_HOST, "backup_ftps_port": FTPS_PORT, "backup_ftps_user": FTPS_USER,
                  "backup_ftps_pass": FTPS_PW, "backup_ftps_path": "/",
                  "backup_interval_hours": "24", "backup_retention_count": "14"})
            shot(page, "backup-ftps-konfiguriert", "Backup-Ziel FTPS konfiguriert", "/admin/backups")
            click_submit(page, "/admin/backups/test", "FTPS-Backup-Lauf per echtem Button-Klick", "backup-ftps-test", expect="erfolgreich manuell ausgeführt")

            # Digest
            post(page, "/admin/digest", {"csrf_token": c, "digest_enabled": "1", "digest_interval_hours": "24"})
            st, _ = post(page, "/admin/digest/test", {"csrf_token": c})
            shot(page, "digest-test", "E-Mail-Digest ausgeführt", "/admin/digest")

            # Cron
            post(page, "/admin/cron/regenerate-secret", {"csrf_token": c})
            shot(page, "cron-secret", "Cron-Secret erzeugt", "/admin/cron")
            page.goto(BASE + "/admin/cron", wait_until="domcontentloaded", timeout=30000)
            click_submit(page, "/admin/cron/run-now", "Cron-Aufgaben per echtem Button-Klick ausgeführt", "cron-run")

            # Roundcube-Beweis (falls URL gesetzt) — nur Login-Seite, Postfach separat
            if ROUNDCUBE_URL:
                shot(page, "roundcube", "Webmail (Beweis für Mailversand)", None)
                try:
                    page.goto(ROUNDCUBE_URL, wait_until="domcontentloaded", timeout=30000)
                    page.screenshot(path=os.path.join(OUT, f"{counter[0]:03d}-roundcube.png"), full_page=True, timeout=15000)
                except Exception as e:
                    log(f"  Roundcube: {e}")

        # ---- Phase: Update -------------------------------------------------
        def phase_update():
            c = csrf["v"]
            # Pflicht-Backup vor dem Update braucht ein FUNKTIONIERENDES Ziel -> S3 (MinIO);
            # zuletzt stand es (aus phase_extern) auf ftps, das im Docker-Image nicht geht.
            post(page, "/admin/backups",
                 {"csrf_token": c, "backup_enabled": "1", "backup_target": "s3",
                  "backup_s3_endpoint": MINIO_ENDPOINT, "backup_s3_region": "us-east-1",
                  "backup_s3_bucket": "hengst-backups", "backup_s3_access_key": MINIO_KEY,
                  "backup_s3_secret_key": MINIO_SECRET, "backup_s3_path_style": "1",
                  "backup_s3_use_https": "0", "backup_interval_hours": "24", "backup_retention_count": "14"})
            # confirm()-Dialog des Update-Buttons automatisch bestätigen
            page.on("dialog", lambda d: d.accept())
            page.goto(BASE + "/admin/updates", wait_until="domcontentloaded", timeout=30000)
            try:
                page.select_option('[name="update_channel"]', 'beta')
            except Exception: pass
            # Kanal setzen UND prüfen (loest die GitHub-Abfrage aus; erst dann erscheint
            # der Install-Button, wenn ein neueres Release vorliegt).
            click_submit(page, "/admin/updates/channel",
                         "Update-Prüfung im Beta-Kanal (löst GitHub-Release-Abfrage aus)", "update-angebot")
            # Zustandsabhängig: Ist ein neueres Release verfügbar, erscheint der
            # Install-Button (/admin/updates/run) und wird echt geklickt. Läuft die
            # Instanz bereits auf der neuesten Version (Server-Lauf B, aus dem Release
            # deployt), gibt es keinen Button - "aktuell" ist dann der grüne Normalfall.
            run_btn = page.query_selector('form[action*="/admin/updates/run"] button')
            if run_btn:
                click_submit(page, "/admin/updates/run",
                             "Update per echtem Klick angewendet (Pflicht-Backup + Release-Zip)", "update-run")
            else:
                counter[0] += 1; n = f"{counter[0]:03d}"; fn = f"{n}-update-run.png"
                content = page.content()
                aktuell = ("ist aktuell" in content) or ("neueste" in content)
                page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
                st = ("ok · bereits neueste Version, kein Update nötig" if aktuell
                      else "FEHLER: weder Update-Button noch Aktuell-Meldung")
                index.append((n, fn, "Update-Status", "Update-Prüfung: Installation ist bereits aktuell", st))
                log(f"{n} update-run: {st}")
            page.wait_for_timeout(6000)
            shot(page, "update-ergebnis", "Update-Verwaltung nach Update-Lauf", "/admin/updates")

        # ---- Phase: SSO ----------------------------------------------------
        def phase_sso():
            # Login-Seite mit SSO-Button
            shot(page, "sso-loginseite", f"Login-Seite mit SSO-Button ({OIDC_LABEL})", "/login")
            # Kompletter Login-Flow in frischem Kontext
            sctx = browser.new_context(viewport={"width": 1440, "height": 1024}, ignore_https_errors=IGNORE_TLS)
            sp = sctx.new_page()
            sp.set_default_timeout(25000)
            btnre = re.compile("log ?in|continue|weiter|anmelden|sign in", re.I)
            try:
                sp.goto(BASE + "/login", wait_until="domcontentloaded", timeout=30000)
                sp.click('a[href="/auth/entra"]')
                # Authentik Identification-Stage (Web Component / Shadow DOM -> Playwright piercet)
                sp.wait_for_selector('input[name="uidField"]', state="visible", timeout=30000)
                counter[0]+=1; n=f"{counter[0]:03d}"
                sp.screenshot(path=os.path.join(OUT, f"{n}-sso-provider-login.png"), full_page=True, timeout=15000)
                index.append((n, f"{n}-sso-provider-login.png", "/auth/entra", f"SSO: Anmeldeseite bei {OIDC_LABEL}", "ok"))
                log(f"  SSO: Provider-Login sichtbar auf {sp.url}")
                # visible=true: Authentik hat pro Flow-Stage gleichnamige, teils versteckte Felder
                sp.locator('input[name="uidField"] >> visible=true').fill(os.environ.get("SSO_USER", ADMIN_EMAIL))
                sp.get_by_role("button", name=btnre).first.click()
                # Password-Stage
                sp.wait_for_selector('input[name="password"]', state="visible", timeout=25000)
                sp.wait_for_timeout(800)
                sp.locator('input[name="password"] >> visible=true').fill(os.environ.get("SSO_PW", ""))
                sp.get_by_role("button", name=btnre).first.click()
                # zurück in der App (Authentik leitet nach erfolgreichem Login zum Callback)
                sp.wait_for_url(re.compile(r"/admin|sso=entra"), timeout=30000)
                counter[0]+=1; n=f"{counter[0]:03d}"
                sp.screenshot(path=os.path.join(OUT, f"{n}-sso-nach-login.png"), full_page=True, timeout=15000)
                ok = "/admin" in sp.url or "sso=entra" in sp.url
                index.append((n, f"{n}-sso-nach-login.png", sp.url, f"SSO: nach {OIDC_LABEL}-Login als App-Admin angemeldet",
                              "angemeldet ✓" if ok else "unbestätigt"))
                log(f"  SSO: nach Login auf {sp.url} (angemeldet={ok})")
            except Exception as e:
                log(f"  SSO-Flow Fehler: {e}")
                try:
                    counter[0]+=1; n=f"{counter[0]:03d}"
                    sp.screenshot(path=os.path.join(OUT, f"{n}-sso-zwischenstand.png"), full_page=True, timeout=10000)
                    index.append((n, f"{n}-sso-zwischenstand.png", sp.url, f"SSO: Zwischenstand ({str(e)[:50]})", "FEHLER"))
                except Exception: pass
            finally:
                sctx.close()

        # ---- Phase: Dark-Mode-Kontrast -------------------------------------
        def phase_darkmode():
            # Dark-Mode erzwingen: das Head-Script der App liest localStorage
            # 'theme' und setzt data-theme. Einmal setzen wirkt auf Folgeseiten;
            # zusätzlich pro Seite data-theme hart setzen (falls JS-Timing).
            # Läuft als LETZTE Phase, damit die vorherigen Screenshots hell sind.
            try:
                page.goto(BASE + "/", wait_until="load", timeout=30000)
                page.evaluate("() => localStorage.setItem('theme','dark')")
            except Exception as e:
                log(f"  Dark-Mode aktivieren: {e}")
            hid = ids.get("haupt")
            seiten = [
                ("dark-start", "/"), ("dark-katalog", "/katalog"),
                ("dark-hengst-detail", f"/hengst?id={hid}" if hid else "/katalog"),
                ("dark-login", "/login"),
                ("dark-admin-dashboard", "/admin"), ("dark-admin-logs", "/admin/logs"),
                ("dark-admin-personen", "/admin/persons"), ("dark-admin-gdpr", "/admin/gdpr"),
                ("dark-api-keys", "/api-keys"), ("dark-admin-system", "/admin/system-settings"),
                ("dark-admin-trash", "/admin/trash"), ("dark-admin-backups", "/admin/backups"),
                ("dark-admin-digest", "/admin/digest"), ("dark-admin-mail", "/admin/mail-settings"),
                ("dark-admin-import", "/admin/import-horses"), ("dark-admin-matches", "/admin/matches"),
                ("dark-admin-updates", "/admin/updates"),
            ]
            for slug, url in seiten:
                counter[0] += 1; n = f"{counter[0]:03d}"; fn = f"{n}-{slug}.png"; status = "ok"
                funde = []
                try:
                    page.goto(BASE + url, wait_until="load", timeout=30000)
                    page.evaluate("() => document.documentElement.setAttribute('data-theme','dark')")
                    page.wait_for_timeout(500)
                    page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
                    funde = page.evaluate(_DARK_AUDIT)
                except Exception as e:
                    status = f"FEHLER: {type(e).__name__}: {str(e)[:100]}"
                    try: page.screenshot(path=os.path.join(OUT, fn), full_page=True, timeout=15000)
                    except Exception: fn = "(kein Screenshot)"
                dark_audit.append((slug, len(funde), funde[:8]))
                if funde and not status.startswith("FEHLER"):
                    bsp = funde[0]
                    status = (f"FEHLER: {len(funde)} Kontrast-Fund(e) <3.0 im Dark-Mode "
                              f"(z. B. '{bsp['text']}' ratio {bsp['ratio']})")
                index.append((n, fn, url, f"Dark-Mode: {slug}", status))
                log(f"{n} {slug}: {status}")

        # ---- Ablauf --------------------------------------------------------
        phases = [
            ("setup", phase_setup, True),
            ("leer", phase_leer, False),
            ("store", phase_store, False),
            ("daten", phase_daten, False),
            ("ansichten", phase_ansichten, False),
            ("gast", phase_gast, False),
            ("filter", phase_filter, False),
            ("api", phase_api, False),
            ("extern", phase_extern, False),
            ("sso", phase_sso, False),
            ("update", phase_update, False),
            ("darkmode", phase_darkmode, False),
        ]
        for name, fn, always in phases:
            if not (always or phase_enabled(name)):
                continue
            log(f"=== Phase: {name} ===")
            try:
                fn()
            except Exception as e:
                log(f"  Phase {name} ABBRUCH: {e}")
                log(traceback.format_exc()[:800])

        browser.close()

    # INDEX + Log schreiben
    with open(os.path.join(OUT, "INDEX.md"), "w") as f:
        f.write(f"# Testlauf-Screenshots — {LABEL}\n\n")
        f.write(f"Basis-URL: `{BASE}`  ·  erzeugt: {time.strftime('%Y-%m-%d %H:%M')}\n\n")
        f.write("| # | Datei | Seite/URL | Was gezeigt wird | Status |\n|---|---|---|---|---|\n")
        for n, fn, url, desc, status in index:
            f.write(f"| {n} | `{fn}` | `{url}` | {desc} | {status} |\n")
    with open(os.path.join(OUT, "klickbarkeit.md"), "w") as f:
        f.write("# Klickbarkeits-Audit\n\n")
        f.write("Je Seite: klickbare (sichtbar UND enabled) / gesamte Bedienelemente "
                "(button, submit, .btn, role=button).\n\n")
        f.write("| Seite | klickbar/gesamt | Auffälligkeiten |\n|---|---|---|\n")
        for slug, total, clickable, probs in click_audit:
            f.write(f"| {slug} | {clickable}/{total} | {probs or '—'} |\n")
    with open(os.path.join(OUT, "darkmode.md"), "w") as f:
        f.write("# Dark-Mode-Kontrast-Audit\n\n")
        f.write("Je Seite im Dark-Mode: Anzahl Textstellen mit WCAG-Kontrast < 3.0 "
                "gegen die effektive Hintergrundfläche (heller Text auf heller "
                "Fläche o. ä. = unlesbar). 0 = sauber.\n\n")
        f.write("| Seite | Funde < 3.0 | Beispiele (Text · ratio · Farbe auf Fläche) |\n|---|---|---|\n")
        for slug, anzahl, funde in dark_audit:
            bsp = "; ".join(f"'{x['text']}' r{x['ratio']} {x['color']}→{x['bg']}" for x in funde[:3]) or "—"
            f.write(f"| {slug} | {anzahl} | {bsp} |\n")
    with open(os.path.join(OUT, "lauf.log"), "w") as f:
        f.write("\n".join(log_lines))
    fails = [i for i in index if str(i[4]).startswith("FEHLER")]
    log(f"FERTIG: {len(index)} Screenshots, {len(fails)} mit FEHLER-Status, INDEX.md geschrieben.")
    return index

_index = run()

# Exit-Code für das Nachtlauf-Gate: 0 = grün, 1 = mindestens ein Problem.
# Die Unterscheidung "echter App-Fehler" vs. "GitHub-Rate-Limit (403)" trifft
# der aufrufende run.sh anhand von lauf.log - hier wird nur signalisiert, DASS
# etwas nicht grün war (FEHLER-Status oder abweichendes/unvollständiges Ergebnis).
import sys as _sys
_probleme = [i for i in (_index or []) if str(i[4]).startswith("FEHLER")
             or "abweichend" in str(i[4]).lower()
             or "unvollständig" in str(i[4]).lower()]
if _probleme:
    print(f"E2E-PROBLEME: {len(_probleme)}", flush=True)
    for p in _probleme:
        print(f"  {p[0]} {p[1]}: {p[4]}", flush=True)
    _sys.exit(1)
print("E2E-OK: keine Probleme", flush=True)
_sys.exit(0)
