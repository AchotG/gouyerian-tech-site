"""Images d'en-tête des e-mails (logo + « GOUYERIAN TECH » en blanc sur fond transparent).
Le texte est en image car le mode sombre de Gmail sur téléphone change la couleur des textes blancs et les rendait illisibles.
Usage : python outils/mail_entete.py  → outils/img/mail-entete.png et mail-entete-atelier.png (à 2x, affichées en 320 × 48)."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
D = Path(__file__).parent / "img"
F_BOLD = "/usr/share/fonts/opentype/inter/Inter-Bold.otf"
F_REG = "/usr/share/fonts/opentype/inter/Inter-Medium.otf"
def faire(sous, nom):
    S = 2; W, H = 320 * S, 48 * S
    im = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ic = Image.open(D / "icon-192.png").convert("RGBA").resize((44 * S, 44 * S), Image.LANCZOS)
    m = Image.new("L", ic.size, 0); ImageDraw.Draw(m).rounded_rectangle((0, 0, ic.size[0] - 1, ic.size[1] - 1), radius=11 * S, fill=255)
    im.paste(ic, (0, 2 * S), m)
    d = ImageDraw.Draw(im)
    fb = ImageFont.truetype(F_BOLD, 17 * S); fr = ImageFont.truetype(F_REG, 12 * S)
    x = 58 * S; y = 6 * S
    for ch in "GOUYERIAN TECH":                       # espacement des lettres comme sur le site
        d.text((x, y), ch, font=fb, fill=(255, 255, 255, 255)); x += d.textlength(ch, font=fb) + 2.4 * S
    d.text((58 * S, 28 * S), sous, font=fr, fill=(159, 180, 216, 255))
    im.save(D / nom, optimize=True)
faire("Service Client", "mail-entete.png")
faire("Espace atelier", "mail-entete-atelier.png")
print("ok")
