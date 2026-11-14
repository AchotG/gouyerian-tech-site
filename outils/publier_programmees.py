#!/usr/bin/env python3
"""Publie les actualités programmées dont l'heure est arrivée, SANS Claude (lancé par GitHub Actions).

attente/programmees.json : tableau d'actualités déjà vérifiées par la recherche du dimanche, chacune avec
  "prevu": "AAAA-MM-JJTHH:MM" (heure de Paris) en plus des champs du site (d, dl, sc, th, tag, urg?, t, s, tip, src).
Celles dont "prevu" est passé sont insérées en tête de NEWS (pub = prevu), NEWS_UPD est mis à jour,
le tableau est limité à 70 actualités, puis elles sont retirées du fichier.
Code de sortie : 0 = rien à faire, 10 = au moins une actualité publiée."""
import json, re, sys
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

RACINE = Path(__file__).resolve().parent.parent
SRC = RACINE / "source" / "gouyerian-tech.html"
FILE = RACINE / "attente" / "programmees.json"
MAX = 70
MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"]
CLES = ["d", "pub", "dl", "sc", "th", "tag", "urg", "t", "s", "tip", "src"]

def js(n):
    """Une actualité au format du site : clés sans guillemets, textes en JSON (valide en JavaScript)."""
    return "{" + ",".join(f"{k}:{json.dumps(n[k], ensure_ascii=False)}" for k in CLES if k in n) + "}"

def main(maintenant=None):
    now = maintenant or datetime.now(ZoneInfo("Europe/Paris"))
    cle = now.strftime("%Y-%m-%dT%H:%M")
    file = json.loads(FILE.read_text("utf-8")) if FILE.exists() else []
    dues = sorted([x for x in file if str(x.get("prevu", "9999")) <= cle], key=lambda x: x["prevu"])
    if not dues:
        print("Rien à publier (" + str(len(file)) + " en attente).")
        return 0
    s = SRC.read_text("utf-8")
    deb = s.index("const NEWS=[") + len("const NEWS=[")
    fin = s.index("\n];", deb)
    bloc = s[deb:fin]
    # ordre de publication : la plus ancienne d'abord, de sorte que la plus récente finisse en tête
    nouvelles = []
    for x in sorted(dues, key=lambda x: x["prevu"]):
        n = {k: v for k, v in x.items() if k in CLES and k != "pub"}
        n["pub"] = x["prevu"]
        nouvelles.insert(0, js(n) + ",")
        print("Publiée :", x["t"])
    entrees = re.split(r"\n(?=\{d:\")", "\n".join(nouvelles) + bloc)
    entrees = [e.strip() for e in entrees if e.strip()]
    if len(entrees) > MAX:
        print(f"Limite de {MAX} : {len(entrees) - MAX} ancienne(s) retirée(s).")
        entrees = entrees[:MAX]
    bloc2 = "\n" + "\n".join(entrees)
    if not bloc2.rstrip().endswith(","):
        bloc2 = bloc2.rstrip() + ","
    s = s[:deb] + bloc2 + s[fin:]
    h = f"{now.day} {MOIS[now.month - 1]} {now.year} à {now.hour}h{now.minute:02d}"
    s = re.sub(r'const NEWS_UPD="[^"]*";', f'const NEWS_UPD="{h}";', s, count=1)
    SRC.write_text(s, "utf-8")
    reste = [x for x in file if x not in dues]
    FILE.write_text(json.dumps(reste, ensure_ascii=False, indent=1) + "\n", "utf-8")
    print(f"{len(dues)} publiée(s), {len(reste)} encore programmée(s).")
    return 10

if __name__ == "__main__":
    sys.exit(main())
