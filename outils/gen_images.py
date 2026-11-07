"""Génère les icônes du site, le logo carré (données structurées) et l'image de partage (1200 x 630)."""
import base64, io, pathlib
from PIL import Image
from playwright.sync_api import sync_playwright

B = pathlib.Path(__file__).parent
OUT = B / "img"
OUT.mkdir(exist_ok=True)
FONTS = (B / "fonts").as_uri()
LOGO = "data:image/png;base64," + ((B / "donnees" / "logo.b64").read_text().strip())

FACES = f"""
@font-face{{font-family:Archivo;font-weight:100 900;font-stretch:62% 125%;src:url({FONTS}/archivo-latin-wdth-normal.woff2) format("woff2")}}
@font-face{{font-family:Cinzel;font-weight:700;src:url({FONTS}/cinzel-latin-700-normal.woff2) format("woff2")}}
@font-face{{font-family:Cinzel;font-weight:600;src:url({FONTS}/cinzel-latin-600-normal.woff2) format("woff2")}}
@font-face{{font-family:"IBM Plex Sans";font-weight:400;src:url({FONTS}/ibm-plex-sans-latin-400-normal.woff2) format("woff2")}}
@font-face{{font-family:"IBM Plex Sans";font-weight:600;src:url({FONTS}/ibm-plex-sans-latin-600-normal.woff2) format("woff2")}}
@font-face{{font-family:"IBM Plex Mono";font-weight:500;src:url({FONTS}/ibm-plex-mono-latin-500-normal.woff2) format("woff2")}}
*{{box-sizing:border-box;margin:0}}
"""
NAVY = "linear-gradient(120deg,#0a1530 0%,#11306b 58%,#0b5068 100%)"

ICON = f"""<style>{FACES}
html,body{{width:512px;height:512px;background:transparent}}
.i{{width:512px;height:512px;border-radius:112px;background:{NAVY};display:grid;place-items:center;position:relative;overflow:hidden}}
.i::after{{content:"";position:absolute;left:96px;right:96px;bottom:118px;height:14px;border-radius:7px;background:linear-gradient(90deg,#2563eb,#06b6d4)}}
.t{{font:700 250px/1 Cinzel,serif;color:#fff;letter-spacing:-6px;transform:translateY(-22px)}}
</style><div class="i"><span class="t">GT</span></div>"""

ICON_FULL = ICON.replace("border-radius:112px", "border-radius:0")
# Icône « maskable » : le dessin tient dans la zone sûre (cercle de 80 %) que Android peut découper en rond ou en goutte
ICON_MASK = ICON_FULL.replace("left:96px;right:96px;bottom:118px", "left:150px;right:150px;bottom:156px").replace("font:700 250px/1", "font:700 196px/1").replace("translateY(-22px)", "translateY(-18px)")

SQUARE = f"""<style>{FACES}
html,body{{width:512px;height:512px;background:#fff}}
.s{{width:512px;height:512px;display:grid;place-items:center;align-content:center;gap:18px}}
img{{width:300px;filter:brightness(0)}}
.w{{font:700 46px/1 Cinzel,serif;letter-spacing:.12em;color:#0e0f11;text-align:center}}
.w small{{display:block;font-size:22px;letter-spacing:.9em;margin-top:10px;padding-left:.9em;color:#2563eb}}
</style><div class="s"><img src="{LOGO}"><div class="w">GOUYERIAN<small>TECH</small></div></div>"""

OG = f"""<style>{FACES}
html,body{{width:1200px;height:630px}}
.o{{width:1200px;height:630px;background:{NAVY};color:#fff;position:relative;overflow:hidden;padding:64px 72px;display:grid;grid-template-rows:auto 1fr auto}}
.o::before{{content:"";position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(255,255,255,.035) 0 1px,transparent 1px 48px),repeating-linear-gradient(0deg,rgba(255,255,255,.035) 0 1px,transparent 1px 48px)}}
.radar{{position:absolute;right:-150px;top:50%;width:720px;height:720px;transform:translateY(-50%)}}
.brand{{display:flex;align-items:center;gap:22px;position:relative}}
.brand img{{height:86px;filter:brightness(0) invert(1)}}
.w{{font:700 34px/1 Cinzel,serif;letter-spacing:.12em}}
.w small{{display:block;font-size:16px;letter-spacing:.9em;margin-top:8px;color:#67e8f9}}
.mid{{align-self:center;position:relative;max-width:720px}}
.eb{{font:500 20px/1 "IBM Plex Mono",monospace;letter-spacing:.16em;text-transform:uppercase;color:#67e8f9}}
h1{{font:800 66px/1.02 Archivo,sans-serif;font-stretch:112%;letter-spacing:-.015em;margin-top:22px}}
p{{font:400 27px/1.4 "IBM Plex Sans",sans-serif;color:#cbd5e1;margin-top:22px}}
.foot{{display:flex;gap:28px;align-items:center;position:relative;font:500 22px/1 "IBM Plex Mono",monospace;color:#e2e8f0}}
.foot b{{font-weight:500;padding:14px 22px;border-radius:999px;background:linear-gradient(120deg,#2563eb,#06b6d4);color:#fff}}
</style>
<div class="o">
<svg class="radar" viewBox="-50 -50 100 100" fill="none" stroke="rgba(103,232,249,.28)" stroke-width=".25">
<circle r="40" stroke="rgba(103,232,249,.55)" stroke-width=".4"/><circle r="30"/><circle r="20"/><circle r="10"/><path d="M-48 0H48M0 -48V48"/>
<path d="M0 0L40 0A40 40 0 0 0 28.28 -28.28Z" fill="rgba(34,211,238,.12)" stroke="none"/>
<circle r="1.6" fill="#67e8f9" stroke="none"/><circle r="3.2" stroke="#67e8f9" stroke-width=".35"/>
</svg>
<div class="brand"><img src="{LOGO}"><div class="w">GOUYERIAN<small>TECH</small></div></div>
<div class="mid"><div class="eb">Saint-Fort-sur-le-Né · près de Cognac</div><h1>Réparation et maintenance informatique</h1><p>Téléphones, tablettes, ordinateurs et accessoires. Devis gratuit, déplacement à domicile jusqu’à 40 km.</p></div>
<div class="foot"><b>06 05 79 48 94</b><span>gouyerian-tech.fr</span></div>
</div>"""


def shot(pg, html, w, h, path, transparent=False):
    pg.set_viewport_size({"width": w, "height": h})
    pg.set_content(html)
    pg.wait_for_timeout(250)
    pg.evaluate("document.fonts.ready")
    png = pg.screenshot(omit_background=transparent, clip={"x": 0, "y": 0, "width": w, "height": h})
    im = Image.open(io.BytesIO(png))
    im.load()
    return im


with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page()
    icon = shot(pg, ICON, 512, 512, None, transparent=True).convert("RGBA")
    full = shot(pg, ICON_FULL, 512, 512, None).convert("RGB")
    mask = shot(pg, ICON_MASK, 512, 512, None).convert("RGB")
    square = shot(pg, SQUARE, 512, 512, None).convert("RGB")
    og = shot(pg, OG, 1200, 630, None).convert("RGB")
    b.close()

icon.resize((192, 192), Image.LANCZOS).save(OUT / "icon-192.png", optimize=True)
icon.save(OUT / "icon-512.png", optimize=True)
mask.save(OUT / "icon-maskable-512.png", optimize=True)
icon.resize((32, 32), Image.LANCZOS).save(OUT / "favicon-32.png", optimize=True)
full.resize((180, 180), Image.LANCZOS).save(OUT / "apple-touch-icon.png", optimize=True)
icon.save(OUT / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])
square.save(OUT / "logo-carre.png", optimize=True)
og.save(OUT / "og-image.jpg", quality=86, optimize=True, progressive=True)
for f in sorted(OUT.iterdir()):
    print(f.name, f.stat().st_size)
