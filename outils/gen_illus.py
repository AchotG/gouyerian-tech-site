"""Illustrations originales du site (non contractuelles) : dessinées en SVG, exportées en WebP 640 et 1280 px
pour l'affichage, et en JPEG 1200 x 630 pour le partage des articles sur les réseaux sociaux.
Sortie : build/img/ill/  (copiée par build.py dans assets/img/)."""
import io, math, pathlib
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


# ---------------------------------------------------------------- scènes
S = {}
crack = f'<path d="M560 214l40 46 -22 30 44 36 -10 40M600 260l52 -14M622 326l-40 22" stroke="{INK}" stroke-width="4" stroke-opacity=".9"/>'
S["cat-telephones"] = frame(
    phone(400, 360, 210, 410, -6, screen="#14306a", extra=f'<path d="M352 248l40 46 -22 30 44 36 -10 40M392 294l52 -14M414 360l-40 22" stroke="{INK}" stroke-width="4"/>')
    + f'<path d="M560 360h150" stroke="{CY}" stroke-width="8"/><path d="M690 330l30 30 -30 30" stroke="{CY}" stroke-width="8"/>'
    + phone(880, 360, 210, 410, 6, screen="url(#ac)") + check(990, 190, 46) + wrench(560, 210, .7, -30, CY), 640, 360)
S["cat-tablettes"] = frame(
    tablet(600, 370, 520, 360, f'<rect x="364" y="214" width="472" height="312" rx="10" fill="url(#ac)" fill-opacity=".35" stroke="none"/>')
    + f'<path d="M880 520l60 -230" stroke="{CY}" stroke-width="10"/><path d="M880 520l-6 26 18 -16" fill="{CY}" stroke="{CY}"/>'
    + battery(540, 370, 200, 96, .9) + bolt(540, 370, .7, "#062a1c") + check(860, 210, 40), 600, 370)
S["cat-ordinateurs"] = frame(
    laptop(600, 330, 520, f'<path d="M420 300 a180 180 0 0 1 360 0" stroke="{CY}" stroke-width="10" stroke-opacity=".35"/><path d="M420 300a180 180 0 0 1 300 -110" stroke="{CY}" stroke-width="10"/><path d="M600 300l70 -96" stroke="{AM}" stroke-width="8"/><circle cx="600" cy="300" r="12" fill="{AM}" stroke="none"/>')
    + gear(960, 210, 58, CY) + shield(250, 230, .55), 600, 340)
S["cat-assistance"] = frame(
    f'<path d="M380 330L620 150L860 330" stroke-width="8"/><rect x="420" y="320" width="400" height="260" rx="8" fill="{BODY}"/>'
    + laptop(560, 470, 170) + f'<rect x="680" y="500" width="110" height="34" rx="8" fill="{BODY}" stroke="{CY}"/><circle cx="700" cy="517" r="4" fill="{CY}" stroke="none"/><circle cx="716" cy="517" r="4" fill="{CY}" stroke="none"/>'
    + wifi(735, 480, 90) + f'<path d="M1010 250c0 70 -70 130 -70 130s-70 -60 -70 -130a70 70 0 0 1 140 0z" fill="url(#ac)" stroke="{INK}"/><circle cx="940" cy="250" r="24" fill="{BODY}"/>'
    + f'<path d="M940 380c-80 60 -150 60 -190 40" stroke="{CY}" stroke-dasharray="4 18" stroke-width="7"/>', 620, 380)
S["cat-consoles"] = frame(
    # console portable générique : écran au centre, deux manettes détachables
    f'<rect x="330" y="200" width="130" height="250" rx="58" fill="{BODY}" stroke="{CY}"/><rect x="740" y="200" width="130" height="250" rx="58" fill="{BODY}" stroke="{BL}"/>'
    + f'<rect x="440" y="200" width="320" height="250" rx="14" fill="{BODY}"/><rect x="462" y="222" width="276" height="206" rx="8" fill="url(#ac)" fill-opacity=".38" stroke="none"/>'
    + f'<circle cx="395" cy="265" r="24" fill="#14306a"/><circle cx="805" cy="375" r="24" fill="#14306a"/>'
    + f'<path d="M395 352v40M375 372h40" stroke-width="8"/>'
    + "".join(f'<circle cx="{x}" cy="{y}" r="9" fill="{INK}" stroke="none"/>' for x, y in ((805, 250), (785, 270), (825, 270), (805, 290)))
    + f'<rect x="580" y="458" width="40" height="12" rx="6" fill="{INK}" stroke="none"/>' + bolt(600, 540, .7, AM)
    # prise HDMI et son câble
    + f'<path d="M170 520h110l-14 40h-82z" fill="{BODY}" stroke="{CY}"/><path d="M196 532h58" stroke="{CY}" stroke-width="5"/><path d="M225 560v40c0 40 60 50 110 30" stroke="{CY}" stroke-width="8"/>'
    + label(225, 500, "HDMI", 30, CY)
    # manette
    + f'<path d="M928 500h164a52 52 0 0 1 50 64l-18 64a30 30 0 0 1 -52 10l-32 -40h-110l-32 40a30 30 0 0 1 -52 -10l-18 -64a52 52 0 0 1 50 -64z" fill="{BODY}"/>'
    + f'<path d="M946 540v34M929 557h34" stroke-width="7"/><circle cx="1068" cy="545" r="8" fill="{CY}" stroke="none"/><circle cx="1090" cy="566" r="8" fill="{AM}" stroke="none"/>'
    + wrench(1000, 250, .7, 40, CY) + check(1110, 170, 42), 600, 360)
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
    phone(470, 370, 230, 440, 0, screen="#14306a", extra=bubble(385, 230, 210, 110, AM) + f'<path d="M410 270h140M410 300h90" stroke="{AM}" stroke-width="6"/>')
    + f'<path d="M820 90v240" stroke-width="5"/><path d="M820 330c0 60 -80 60 -80 0v-20" stroke="{INK}" stroke-width="10"/><path d="M740 300l-16 24 30 -4" fill="{INK}"/>'
    + f'<path d="M900 470L1000 300L1100 470Z" fill="{AM}" stroke="{AM}"/><path d="M1000 360v50" stroke="#2a1a00" stroke-width="12"/><circle cx="1000" cy="438" r="8" fill="#2a1a00" stroke="none"/>'
    + f'<path d="M640 470h150" stroke="{RD}" stroke-width="6" stroke-dasharray="4 16"/>', 640, 360)
S["ordinateur-lent"] = frame(
    laptop(520, 320, 480, f'<path d="M330 260h180M330 300h140M330 340h160" stroke-width="5" stroke-opacity=".5"/>')
    + f'<g transform="translate(640 300)"><path d="M-50 -70h100M-50 70h100M-40 -70c0 50 80 50 80 70s-80 20 -80 70M40 -70c0 50 -80 50 -80 70s80 20 80 70" fill="{BODY}" stroke="{AM}"/><path d="M-20 50h40l-20 -18z" fill="{AM}" stroke="none"/></g>'
    + f'<rect x="900" y="250" width="200" height="120" rx="14" fill="url(#ac)" stroke="{INK}"/>' + label(1000, 326, "SSD", 46, "#071226")
    + f'<path d="M790 310h80" stroke="{CY}" stroke-width="8"/><path d="M850 290l24 20 -24 20" stroke="{CY}" stroke-width="8"/>'
    + gear(990, 520, 48, CY), 640, 340)
S["batterie-smartphone"] = frame(
    phone(460, 360, 210, 420, 0, screen="#14306a", extra=battery(460, 340, 120, 56, .8) + label(460, 450, "80 %", 40, INK))
    + f'<g transform="translate(820 300)"><path d="M-22 -110a22 22 0 0 1 44 0v140a46 46 0 1 1 -44 0z" fill="{BODY}"/><circle r="30" cy="70" fill="{RD}" stroke="none"/><path d="M0 70v-130" stroke="{RD}" stroke-width="12"/></g>'
    + cross(890, 180, 34) + f'<path d="M980 470a90 90 0 1 1 160 0" stroke="{GR}" stroke-width="10"/><path d="M1060 470l-36 -60" stroke="{GR}" stroke-width="8"/>' + bolt(1060, 540, .7, AM), 620, 360)
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


def render():
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_page(viewport={"width": W, "height": H})
        for name, svg in S.items():
            (SRC / f"{name}.svg").write_text(svg, encoding="utf-8")
            pg.set_content(f"<html><body style='margin:0'>{svg}</body></html>")
            pg.wait_for_timeout(60)
            im = Image.open(io.BytesIO(pg.screenshot(clip={"x": 0, "y": 0, "width": W, "height": H}))).convert("RGB")
            for w in (640, 1280):
                (im if w == W else im.resize((w, w * H // W), Image.LANCZOS)).save(OUT / f"{name}-{w}.webp", "WEBP", quality=78, method=6)
            if not name.startswith(("cat-", "pro-")):
                og = im.resize((1200, 675), Image.LANCZOS).crop((0, 22, 1200, 652))
                og.save(OUT / f"og-{name}.jpg", "JPEG", quality=84, optimize=True, progressive=True)
        b.close()


if __name__ == "__main__":
    render()
    tot = sum(f.stat().st_size for f in OUT.iterdir())
    for f in sorted(OUT.iterdir()):
        print(f.name, f.stat().st_size // 1024, "Ko")
    print("total", tot // 1024, "Ko")
