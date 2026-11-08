"""Illustrations originales du site (non contractuelles) : dessinées en SVG, exportées en WebP 640 et 1280 px
pour l'affichage, et en JPEG 1200 x 630 pour le partage des articles sur les réseaux sociaux.
Sortie : build/img/ill/  (copiée par build.py dans assets/img/)."""
import io, math, os, pathlib
from PIL import Image
from playwright.sync_api import sync_playwright

B = pathlib.Path(__file__).parent
OUT = B / "img" / "ill"
OUT.mkdir(parents=True, exist_ok=True)
SRC = B / "img" / "ill-src"
SRC.mkdir(parents=True, exist_ok=True)

W, H = 1280, 720
INK, CY, BL, GR, AM, RD, BODY = "#e8f0ff", "#22d3ee", "#60a5fa", "#34d399", "#fbbf24", "#f87171", "#0d1d44"


def frame(inner, gx=640, gy=360):
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">
<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0a1530"/><stop offset=".6" stop-color="#11306b"/><stop offset="1" stop-color="#0b5068"/></linearGradient>
<radialGradient id="gl"><stop offset="0" stop-color="#3b82f6" stop-opacity=".38"/><stop offset="1" stop-color="#3b82f6" stop-opacity="0"/></radialGradient>
<pattern id="gr" width="48" height="48" patternUnits="userSpaceOnUse"><path d="M48 0H0V48" fill="none" stroke="#fff" stroke-opacity=".055" stroke-width="1"/></pattern>
<linearGradient id="ac" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3b82f6"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>
<rect width="{W}" height="{H}" fill="url(#bg)"/><rect width="{W}" height="{H}" fill="url(#gr)"/>
<circle cx="{gx}" cy="{gy}" r="380" fill="url(#gl)"/>
<g fill="none" stroke="{INK}" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" font-family="Arial,Helvetica,sans-serif">{inner}</g></svg>'''


# ---------------------------------------------------------------- éléments
def phone(cx, cy, w=190, h=380, rot=0, extra="", screen=None):
    x, y = cx - w / 2, cy - h / 2
    sc = f'<rect x="{x+16}" y="{y+34}" width="{w-32}" height="{h-68}" rx="12" fill="{screen}" stroke="none"/>' if screen else ""
    return (f'<g transform="rotate({rot} {cx} {cy})"><rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{w*.17:.0f}" fill="{BODY}"/>{sc}'
            f'<rect x="{x+16}" y="{y+34}" width="{w-32}" height="{h-68}" rx="12" stroke-width="3" stroke-opacity=".45"/>'
            f'<path d="M{cx-24} {y+18}h48" stroke-width="5"/>{extra}</g>')


def tablet(cx, cy, w=420, h=300, extra=""):
    x, y = cx - w / 2, cy - h / 2
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="26" fill="{BODY}"/>'
            f'<rect x="{x+24}" y="{y+24}" width="{w-48}" height="{h-48}" rx="10" stroke-width="3" stroke-opacity=".45"/>'
            f'<circle cx="{x+w-12}" cy="{cy}" r="3" fill="{INK}" stroke="none"/>{extra}')


def laptop(cx, cy, w=460, extra=""):
    h = w * .62
    x, y = cx - w / 2, cy - h / 2
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="16" fill="{BODY}"/>'
            f'<rect x="{x+18}" y="{y+18}" width="{w-36}" height="{h-36}" rx="6" stroke-width="3" stroke-opacity=".45"/>'
            f'<path d="M{x-44} {y+h+10}h{w+88}l-26 30H{x-18}z" fill="{BODY}"/><path d="M{cx-40} {y+h+10}h80" stroke-width="4"/>{extra}')


def monitor(cx, cy, w=440, h=280, extra=""):
    x, y = cx - w / 2, cy - h / 2
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="16" fill="{BODY}"/>'
            f'<rect x="{x+18}" y="{y+18}" width="{w-36}" height="{h-36}" rx="6" stroke-width="3" stroke-opacity=".45"/>'
            f'<path d="M{cx} {y+h}v44M{cx-80} {y+h+46}h160"/>{extra}')


def shield(cx, cy, s=1.0, fill="url(#ac)", stroke=INK):
    return (f'<path transform="translate({cx} {cy}) scale({s})" d="M0 -110 L90 -76 V0 C90 62 46 100 0 118 C-46 100 -90 62 -90 0 V-76 Z" fill="{fill}" stroke="{stroke}"/>')


def check(cx, cy, r=42, col=GR):
    return f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{col}" stroke="none"/><path d="M{cx-r*.45} {cy+r*.02}l{r*.32} {r*.32} {r*.58} -{r*.66}" stroke="#062a1c" stroke-width="{max(5, r*.16):.0f}"/>'


def cross(cx, cy, r=40, col=RD):
    d = r * .4
    return f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{col}" stroke="none"/><path d="M{cx-d} {cy-d}L{cx+d} {cy+d}M{cx+d} {cy-d}L{cx-d} {cy+d}" stroke="#3a0b0b" stroke-width="7"/>'


def wifi(cx, cy, r=110, col=CY, n=3, w=7):
    out = f'<circle cx="{cx}" cy="{cy}" r="{w*1.3}" fill="{col}" stroke="none"/>'
    for i in range(1, n + 1):
        rr = r * i / n
        a = math.radians(45)
        out += f'<path d="M{cx-rr*math.sin(a):.1f} {cy-rr*math.cos(a):.1f}A{rr:.1f} {rr:.1f} 0 0 1 {cx+rr*math.sin(a):.1f} {cy-rr*math.cos(a):.1f}" stroke="{col}" stroke-width="{w}" stroke-opacity="{1-.18*(i-1):.2f}"/>'
    return out


def cloud(cx, cy, s=1.0, fill="none", col=INK):
    return (f'<path transform="translate({cx} {cy}) scale({s})" d="M-120 50 H110 A70 70 0 0 0 96 -88 A100 100 0 0 0 -92 -60 A72 72 0 0 0 -120 50 Z" fill="{fill}" stroke="{col}"/>')


def battery(cx, cy, w=240, h=120, lvl=.8, col=GR):
    x, y = cx - w / 2, cy - h / 2
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="18" fill="{BODY}"/><rect x="{x+w}" y="{cy-h*.2}" width="16" height="{h*.4}" rx="5" fill="{INK}" stroke="none"/>'
            f'<rect x="{x+14}" y="{y+14}" width="{(w-28)*lvl:.0f}" height="{h-28}" rx="8" fill="{col}" stroke="none"/>')


def bolt(cx, cy, s=1.0, col=AM):
    return f'<path transform="translate({cx} {cy}) scale({s})" d="M10 -60 L-34 8 H-2 L-12 60 L34 -10 H2 Z" fill="{col}" stroke="none"/>'


def drop(cx, cy, s=1.0, col=CY, op=1):
    return f'<path transform="translate({cx} {cy}) scale({s})" d="M0 -40 C18 -14 30 0 30 16 A30 30 0 0 1 -30 16 C-30 0 -18 -14 0 -40 Z" fill="{col}" fill-opacity="{op}" stroke="none"/>'


def building(x, y, w, h, roof=False):
    out = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{BODY}"/>'
    if roof:
        out += f'<path d="M{x-16} {y+4}L{x+w/2} {y-70}L{x+w+16} {y+4}" fill="{BODY}"/>'
    cols, rows = 3, max(2, int(h // 70))
    for r in range(rows - 1):
        for c in range(cols):
            out += f'<rect x="{x+18+c*(w-36)/cols+6:.0f}" y="{y+24+r*70}" width="{(w-36)/cols-12:.0f}" height="34" rx="4" stroke-width="3" stroke-opacity=".55"/>'
    out += f'<rect x="{x+w/2-24}" y="{y+h-70}" width="48" height="70" rx="4" stroke-width="4"/>'
    return out


def bubble(x, y, w, h, col=INK, fill=BODY):
    return f'<path d="M{x} {y+18}a18 18 0 0 1 18 -18h{w-36}a18 18 0 0 1 18 18v{h-36}a18 18 0 0 1 -18 18H{x+46}l-28 26v-26h0a18 18 0 0 1 -18 -18z" fill="{fill}" stroke="{col}"/>'


def gear(cx, cy, r=46, col=INK):
    pts = []
    for i in range(16):
        a = i * math.pi / 8
        rr = r if i % 2 == 0 else r * .78
        pts.append(f"{cx+rr*math.cos(a):.1f},{cy+rr*math.sin(a):.1f}")
    return f'<polygon points="{" ".join(pts)}" fill="{BODY}" stroke="{col}"/><circle cx="{cx}" cy="{cy}" r="{r*.32:.0f}" stroke="{col}"/>'


def wrench(cx, cy, s=1.0, rot=-45, col=INK):
    return (f'<g transform="translate({cx} {cy}) rotate({rot}) scale({s})"><path d="M-14 -40 V90 a14 14 0 0 0 28 0 V-40" fill="{BODY}" stroke="{col}"/>'
            f'<path d="M-40 -64 a42 42 0 1 0 80 0 l-16 -14 v26 l-24 10 -24 -10 v-26 z" fill="{BODY}" stroke="{col}"/></g>')


def label(x, y, t, size=34, col=INK, anchor="middle", weight=700):
    return f'<text x="{x}" y="{y}" font-size="{size}" font-weight="{weight}" fill="{col}" stroke="none" text-anchor="{anchor}">{t}</text>'


def sparkles(pts, col=CY):
    return "".join(f'<path d="M{x} {y-14}v28M{x-14} {y}h28" stroke="{col}" stroke-width="4"/>' for x, y in pts)


# ---------------------------------------------------------------- éléments ajoutés (nov. 2026)
# Tous suivent le même style : trait INK de 6 px, corps BODY, accents CY / GR / AM / RD.
def arrow(x1, y1, x2, y2, col=CY, w=8, head=22):
    """Flèche droite de (x1, y1) vers (x2, y2)."""
    a = math.atan2(y2 - y1, x2 - x1)
    p1 = (x2 - head * math.cos(a - .5), y2 - head * math.sin(a - .5))
    p2 = (x2 - head * math.cos(a + .5), y2 - head * math.sin(a + .5))
    return (f'<path d="M{x1} {y1}L{x2} {y2}" stroke="{col}" stroke-width="{w}"/>'
            f'<path d="M{p1[0]:.1f} {p1[1]:.1f}L{x2} {y2}L{p2[0]:.1f} {p2[1]:.1f}" stroke="{col}" stroke-width="{w}"/>')


def link(x1, y1, x2, y2, bend=-60, col=CY, w=6):
    """Liaison courbe en pointillés (transfert, connexion sans fil, synchronisation)."""
    mx, my = (x1 + x2) / 2, (y1 + y2) / 2 + bend
    return f'<path d="M{x1} {y1}Q{mx} {my} {x2} {y2}" stroke="{col}" stroke-width="{w}" stroke-dasharray="6 16"/>'


def calendar(cx, cy, w=300, h=290, big="2027", small="OCT.", col=CY):
    x, y = cx - w / 2, cy - h / 2
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="22" fill="{BODY}"/><path d="M{x} {y+70}h{w}"/>'
            f'<path d="M{x+60} {y-30}v54M{x+w-60} {y-30}v54"/>'
            + label(cx, y + h * .66, big, min(92, w * .3), INK) + label(cx, y + h * .86, small, 34, col))


def padlock(cx, cy, s=1.0, open_=False, fill="url(#ac)"):
    arc = "M-46 -20v-40a46 46 0 0 1 92 0" + ("v-20" if open_ else "v40")
    return (f'<g transform="translate({cx} {cy}) scale({s})"><path d="{arc}" stroke-width="12"/>'
            f'<rect x="-76" y="-20" width="152" height="130" rx="18" fill="{fill}"/><circle cy="34" r="14" fill="#071226" stroke="none"/>'
            f'<path d="M0 40v30" stroke="#071226" stroke-width="10"/></g>')


def envelope(cx, cy, w=300, h=200, col=INK, fill=BODY):
    x, y = cx - w / 2, cy - h / 2
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="16" fill="{fill}" stroke="{col}"/>'
            f'<path d="M{x+14} {y+16}L{cx} {cy+10}L{x+w-14} {y+16}" stroke="{col}"/>')


def bankcard(cx, cy, w=300, rot=0, fill="url(#ac)"):
    h = w * .63
    x, y = cx - w / 2, cy - h / 2
    return (f'<g transform="rotate({rot} {cx} {cy})"><rect x="{x}" y="{y}" width="{w}" height="{h}" rx="18" fill="{fill}"/>'
            f'<rect x="{x+3}" y="{y+h*.2:.0f}" width="{w-6}" height="{h*.17:.0f}" fill="#071226" stroke="none"/>'
            f'<rect x="{x+w*.1}" y="{y+h*.55}" width="{w*.18}" height="{h*.2}" rx="6" fill="{AM}" stroke="none"/>'
            f'<path d="M{x+w*.5} {y+h*.7}h{w*.36}" stroke="#071226" stroke-width="6"/></g>')


def gift(cx, cy, s=1.0, col=AM):
    return (f'<g transform="translate({cx} {cy}) scale({s})"><rect x="-110" y="-30" width="220" height="150" rx="12" fill="{BODY}"/>'
            f'<rect x="-128" y="-74" width="256" height="50" rx="10" fill="{BODY}"/><path d="M0 -74v194" stroke="{col}" stroke-width="16"/>'
            f'<path d="M0 -76c-30 -60 -90 -40 -60 -4zM0 -76c30 -60 90 -40 60 -4z" stroke="{col}" stroke-width="8"/></g>')


def cart(cx, cy, s=1.0, col=INK):
    return (f'<g transform="translate({cx} {cy}) scale({s})"><path d="M-130 -80h40l34 130h150l30 -100h-196" fill="{BODY}" stroke="{col}"/>'
            f'<circle cx="-36" cy="90" r="16" fill="{BODY}" stroke="{col}"/><circle cx="76" cy="90" r="16" fill="{BODY}" stroke="{col}"/></g>')


def pricetag(cx, cy, text="-30 %", s=1.0, col=AM, rot=-14):
    return (f'<g transform="translate({cx} {cy}) rotate({rot}) scale({s})"><path d="M-110 -50h170l50 50 -50 50h-170z" fill="{col}" stroke="none"/>'
            f'<circle cx="60" r="9" fill="#2a1a00" stroke="none"/>' + label(-28, 13, text, 38, "#2a1a00") + '</g>')


def magnifier(cx, cy, r=70, col=INK, fill=BODY):
    return (f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{fill}" stroke="{col}" stroke-width="10"/>'
            f'<path d="M{cx+r*.72:.0f} {cy+r*.72:.0f}l{r*.75:.0f} {r*.75:.0f}" stroke="{col}" stroke-width="18"/>')


def warning(cx, cy, s=1.0, col=AM):
    return (f'<g transform="translate({cx} {cy}) scale({s})"><path d="M-100 85L0 -85L100 85Z" fill="{col}" stroke="{col}"/>'
            f'<path d="M0 -25v50" stroke="#2a1a00" stroke-width="12"/><circle cy="55" r="8" fill="#2a1a00" stroke="none"/></g>')


def snowflake(cx, cy, r=60, col=CY, w=6):
    out = ""
    for i in range(6):
        a = i * math.pi / 3
        ex, ey = cx + r * math.cos(a), cy + r * math.sin(a)
        out += f'<path d="M{cx} {cy}L{ex:.1f} {ey:.1f}" stroke="{col}" stroke-width="{w}"/>'
        for sgn in (-1, 1):
            bx, by = cx + r * .6 * math.cos(a), cy + r * .6 * math.sin(a)
            tx, ty = bx + r * .3 * math.cos(a + sgn * .8), by + r * .3 * math.sin(a + sgn * .8)
            out += f'<path d="M{bx:.1f} {by:.1f}L{tx:.1f} {ty:.1f}" stroke="{col}" stroke-width="{w}"/>'
    return out


def thermometer(cx, cy, s=1.0, lvl=.7, col=RD):
    top = 30 - 140 * lvl
    return (f'<g transform="translate({cx} {cy}) scale({s})"><path d="M-22 -110a22 22 0 0 1 44 0v140a46 46 0 1 1 -44 0z" fill="{BODY}"/>'
            f'<circle r="30" cy="70" fill="{col}" stroke="none"/><path d="M0 70V{top:.0f}" stroke="{col}" stroke-width="12"/></g>')


def chip(cx, cy, s=1.0, text="IA", fill="url(#ac)"):
    legs = "".join(f'<path d="M{v} -96v-26M{v} 96v26M-96 {v}h-26M96 {v}h26" stroke-width="8"/>' for v in (-50, 0, 50))
    return (f'<g transform="translate({cx} {cy}) scale({s})">{legs}<rect x="-96" y="-96" width="192" height="192" rx="22" fill="{BODY}"/>'
            f'<rect x="-62" y="-62" width="124" height="124" rx="12" fill="{fill}" stroke="none"/>' + label(0, 20, text, 58, "#071226") + '</g>')


def router(cx, cy, w=320, col=CY):
    x, y = cx - w / 2, cy - 45
    leds = "".join(f'<circle cx="{x+50+i*36}" cy="{cy}" r="7" fill="{c}" stroke="none"/>' for i, c in enumerate((GR, GR, CY, AM)))
    return (f'<path d="M{x+50} {y}l-24 -100M{x+w-50} {y}l24 -100" stroke-width="10"/>'
            f'<rect x="{x}" y="{y}" width="{w}" height="90" rx="22" fill="{BODY}"/>{leds}<path d="M{x+w-80} {cy}h44" stroke-width="5" stroke-opacity=".6"/>')


def gamepad(cx, cy, s=1.0, col=INK):
    """Manette générique (aucune marque)."""
    return (f'<g transform="translate({cx} {cy}) scale({s})"><path d="M-82 -70h164a52 52 0 0 1 50 64l-18 64a30 30 0 0 1 -52 10l-32 -40h-110l-32 40a30 30 0 0 1 -52 -10l-18 -64a52 52 0 0 1 50 -64z" fill="{BODY}" stroke="{col}"/>'
            f'<path d="M-64 -30v34M-81 -13h34" stroke-width="7"/><circle cx="58" cy="-25" r="8" fill="{CY}" stroke="none"/><circle cx="80" cy="-4" r="8" fill="{AM}" stroke="none"/></g>')


def headphones(cx, cy, s=1.0, col=INK):
    return (f'<g transform="translate({cx} {cy}) scale({s})"><path d="M-100 30v-30a100 100 0 0 1 200 0v30" stroke="{col}" stroke-width="12"/>'
            f'<rect x="-124" y="10" width="54" height="100" rx="20" fill="url(#ac)" stroke="{col}"/><rect x="70" y="10" width="54" height="100" rx="20" fill="url(#ac)" stroke="{col}"/></g>')


def usbkey(cx, cy, s=1.0, rot=0):
    return (f'<g transform="translate({cx} {cy}) rotate({rot}) scale({s})"><rect x="-40" y="-90" width="80" height="150" rx="16" fill="url(#ac)"/>'
            f'<rect x="-26" y="60" width="52" height="50" rx="4" fill="{BODY}"/><path d="M-10 76h6M6 76h6" stroke-width="6"/></g>')


def clock(cx, cy, r=70, h=10, m=10, col=INK, fill=BODY):
    ah, am = math.radians(h % 12 * 30 + m / 2 - 90), math.radians(m * 6 - 90)
    return (f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{fill}" stroke="{col}"/>'
            f'<path d="M{cx} {cy}L{cx+r*.5*math.cos(ah):.1f} {cy+r*.5*math.sin(ah):.1f}" stroke="{col}" stroke-width="9"/>'
            f'<path d="M{cx} {cy}L{cx+r*.75*math.cos(am):.1f} {cy+r*.75*math.sin(am):.1f}" stroke="{CY}" stroke-width="7"/><circle cx="{cx}" cy="{cy}" r="7" fill="{col}" stroke="none"/>')


def hourglass(cx, cy, s=1.0, col=AM):
    return (f'<g transform="translate({cx} {cy}) scale({s})"><path d="M-50 -70h100M-50 70h100M-40 -70c0 50 80 50 80 70s-80 20 -80 70M40 -70c0 50 -80 50 -80 70s80 20 80 70" fill="{BODY}" stroke="{col}"/>'
            f'<path d="M-20 50h40l-20 -18z" fill="{col}" stroke="none"/></g>')


def house(cx, cy, w=260, h=200, fill=BODY):
    x, y = cx - w / 2, cy - h / 2 + 40
    return (f'<path d="M{x-24} {y+6}L{cx} {y-90}L{x+w+24} {y+6}" fill="{fill}"/><rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{fill}"/>'
            f'<rect x="{cx-26}" y="{y+h-80}" width="52" height="80" rx="4" stroke-width="4"/>')


def screenlines(x, y, n=3, w=160, gap=40):
    """Lignes de texte sur un écran."""
    return "".join(f'<path d="M{x} {y+i*gap}h{w*(1, .75, .88, .6)[i % 4]:.0f}" stroke-width="5" stroke-opacity=".6"/>' for i in range(n))


def badge(cx, cy, text, r=72, col=GR, ink="#062a1c", sub=""):
    """Pastille ronde avec un mot court (« 2 ANS », « 80 % »…)."""
    out = f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{col}" stroke="none"/>' + label(cx, cy + (-4 if sub else 11), text, r * .42, ink)
    return out + (label(cx, cy + r * .48, sub, r * .3, ink, weight=600) if sub else "")


# ---------------------------------------------------------------- scènes
S = {}
crack = f'<path d="M560 214l40 46 -22 30 44 36 -10 40M600 260l52 -14M622 326l-40 22" stroke="{INK}" stroke-width="4" stroke-opacity=".9"/>'
S["pro-virus"] = frame(
    monitor(560, 320, 520, 320, shield(560, 320, 1.0) + f'<path d="M520 320l30 30 56 -64" stroke="#071226" stroke-width="12"/>')
    + f'<g transform="translate(960 220)"><ellipse rx="44" ry="56" fill="{BODY}" stroke="{RD}"/><path d="M-44 -10h-30M44 -10h30M-40 26l-28 18M40 26l28 18M-30 -44l-20 -24M30 -44l20 -24M0 -56v112" stroke="{RD}"/></g>'
    + f'<path d="M900 160L1020 280" stroke="{INK}" stroke-width="10"/>' + sparkles([(250, 180), (980, 520), (300, 540)]), 560, 340)
S["pro-reseau"] = frame(
    building(150, 300, 300, 330, roof=True) + building(830, 250, 300, 380)
    + f'<path d="M450 560 C560 600 720 600 830 560" stroke="{CY}" stroke-width="8"/><path d="M450 560 C560 600 720 600 830 560" stroke="#071226" stroke-width="3" stroke-dasharray="2 22"/>'
    + wifi(300, 250, 100) + wifi(980, 200, 100)
    + f'<path d="M420 170 C560 70 720 70 860 130" stroke="{CY}" stroke-width="5" stroke-dasharray="6 16"/>'
    + f'<rect x="590" y="520" width="100" height="40" rx="8" fill="{BODY}" stroke="{CY}"/><path d="M610 540h12M634 540h12M658 540h12" stroke="{CY}" stroke-width="5"/>', 640, 360)
S["pro-depannage"] = frame(
    f'<rect x="230" y="300" width="300" height="160" rx="18" fill="{BODY}"/><path d="M280 300v-90h200v90" fill="{BODY}"/><path d="M280 460v80h200v-80" fill="{BODY}"/><path d="M310 500h140M310 520h100" stroke-width="4" stroke-opacity=".6"/><circle cx="490" cy="340" r="8" fill="{GR}" stroke="none"/>'
    + laptop(820, 330, 400, f'<path d="M700 300h100M700 340h160M700 380h120" stroke-width="5" stroke-opacity=".6"/>')
    + wrench(640, 210, .75, 35, CY) + gear(1030, 520, 44, CY) + check(1030, 180, 42), 640, 360)
S["windows-10-que-faire"] = frame(
    monitor(480, 320, 480, 300, f'<g transform="translate(370 290)"><path d="M-44 -10a46 46 0 0 1 84 -16" stroke="{CY}" stroke-width="9"/><path d="M44 10a46 46 0 0 1 -84 16" stroke="{CY}" stroke-width="9"/><path d="M40 -26l4 -26M40 -26l-26 -2M-40 26l-4 26M-40 26l26 2" stroke="{CY}" stroke-width="9"/></g><path d="M470 250h160M470 290h120M470 330h140" stroke-width="5" stroke-opacity=".6"/>')
    + f'<rect x="820" y="170" width="300" height="290" rx="22" fill="{BODY}"/><path d="M820 240h300" /><path d="M880 140v54M1060 140v54"/>'
    + label(970, 360, "2027", 92, INK) + label(970, 420, "OCT.", 34, CY) + shield(1100, 500, .55), 640, 340)
S["telephone-tombe-dans-l-eau"] = frame(
    phone(640, 300, 200, 380, -14, screen="#14306a")
    + "".join(drop(x, y, s, CY, o) for x, y, s, o in ((420, 180, 1.1, 1), (860, 150, .8, .8), (900, 330, 1.2, 1), (380, 380, .7, .7), (760, 110, .6, .7)))
    + f'<path d="M120 580q60 -40 120 0t120 0 120 0 120 0 120 0 120 0 120 0 120 0 120 0" stroke="{CY}" stroke-width="7"/><path d="M180 640q60 -40 120 0t120 0 120 0 120 0 120 0 120 0 120 0 120 0" stroke="{CY}" stroke-width="6" stroke-opacity=".5"/>'
    + f'<circle cx="1040" cy="460" r="54" fill="{AM}" stroke="none"/><path d="M1022 438a28 28 0 1 0 36 0" stroke="#2a1a00" stroke-width="8"/><path d="M1040 424v34" stroke="#2a1a00" stroke-width="8"/>', 640, 320)
S["arnaques-en-ligne"] = frame(
    # faux message reçu sur le téléphone, hameçon qui « pêche » la carte bancaire, alerte
    phone(470, 370, 230, 440, -4, screen="#14306a", extra=bubble(378, 236, 190, 104, AM) + f'<path d="M402 272h130M402 302h84" stroke="{AM}" stroke-width="6"/>'
          + screenlines(392, 400, 3, 150, 34))
    + f'<path d="M850 40v170" stroke-width="5"/><path d="M850 210c0 46 -58 52 -66 14" stroke-width="9"/><path d="M784 224l-14 -20M784 224l22 -8" stroke-width="8"/>'
    + bankcard(860, 330, 240, 12)
    + warning(1080, 190, .62) + check(1070, 520, 46) + link(600, 300, 740, 330, -40, RD), 640, 360)
S["ordinateur-lent"] = frame(
    laptop(520, 320, 480, f'<path d="M330 260h180M330 300h140M330 340h160" stroke-width="5" stroke-opacity=".5"/>')
    + f'<g transform="translate(640 300)"><path d="M-50 -70h100M-50 70h100M-40 -70c0 50 80 50 80 70s-80 20 -80 70M40 -70c0 50 -80 50 -80 70s80 20 80 70" fill="{BODY}" stroke="{AM}"/><path d="M-20 50h40l-20 -18z" fill="{AM}" stroke="none"/></g>'
    + f'<rect x="900" y="250" width="200" height="120" rx="14" fill="url(#ac)" stroke="{INK}"/>' + label(1000, 326, "SSD", 46, "#071226")
    + f'<path d="M790 310h80" stroke="{CY}" stroke-width="8"/><path d="M850 290l24 20 -24 20" stroke="{CY}" stroke-width="8"/>'
    + gear(990, 520, 48, CY), 640, 340)
S["batterie-smartphone"] = frame(
    # batterie ménagée : ni trop chaud, ni trop froid, charge limitée à 80 %
    phone(430, 360, 220, 430, 0, screen="#14306a", extra=battery(430, 330, 130, 60, .8) + bolt(430, 440, .55, AM))
    + thermometer(760, 330, .95, .85) + cross(830, 190, 34)
    + snowflake(1010, 220, 62) + cross(1076, 160, 30)
    + badge(1010, 500, "80 %", 82, GR, sub="charge"), 640, 360)
S["sauvegarder-ses-photos"] = frame(
    f'<rect x="170" y="240" width="300" height="230" rx="16" fill="{BODY}"/><path d="M200 440l80 -90 60 60 40 -40 60 70" stroke="{CY}"/><circle cx="410" cy="300" r="22" fill="{AM}" stroke="none"/>'
    + cloud(780, 220, .95, "url(#ac)")
    + f'<rect x="890" y="430" width="200" height="130" rx="18" fill="{BODY}"/><circle cx="1050" cy="530" r="8" fill="{GR}" stroke="none"/><path d="M920 470h110" stroke-width="4" stroke-opacity=".6"/>'
    + f'<path d="M480 330C560 280 620 260 670 250" stroke="{CY}" stroke-width="6" stroke-dasharray="6 16"/><path d="M480 400C640 480 760 500 880 495" stroke="{CY}" stroke-width="6" stroke-dasharray="6 16"/>'
    + label(780, 240, "3-2-1", 64, "#071226"), 640, 360)
S["reconditionne-ou-neuf"] = frame(
    phone(430, 360, 200, 400, -6, screen="#14306a") + phone(830, 360, 200, 400, 6, screen="url(#ac)")
    + f'<g transform="translate(630 170)" stroke="{GR}" stroke-width="9"><path d="M-60 0a60 60 0 0 1 104 -40"/><path d="M60 0a60 60 0 0 1 -104 40"/><path d="M44 -40l4 -30M44 -40l-28 -6M-44 40l-4 30M-44 40l28 6"/></g>'
    + f'<circle cx="980" cy="560" r="72" fill="{GR}" stroke="none"/>' + label(980, 556, "2 ANS", 30, "#062a1c") + label(980, 590, "garantie", 22, "#062a1c", weight=600), 640, 360)


# Images des catégories de réparation (cat-*) : rendus réalistes en 3D dessinés par le moteur ART du site
# (même code que les visuels de la boutique, entre /*ART-DEBUT*/ et /*ART-FIN*/ dans source/gouyerian-tech.html).
CATS = ("cat-telephones", "cat-tablettes", "cat-ordinateurs", "cat-consoles", "cat-assistance")


def art_js():
    src = (B.parent / "source" / "gouyerian-tech.html").read_text(encoding="utf-8")
    a, b = src.index("/*ART-DEBUT*/"), src.index("/*ART-FIN*/")
    return src[a:b] + ";window.ART=ART;"


def render_cats(pg, only=()):
    """Rendus des catégories : WebP 640 et 1280 px (pas d'image de partage)."""
    fonts = "".join(f"@font-face{{font-family:{n};src:url(data:font/woff2;base64,{__import__('base64').b64encode((B / 'fonts' / f).read_bytes()).decode()})}}"
                    for n, f in (("Archivo", "archivo-latin-wdth-normal.woff2"), ("'IBM Plex Mono'", "ibm-plex-mono-latin-500-normal.woff2")))
    pg.set_content(f"<html><head><style>{fonts}</style></head><body style='margin:0'><script>{art_js()}</script></body></html>")
    cats = pg.evaluate("() => ART.cats()")
    faits = []
    for name in CATS:
        if only and name not in only:
            continue
        pg.evaluate("(s) => { document.body.innerHTML = s }", cats[name])
        pg.wait_for_timeout(150)
        (SRC / f"{name}.svg").write_text(cats[name], encoding="utf-8")
        im = Image.open(io.BytesIO(pg.screenshot(clip={"x": 0, "y": 0, "width": W, "height": H}))).convert("RGB")
        for w in (640, 1280):
            (im if w == W else im.resize((w, w * H // W), Image.LANCZOS)).save(OUT / f"{name}-{w}.webp", "WEBP", quality=82, method=6)
        faits.append(name)
    return faits


MARGE = 28          # marge minimale (px) au bord de l'image ; l'aperçu de partage coupe aussi ~22 px en haut et en bas
CONTROLE_JS = """(m) => {
  const W = 1280, H = 720, g = document.querySelector('svg').lastElementChild, pb = [];
  const r0 = document.querySelector('svg').getBoundingClientRect();
  let L = 1e9, T = 1e9, R = -1e9, B = -1e9;
  const txt = [];
  for (const e of g.querySelectorAll('*')) {
    if (['g', 'defs', 'stop', 'tspan'].includes(e.tagName)) continue;
    const b = e.getBoundingClientRect(); if (!b.width && !b.height) continue;
    const sw = parseFloat(getComputedStyle(e).strokeWidth) || 0, st = getComputedStyle(e).stroke !== 'none' ? sw / 2 : 0;
    const x1 = b.left - r0.left - st, y1 = b.top - r0.top - st, x2 = b.right - r0.left + st, y2 = b.bottom - r0.top + st;
    L = Math.min(L, x1); T = Math.min(T, y1); R = Math.max(R, x2); B = Math.max(B, y2);
    const nom = e.tagName === 'text' ? 'texte « ' + e.textContent + ' »' : e.tagName;
    if (x1 < m || y1 < m || x2 > W - m || y2 > H - m) {
      if (x2 < 0 || y2 < 0 || x1 > W || y1 > H) pb.push(nom + ' entièrement hors de l\\'image');
      else if (e.tagName === 'text' || x1 < 0 || y1 < 0 || x2 > W || y2 > H) pb.push(nom + ' coupé ou collé au bord (' + [x1, y1, x2, y2].map(Math.round).join(', ') + ')');
    }
    if (e.tagName === 'text') { txt.push([nom, x1, y1, x2, y2]); if (b.height < 18) pb.push(nom + ' trop petit pour être lu en vignette'); }
  }
  for (let i = 0; i < txt.length; i++) for (let j = i + 1; j < txt.length; j++) {
    const a = txt[i], c = txt[j];
    if (a[1] < c[3] && c[1] < a[3] && a[2] < c[4] && c[2] < a[4]) pb.push(a[0] + ' chevauche ' + c[0]);
  }
  if (R - L < W * .4) pb.push('dessin trop petit : il occupe ' + Math.round((R - L) / W * 100) + ' % de la largeur (viser 55 à 80 %)');
  const dx = (L + R) / 2 - W / 2, dy = (T + B) / 2 - H / 2;
  if (Math.abs(dx) > 170 || Math.abs(dy) > 110) pb.push('dessin décentré (' + Math.round(dx) + ' px, ' + Math.round(dy) + ' px)');
  return pb;
}"""


def render(only=(), check_only=False):
    """only : noms à dessiner (ex. python outils/gen_illus.py mon-article) ; vide = toutes les illustrations.
    Contrôle chaque dessin (débordements, textes qui se chevauchent ou trop petits, dessin trop petit ou décentré)
    et renvoie {nom: [problèmes]}. check_only=True : contrôle sans rien écrire."""
    pbs = {}
    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=os.environ.get("GT_CHROME") or None)
        pg = b.new_page(viewport={"width": W, "height": H})
        for name, svg in S.items():
            if only and name not in only:
                continue
            pg.set_content(f"<html><body style='margin:0'>{svg}</body></html>")
            pg.wait_for_timeout(60)
            pbs[name] = pg.evaluate(CONTROLE_JS, MARGE)
            if check_only:
                continue
            (SRC / f"{name}.svg").write_text(svg, encoding="utf-8")
            im = Image.open(io.BytesIO(pg.screenshot(clip={"x": 0, "y": 0, "width": W, "height": H}))).convert("RGB")
            for w in (640, 1280):
                (im if w == W else im.resize((w, w * H // W), Image.LANCZOS)).save(OUT / f"{name}-{w}.webp", "WEBP", quality=78, method=6)
            if not name.startswith("pro-"):
                og = im.resize((1200, 675), Image.LANCZOS).crop((0, 22, 1200, 652))
                og.save(OUT / f"og-{name}.jpg", "JPEG", quality=84, optimize=True, progressive=True)
        if not check_only:
            render_cats(pg, only)
        b.close()
    return pbs


def planche(noms, dest):
    """Planche de contrôle : chaque illustration en grand, puis en vignette (taille d'une carte sur téléphone)
    et dans le cadrage du partage sur les réseaux sociaux, pour vérifier qu'elle reste lisible partout."""
    from PIL import ImageDraw, ImageFont
    try:
        font = ImageFont.truetype("DejaVuSans.ttf", 15)
    except OSError:
        try:
            font = ImageFont.load_default(size=15)
        except TypeError:
            font = ImageFont.load_default()
    rows = []
    for n in noms:
        big = Image.open(OUT / f"{n}-640.webp").convert("RGB")
        small = big.resize((240, 135), Image.LANCZOS)
        ogf = OUT / f"og-{n}.jpg"
        og = Image.open(ogf).convert("RGB").resize((320, 168), Image.LANCZOS) if ogf.exists() else None
        rows.append((n, big, small, og))
    sheet = Image.new("RGB", (640 + 360 + 40, len(rows) * 400 + 20), "#ffffff")
    d = ImageDraw.Draw(sheet)
    for i, (n, big, small, og) in enumerate(rows):
        y = 20 + i * 400
        d.text((10, y), n, fill="#000000", font=font)
        sheet.paste(big, (10, y + 20))
        d.text((670, y), "vignette (carte sur téléphone)", fill="#000000", font=font)
        sheet.paste(small, (670, y + 20))
        if og:
            d.text((670, y + 166), "partage réseaux sociaux", fill="#000000", font=font)
            sheet.paste(og, (670, y + 188))
    sheet.save(dest)
    return dest


ELEMENTS = ["phone", "tablet", "laptop", "monitor", "shield", "check", "cross", "wifi", "cloud", "battery", "bolt", "drop", "building",
            "bubble", "gear", "wrench", "sparkles", "arrow", "link", "calendar", "padlock", "envelope", "bankcard", "gift", "cart",
            "pricetag", "magnifier", "warning", "snowflake", "thermometer", "chip", "router", "gamepad", "headphones", "usbkey",
            "clock", "hourglass", "house", "screenlines", "badge", "label"]


def catalogue(dest):
    """Image de tous les éléments disponibles, avec leur nom, pour choisir quoi dessiner."""
    ex = {"phone": phone(0, 0, 120, 230, screen="#14306a"), "tablet": tablet(0, 0, 230, 160), "laptop": laptop(0, -10, 220),
          "monitor": monitor(0, -20, 220, 150), "shield": shield(0, 0, .8), "check": check(0, 0, 50), "cross": cross(0, 0, 50),
          "wifi": wifi(0, 40, 110), "cloud": cloud(0, 0, .8, "url(#ac)"), "battery": battery(0, 0, 200, 100, .7), "bolt": bolt(0, 0, 1.2),
          "drop": drop(0, 0, 1.8), "building": building(-90, -80, 180, 190), "bubble": bubble(-110, -60, 220, 110, AM),
          "gear": gear(0, 0, 70, CY), "wrench": wrench(0, -20, .8), "sparkles": sparkles([(-60, -40), (40, 30), (70, -60)]),
          "arrow": arrow(-100, 0, 100, 0), "link": link(-110, 40, 110, 40), "calendar": calendar(0, 10, 220, 210),
          "padlock": padlock(0, -10, .9), "envelope": envelope(0, 0, 240, 160), "bankcard": bankcard(0, 0, 240, -8),
          "gift": gift(0, 0, .85), "cart": cart(0, -10, .9), "pricetag": pricetag(0, 0, "-30 %"), "magnifier": magnifier(-20, -20, 60),
          "warning": warning(0, 0, .9), "snowflake": snowflake(0, 0, 80), "thermometer": thermometer(0, -10, .9),
          "chip": chip(0, 0, .8), "router": router(0, 40, 260), "gamepad": gamepad(0, 0, 1.0), "headphones": headphones(0, -20, .9),
          "usbkey": usbkey(0, -10, .9, 20), "clock": clock(0, 0, 80), "hourglass": hourglass(0, 0, 1.1), "house": house(0, 0, 200, 140),
          "screenlines": screenlines(-80, -40), "badge": badge(0, 0, "2 ANS", 80, sub="garantie"), "label": label(0, 14, "Texte", 46)}
    cw, ch, cols = 320, 300, 6
    n = len(ELEMENTS)
    rows = -(-n // cols)
    cells = "".join(f'<g transform="translate({(i % cols) * cw + cw / 2} {(i // cols) * ch + 140})">{ex[k]}</g>'
                    f'<text x="{(i % cols) * cw + cw / 2}" y="{(i // cols) * ch + 285}" font-size="26" fill="{AM}" stroke="none" text-anchor="middle">{k}</text>'
                    for i, k in enumerate(ELEMENTS))
    svg = frame(cells).replace(f'viewBox="0 0 {W} {H}" width="{W}" height="{H}"', f'viewBox="0 0 {cols*cw} {rows*ch}" width="{cols*cw}" height="{rows*ch}"')
    svg = svg.replace(f'<rect width="{W}" height="{H}"', f'<rect width="{cols*cw}" height="{rows*ch}"')
    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=os.environ.get("GT_CHROME") or None)
        pg = b.new_page(viewport={"width": cols * cw, "height": rows * ch})
        pg.set_content(f"<html><body style='margin:0'>{svg}</body></html>")
        pg.wait_for_timeout(80)
        pg.screenshot(path=str(dest))
        b.close()
    return dest


AIDE = """Utilisation :
  python outils/gen_illus.py                  dessine toutes les illustrations
  python outils/gen_illus.py nom [nom…]       dessine seulement celles-ci (ex. un nouvel article)
  python outils/gen_illus.py --controle [nom…] contrôle sans rien écrire (débordements, textes, centrage)
  python outils/gen_illus.py --planche nom…   dessine puis crée outils/img/ill-src/planche.png (grand, vignette, partage)
  python outils/gen_illus.py --catalogue      crée outils/img/ill-src/catalogue.png : tous les éléments disponibles
  python outils/gen_illus.py --liste          liste les illustrations
Sortie : code 1 si un contrôle signale un problème (l'image est quand même écrite, à corriger)."""


if __name__ == "__main__":
    import sys
    args = sys.argv[1:]
    opts = {a for a in args if a.startswith("--")}
    noms = [a for a in args if not a.startswith("--")]
    if opts - {"--controle", "--planche", "--catalogue", "--liste", "--aide"} or "--aide" in opts:
        sys.exit(AIDE)
    if "--liste" in opts:
        print("\n".join(list(S) + list(CATS)))
        sys.exit(0)
    if "--catalogue" in opts:
        print("catalogue :", catalogue(SRC / "catalogue.png"))
        sys.exit(0)
    manque = [n for n in noms if n not in S and n not in CATS]
    if manque:
        sys.exit("Illustration inconnue : " + ", ".join(manque) + "\n" + AIDE)
    pbs = render(tuple(noms), check_only="--controle" in opts)
    if "--controle" not in opts:
        for n in (noms or list(S) + list(CATS)):
            for f in sorted(OUT.glob(f"*{n}*")):
                if f.stem in (f"{n}-640", f"{n}-1280", f"og-{n}"):
                    print(f.name, f.stat().st_size // 1024, "Ko")
    if "--planche" in opts:
        print("planche :", planche(noms or list(S), SRC / "planche.png"))
    mauvais = {n: v for n, v in pbs.items() if v}
    for n, v in mauvais.items():
        for x in v:
            print(f"À CORRIGER – {n} : {x}")
    print("contrôle :", "aucun problème" if not mauvais else f"{len(mauvais)} illustration(s) à corriger")
    sys.exit(1 if mauvais else 0)
