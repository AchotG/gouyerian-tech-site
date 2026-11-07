"""Découpe le site Gouyerian Tech (un seul fichier HTML) en vraies pages statiques, avec le référencement.

Source : source/gouyerian-tech.html
Sortie : _site/ (publié par GitHub Pages, voir .github/workflows/publier.yml)

Étapes : 1) extraire CSS, JS et blocs HTML ; 2) écrire une page par rubrique, catégorie et ville ;
3) pré-rendre chaque page dans Chromium pour que le contenu soit dans le HTML (lisible sans JavaScript) ;
4) sitemap.xml, robots.txt, manifeste, icônes, en-têtes d'hébergement.
"""
import json, re, shutil, pathlib, mimetypes, datetime, hashlib
from html import escape
from playwright.sync_api import sync_playwright

import os
B = pathlib.Path(__file__).resolve().parent
SRC = pathlib.Path(os.environ.get("GT_SRC", B.parent / "source" / "gouyerian-tech.html"))
DIST = pathlib.Path(os.environ.get("GT_DIST", B.parent / "_site"))
SITE = "https://gouyerian-tech.fr"
TODAY = datetime.date.today().isoformat()
PLACE_ID = "ChIJY1Q5ZwBhAEgRGG3fYcH4qpk"
MAPS = "https://www.google.com/maps/search/?api=1&query=Gouyerian+Tech%2C+1+route+de+Segonzac%2C+16130+Saint-Fort-sur-le-N%C3%A9&query_place_id=" + PLACE_ID

src = SRC.read_text(encoding="utf-8")

# ---------- 1. Extraction ----------
css = src[src.index("<style>") + 7: src.index("</style>")]
js = src[src.rindex("<script>") + 8: src.rindex("</script>")]
body = src[src.index('<a class="skip"'): src.rindex("<script>")].rstrip()
body = body.replace('src="assets/', 'src="/assets/')

main_open = body.index('<main id="main">') + len('<main id="main">')
main_close = body.index("</main>")
pre, views_html, post = body[:main_open], body[main_open:main_close], body[main_close:]

starts = [m.start() for m in re.finditer(r'<div class="view" id="v-', views_html)]
VIEW = {}
for i, s in enumerate(starts):
    e = starts[i + 1] if i + 1 < len(starts) else len(views_html)
    chunk = re.sub(r"\s*<!--[^>]*-->\s*$", "", views_html[s:e].rstrip())
    name = re.match(r'<div class="view" id="v-([\w-]+)"', chunk).group(1)
    VIEW[name] = re.sub(r'(<div class="view" id="v-[\w-]+") hidden>', r"\1>", chunk, count=1)
print("vues :", ", ".join(VIEW))

# ---------- Polices hébergées avec le site (plus d'appel à Google Fonts) ----------
UR = {
    "latin": "U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD",
    "latin-ext": "U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF",
}
faces = []
for sub in ("latin-ext", "latin"):
    faces.append(f'@font-face{{font-family:"Archivo";font-style:normal;font-weight:100 900;font-stretch:62% 125%;font-display:swap;src:url(/assets/fonts/archivo-{sub}-wdth-normal.woff2) format("woff2");unicode-range:{UR[sub]}}}')
    for fam, file, weights in (("Cinzel", "cinzel", (600, 700)), ("IBM Plex Sans", "ibm-plex-sans", (400, 500, 600)), ("IBM Plex Mono", "ibm-plex-mono", (400, 500))):
        for w in weights:
            faces.append(f'@font-face{{font-family:"{fam}";font-style:normal;font-weight:{w};font-display:swap;src:url(/assets/fonts/{file}-{sub}-{w}-normal.woff2) format("woff2");unicode-range:{UR[sub]}}}')
BASE = "html{-webkit-text-size-adjust:100%;text-size-adjust:100%}body{margin:0}img{max-width:100%}[hidden]{display:none!important}"
site_css = "/* Gouyerian Tech, feuille de style du site */\n" + "\n".join(faces) + "\n" + BASE + "\n" + css.strip() + "\n"
VER = hashlib.sha1((site_css + js).encode("utf-8")).hexdigest()[:10]

# ---------- 2. Pages ----------
CATP = {
    "telephones": dict(cat="Téléphones", crumb="Téléphones", h1="Réparation de téléphone",
        lead="Écran cassé, batterie usée, connecteur de charge, caméra ou téléphone tombé dans l’eau : réparation de smartphones toutes marques à Saint-Fort-sur-le-Né, près de Cognac. Devis gratuit, et vous gardez votre téléphone le temps de recevoir les pièces.",
        title="Réparation de téléphone près de Cognac · Gouyerian Tech",
        desc="Réparation de smartphones toutes marques près de Cognac : écran, batterie, connecteur de charge, caméra. Devis gratuit, écran en 3 à 4 jours en général."),
    "tablettes": dict(cat="Tablettes", crumb="Tablettes", h1="Réparation de tablette",
        lead="Vitre cassée, batterie faible, tablette qui ne charge plus ou ne démarre plus : réparation de tablettes toutes marques (iPad, Galaxy Tab, Lenovo…) à Saint-Fort-sur-le-Né, près de Cognac. Devis gratuit avant toute intervention.",
        title="Réparation de tablette près de Cognac · Gouyerian Tech",
        desc="Réparation de tablettes iPad, Samsung, Lenovo et autres près de Cognac : écran, batterie, connecteur de charge, logiciel. Devis gratuit, prix annoncé avant travaux."),
    "ordinateurs": dict(cat="Ordinateurs", crumb="Ordinateurs", h1="Réparation et dépannage d’ordinateur",
        lead="Ordinateur lent, virus, Windows à réinstaller, données à récupérer, écran ou clavier cassé : PC et Mac, portables et fixes. Nettoyage de virus 30 €, réinstallation de Windows dès 50 €, en 24 h.",
        title="Réparation d’ordinateur près de Cognac · Gouyerian Tech",
        desc="Nettoyage de virus 30 €, réinstallation de Windows 50 € (80 € avec sauvegarde), récupération de données 80 €. PC et Mac, près de Cognac, en 24 h."),
    "consoles": dict(cat="Consoles", crumb="Consoles", h1="Réparation de console de jeux",
        lead="Console qui ne charge plus, plus d’image sur la télévision, manette qui bouge toute seule ou console qui surchauffe : réparation de Nintendo Switch, PlayStation et Xbox à Saint-Fort-sur-le-Né, près de Cognac. Devis gratuit avant toute intervention.",
        title="Réparation de console près de Cognac · Gouyerian Tech",
        desc="Réparation de Switch, PS5, PS4 et Xbox près de Cognac : connecteur de charge, port HDMI, manettes, surchauffe. Devis gratuit, prix annoncé avant travaux."),
    "assistance": dict(cat="Assistance", crumb="Assistance et professionnels", h1="Assistance informatique à domicile et pour les pros",
        lead="Dépannage à domicile jusqu’à 40 km autour de Saint-Fort-sur-le-Né, assistance à distance, installation de box, de Wi-Fi et d’imprimante, accompagnement des seniors. Pour les professionnels de Nouvelle-Aquitaine : virus, Wi-Fi et réseau entre bâtiments, dépannage courant.",
        title="Assistance informatique à domicile et pros · Gouyerian Tech",
        desc="Dépannage informatique à domicile (10 à 20 €), assistance à distance, box, Wi-Fi, imprimante, seniors ; virus, réseau et dépannage pour les pros, près de Cognac."),
}

VOFF = """<div class="view" id="v-off">
  <header class="phead"><div class="wrap">
    <span class="crumb"><a href="#home">Accueil</a> / Hors connexion</span>
    <h1>Vous êtes hors connexion</h1>
    <p class="lead">Cette page n’a pas encore été enregistrée sur votre appareil. Vérifiez votre connexion à Internet, puis réessayez. Les pages déjà consultées restent disponibles.</p>
    <div class="cta"><a class="btn" href="#home">Retour à l’accueil</a><a class="btn ghost" href="tel:+33605794894">Appeler le 06 05 79 48 94</a></div>
  </div></header>
</div>"""

V404 = """<div class="view" id="v-404">
  <header class="phead"><div class="wrap">
    <span class="crumb"><a href="#home">Accueil</a> / Page introuvable</span>
    <h1>Page introuvable</h1>
    <p class="lead">Cette page n’existe pas ou a été déplacée. Les pages les plus consultées :</p>
    <div class="cta"><a class="btn" href="#home">Retour à l’accueil</a><a class="btn ghost" href="#services" data-cat="Tous">Services et tarifs</a><a class="btn ghost" href="#boutique" data-bcat="Tout">Boutique</a><a class="btn ghost" href="#demande">Prendre rendez-vous</a></div>
  </div></header>
</div>"""


def services_variant(key):
    c = CATP[key]
    v = VIEW["services"]
    v = re.sub(r'<span class="crumb">.*?</span>', f'<span class="crumb"><a href="#home">Accueil</a> / <a href="#services">Services et tarifs</a> / {c["crumb"]}</span>', v, count=1, flags=re.S)
    v = re.sub(r"<h1>.*?</h1>", f"<h1>{c['h1']}</h1>", v, count=1, flags=re.S)
    v = re.sub(r'<p class="lead">.*?</p>', f'<p class="lead">{c["lead"]}</p>', v, count=1, flags=re.S)
    return v


# Données calculées par le site lui-même (villes principales, distances, FAQ, tarifs)
def site_data():
    tmp = DIST / "_probe"
    tmp.mkdir(parents=True, exist_ok=True)
    (tmp / "index.html").write_text("<!doctype html><html lang=fr><head><meta charset=utf-8><style>[hidden]{display:none!important}</style></head><body>" + body.replace('src="/assets/', 'src="assets/') + "<script>" + js + "</script></body></html>", encoding="utf-8")
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_page(service_workers="block")
        pg.route("**/*", lambda r: r.continue_() if r.request.url.startswith("file:") else r.abort())
        pg.goto((tmp / "index.html").as_uri())
        pg.wait_for_timeout(300)
        d = pg.evaluate("""()=>({cpg:CPG.map(p=>({n:p.n,s:p.s,cp:p.cp,km:p.km,dir:dirOf(p),dept:dept(p)})),zone:ZONE.length,
          faq:[...document.querySelectorAll('#v-faq details')].map(x=>({q:x.querySelector('summary').textContent.trim(),a:x.querySelector('p').textContent.trim()})),
          sv:SV.map(s=>({c:s.c,n:s.n,p:s.p})),
          arts:ARTS.map(a=>({s:a.s,t:a.t,d:a.d,cat:a.cat,date:a.date,min:a.min})),news:NEWS.length,nl:NEWS.map(n=>({id:n.id,t:n.t,s:n.s,d:n.d,th:THL(n.th),src:n.src.map(x=>x[1])}))})""")
        b.close()
    shutil.rmtree(tmp)
    return d


if DIST.exists():
    shutil.rmtree(DIST)
DIST.mkdir(parents=True)
DATA = site_data()
CPG = DATA["cpg"]
print("villes principales :", len(CPG), "· communes dans la zone :", DATA["zone"], "· FAQ :", len(DATA["faq"]), "· conseils :", len(DATA["arts"]))
assert len(DATA["faq"]) >= 20 and len(DATA["arts"]) >= 7


def fee(km):
    return "10 €" if km <= 10 else "20 €" if km <= 40 else "sur devis"


PAGES = []  # dict(path, view, markup, title, desc, crumbs, ld, pg, changefreq, priority)


IMGV = {f.name: hashlib.sha1(f.read_bytes()).hexdigest()[:8] for f in sorted((B / "img" / "ill").iterdir())}  # empreintes des illustrations


def iv(name):
    """« ?v=empreinte » d'une illustration : une image redessinée change d'adresse, donc plus de vieille copie en cache."""
    return f"?v={IMGV[name]}" if name in IMGV else ""


def add(path, view, markup, title, desc, crumbs, ld=None, pg=None, prio="0.7", index=True, extra_head="", lastmod=TODAY, og=None, imgs=()):
    PAGES.append(dict(path=path, view=view, markup=markup, title=title, desc=desc, crumbs=crumbs, ld=ld or [], pg=pg or {"view": view}, prio=prio, index=index, extra_head=extra_head, lastmod=lastmod, og=og, imgs=imgs))


ORG_ID = SITE + "/#entreprise"
HOURS = [{"@type": "OpeningHoursSpecification", "dayOfWeek": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"], "opens": "09:00", "closes": "20:00"},
         {"@type": "OpeningHoursSpecification", "dayOfWeek": "Sunday", "opens": "09:00", "closes": "13:00"}]
ADDR = {"@type": "PostalAddress", "streetAddress": "1 route de Segonzac", "postalCode": "16130", "addressLocality": "Saint-Fort-sur-le-Né", "addressRegion": "Nouvelle-Aquitaine", "addressCountry": "FR"}


def offer(name, price, desc=None):
    o = {"@type": "Offer", "itemOffered": {"@type": "Service", "name": name}, "priceCurrency": "EUR"}
    if isinstance(price, tuple):
        o["priceSpecification"] = {"@type": "PriceSpecification", "minPrice": price[0], "maxPrice": price[1], "priceCurrency": "EUR"}
    else:
        o["price"] = price
    if desc:
        o["itemOffered"]["description"] = desc
    return o


BUSINESS = {
    "@context": "https://schema.org", "@type": "ComputerStore", "@id": ORG_ID,
    "name": "Gouyerian Tech", "url": SITE + "/", "logo": SITE + "/assets/logo-carre.png", "image": SITE + "/assets/og-image.jpg",
    "description": "Réparation et maintenance de téléphones, tablettes, ordinateurs et consoles de jeux, achat et vente d’appareils et d’accessoires à Saint-Fort-sur-le-Né, près de Cognac. Devis gratuit, déplacement à domicile jusqu’à 40 km, professionnels dans toute la Nouvelle-Aquitaine.",
    "telephone": "+33605794894", "email": "gouyerian.tech@gmail.com", "priceRange": "€", "currenciesAccepted": "EUR",
    "paymentAccepted": "Carte bancaire, espèces", "founder": {"@type": "Person", "name": "Achot Gouyerian"},
    "identifier": {"@type": "PropertyValue", "propertyID": "SIRET", "value": "98489954200026"},
    "address": ADDR, "geo": {"@type": "GeoCoordinates", "latitude": 45.5749, "longitude": -0.30094},
    "hasMap": MAPS, "openingHoursSpecification": HOURS,
    "areaServed": [{"@type": "GeoCircle", "geoMidpoint": {"@type": "GeoCoordinates", "latitude": 45.5817, "longitude": -0.3072}, "geoRadius": "40000"}]
                  + [{"@type": "City", "name": c["n"]} for c in CPG]
                  + [{"@type": "State", "name": "Nouvelle-Aquitaine"}],
    "hasOfferCatalog": {"@type": "OfferCatalog", "name": "Tarifs Gouyerian Tech", "itemListElement": [
        offer("Devis", "0", "Analyse de la panne, sans engagement"),
        offer("Nettoyage de virus", "30", "Suppression des virus et sécurisation du système, en 24 h"),
        offer("Réinstallation de Windows", "50", "Sans conservation des données, en 24 h"),
        offer("Réinstallation de Windows avec sauvegarde des données", "80", "Si l’état de l’appareil le permet, 48 à 72 h"),
        offer("Récupération de données", "80", "Si la récupération est possible, 48 à 72 h"),
        offer("Déplacement à domicile (jusqu’à 40 km)", ("10", "20"), "10 € jusqu’à 10 km, 20 € de 10 à 40 km"),
    ]},
}


def crumbs_ld(crumbs):
    return {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "name": n, "item": SITE + u} for i, (n, u) in enumerate(crumbs)]}


HOME = ("Accueil", "/")

# Accueil
add("/", "home", VIEW["home"], "Réparation informatique près de Cognac · Gouyerian Tech",
    "Réparation de téléphones, tablettes, ordinateurs et consoles près de Cognac : vente d’accessoires, devis gratuit, déplacement jusqu’à 40 km.",
    [HOME], ld=[BUSINESS, {"@context": "https://schema.org", "@type": "WebSite", "@id": SITE + "/#site", "name": "Gouyerian Tech", "url": SITE + "/", "inLanguage": "fr-FR", "publisher": {"@id": ORG_ID}}], prio="1.0")

# Services et catégories
faq_ld = {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
    {"@type": "Question", "name": f["q"], "acceptedAnswer": {"@type": "Answer", "text": f["a"]}} for f in DATA["faq"]]}
add("/services/", "services", VIEW["services"], "Services et tarifs de réparation · Gouyerian Tech",
    "Nettoyage de virus 30 €, réinstallation de Windows 50 €, récupération de données 80 €. Écrans et batteries sur devis gratuit, toutes marques, près de Cognac.",
    [HOME, ("Services et tarifs", "/services/")], prio="0.9")
for key, c in CATP.items():
    svc = [s for s in DATA["sv"] if s["c"] == c["cat"]]
    ld = {"@context": "https://schema.org", "@type": "Service", "name": c["h1"], "serviceType": c["h1"], "provider": {"@id": ORG_ID},
          "areaServed": {"@type": "GeoCircle", "geoMidpoint": {"@type": "GeoCoordinates", "latitude": 45.5817, "longitude": -0.3072}, "geoRadius": "40000"},
          "hasOfferCatalog": {"@type": "OfferCatalog", "name": c["h1"], "itemListElement": [
              {"@type": "Offer", "itemOffered": {"@type": "Service", "name": s["n"]}, **({"price": re.sub(r"\D", "", s["p"]) or "0", "priceCurrency": "EUR"} if re.search(r"\d+ €$|^Gratuit$", s["p"]) else {})} for s in svc]}}
    add(f"/services/{key}/", "services", services_variant(key), c["title"], c["desc"],
        [HOME, ("Services et tarifs", "/services/"), (c["crumb"], f"/services/{key}/")], ld=[ld], pg={"view": "services", "cat": key}, prio="0.9")

# Professionnels
PRO_LD = [("Prévention et nettoyage des virus", "Nettoyage complet, antivirus installé et vérifié, mises à jour de Windows et des logiciels", "30"),
          ("Wi-Fi et réseau entre bâtiments ou bureaux", "Étude de couverture, répéteurs, bornes Wi-Fi, câblage Ethernet, Wi-Fi invités", None),
          ("Résolution des problèmes informatiques courants", "Ordinateur lent ou bloqué, imprimante, messagerie, connexion Internet, sur place ou à distance", None)]
add("/professionnels/", "pro", VIEW["pro"], "Informatique pour les professionnels · Gouyerian Tech",
    "Commerces, artisans, domaines viticoles : virus, Wi-Fi et réseau entre bâtiments, dépannage courant. Devis gratuit dans toute la Nouvelle-Aquitaine.",
    [HOME, ("Professionnels", "/professionnels/")],
    ld=[{"@context": "https://schema.org", "@type": "Service", "name": "Informatique pour les professionnels", "serviceType": "Dépannage, sécurité et réseau informatique pour les entreprises",
         "provider": {"@id": ORG_ID}, "audience": {"@type": "BusinessAudience", "name": "Commerces, artisans, TPE, domaines viticoles, cabinets, associations"},
         "areaServed": {"@type": "State", "name": "Nouvelle-Aquitaine"},
         "hasOfferCatalog": {"@type": "OfferCatalog", "name": "Prestations pour les professionnels", "itemListElement": [
             {"@type": "Offer", "itemOffered": {"@type": "Service", "name": n, "description": d}, **({"price": p, "priceCurrency": "EUR", "description": "Nettoyage de virus, par poste"} if p else {})}
             for n, d, p in PRO_LD]}}],
    prio="0.9")

# FAQ, conseils, actualités
add("/faq/", "faq", VIEW["faq"], "Questions fréquentes : devis, délais, garanties · Gouyerian Tech",
    "Devis gratuit, délais de réparation, déplacement à domicile, livraison, garanties, paiement, forfaits pros : toutes les réponses de Gouyerian Tech, près de Cognac.",
    [HOME, ("Questions fréquentes", "/faq/")], ld=[faq_ld], prio="0.8")
add("/infos-pratiques/", "conseils", VIEW["conseils"], "Infos pratiques en informatique · Gouyerian Tech",
    "Les infos pratiques de l’atelier : téléphone tombé dans l’eau, ordinateur lent, batterie, arnaques, sauvegardes, reconditionné ou neuf, Windows 10.",
    [HOME, ("Infos pratiques", "/infos-pratiques/")],
    ld=[{"@context": "https://schema.org", "@type": "ItemList", "name": "Infos pratiques Gouyerian Tech", "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "url": f"{SITE}/infos-pratiques/{a['s']}/", "name": a["t"]} for i, a in enumerate(DATA["arts"])]}],
    prio="0.8")
for a in DATA["arts"]:
    manque = [f for f in (f"{a['s']}-640.webp", f"{a['s']}-1280.webp", f"og-{a['s']}.jpg") if f not in IMGV]
    if manque:
        raise SystemExit(f"Illustration manquante pour l’info pratique « {a['s']} » : {', '.join(manque)} (ajouter S['{a['s']}'] dans outils/gen_illus.py puis : python outils/gen_illus.py {a['s']})")
    path = f"/infos-pratiques/{a['s']}/"
    t = a["t"] + " · Gouyerian Tech"
    add(path, "article", VIEW["article"], t if len(t) <= 65 else a["t"], a["d"],
        [HOME, ("Infos pratiques", "/infos-pratiques/"), (a["t"], path)],
        ld=[{"@context": "https://schema.org", "@type": "Article", "headline": a["t"], "description": a["d"], "inLanguage": "fr-FR",
             "datePublished": a["date"], "dateModified": a["date"], "articleSection": a["cat"], "timeRequired": f"PT{a['min']}M",
             "image": [SITE + f"/assets/img/og-{a['s']}.jpg" + iv(f"og-{a['s']}.jpg"), SITE + f"/assets/img/{a['s']}-1280.webp" + iv(f"{a['s']}-1280.webp")], "mainEntityOfPage": SITE + path,
             "author": {"@type": "Organization", "name": "Gouyerian Tech", "url": SITE + "/"}, "publisher": {"@id": ORG_ID}}],
        pg={"view": "article", "slug": a["s"]}, prio="0.7", lastmod=a["date"], og=f"/assets/img/og-{a['s']}.jpg" + iv(f"og-{a['s']}.jpg"), imgs=(f"/assets/img/{a['s']}-1280.webp",))
add("/actualites/", "actus", VIEW["actus"], "Actualités tech France et monde · Gouyerian Tech",
    f"{DATA['news']} actualités tech résumées simplement : smartphones, ordinateurs, IA, Internet, sécurité, jeux vidéo. Un conseil pratique et le lien vers chaque source.",
    [HOME, ("Actualités", "/actualites/")], prio="0.7")


def short_title(t, n=65):
    full = t + " · Gouyerian Tech"
    if len(full) <= n:
        return full
    if len(t) <= n:
        return t
    out = ""
    for w in t.split():
        if len(out + " " + w) > n - 1:
            break
        out = (out + " " + w).strip()
    return out + "…"


def short_desc(t, n=160):
    if len(t) <= n:
        return t
    cut = t[:n].rsplit(" ", 1)[0]
    return cut.rstrip(",;:") + "…"


for n in DATA["nl"]:
    path = f"/actualites/{n['id']}/"
    add(path, "actu", VIEW["actu"], short_title(n["t"]), short_desc(n["s"]),
        [HOME, ("Actualités", "/actualites/"), (n["t"], path)],
        ld=[{"@context": "https://schema.org", "@type": "NewsArticle", "headline": n["t"][:110], "description": short_desc(n["s"]), "inLanguage": "fr-FR",
             "datePublished": n["d"], "dateModified": n["d"], "articleSection": n["th"], "isBasedOn": n["src"], "mainEntityOfPage": SITE + path,
             "image": SITE + "/assets/og-image.jpg", "author": {"@type": "Organization", "name": "Gouyerian Tech", "url": SITE + "/"}, "publisher": {"@id": ORG_ID}}],
        pg={"view": "actu", "slug": n["id"]}, prio="0.5", lastmod=n["d"])

# Boutique
add("/boutique/", "boutique", VIEW["boutique"], "Boutique : appareils et accessoires · Gouyerian Tech",
    "Appareils neufs et reconditionnés, chargeurs Apple et Android, adaptateurs USB-C, câbles HDMI et TNT, batteries externes, clés USB et micro SD, près de Cognac.",
    [HOME, ("Boutique", "/boutique/")], prio="0.9")

# Rendez-vous, zone, communes, contact, mentions
add("/rendez-vous/", "demande", VIEW["demande"], "Prendre rendez-vous · Gouyerian Tech",
    "Demandez un rappel en 3 étapes pour fixer votre rendez-vous, à l’atelier de Saint-Fort-sur-le-Né ou à domicile. Lun–sam 9h–20h, dim 9h–13h. Devis gratuit.",
    [HOME, ("Rendez-vous", "/rendez-vous/")], prio="0.8")
add("/zone-intervention/", "zone", VIEW["zone"], "Zone d’intervention à domicile (40 km) · Gouyerian Tech",
    "Déplacement à domicile jusqu’à 40 km autour de Saint-Fort-sur-le-Né : 10 € jusqu’à 10 km, 20 € au-delà. Trouvez votre commune ou adresse sur la carte.",
    [HOME, ("Zone d’intervention", "/zone-intervention/")], prio="0.8",
    extra_head='<link rel="preconnect" href="https://data.geopf.fr" crossorigin>')
add("/communes/", "secteurs", VIEW["secteurs"], "Communes desservies autour de Cognac · Gouyerian Tech",
    f"Les {DATA['zone']} communes de Charente et de Charente-Maritime à moins de 40 km de Saint-Fort-sur-le-Né : Cognac, Jarnac, Segonzac, Barbezieux, Jonzac, Pons, Saintes…",
    [HOME, ("Communes desservies", "/communes/")], prio="0.8")
for c in CPG:
    path = f"/communes/{c['s']}/"
    ct = next(t for t in (f"Dépannage informatique à {c['n']} ({c['cp']}) · Gouyerian Tech", f"Dépannage informatique à {c['n']} · Gouyerian Tech", f"Dépannage informatique à {c['n']} ({c['cp']})") if len(t) <= 62)
    cd = f"Réparation de téléphones, tablettes et ordinateurs à {c['n']}, à domicile (déplacement {fee(c['km'])}) ou à l’atelier, à {c['km']} km. Devis gratuit, virus 30 €, Windows 50 €."
    for cut in (f", à {c['km']} km", ", Windows 50 €"):
        if len(cd) > 160:
            cd = cd.replace(cut, "")
    add(path, "commune", VIEW["commune"], ct, cd,
        [HOME, ("Communes desservies", "/communes/"), (c["n"], path)],
        ld=[{"@context": "https://schema.org", "@type": "Service", "name": f"Dépannage informatique à {c['n']}", "serviceType": "Réparation et dépannage informatique",
             "provider": {"@id": ORG_ID}, "areaServed": {"@type": "City", "name": c["n"], "address": {"@type": "PostalAddress", "postalCode": c["cp"], "addressLocality": c["n"], "addressCountry": "FR"}},
             "offers": offer(f"Déplacement à domicile à {c['n']}", re.sub(r"\D", "", fee(c["km"])) or "0")}],
        pg={"view": "commune", "slug": c["s"]}, prio="0.7")
add("/contact/", "contact", VIEW["contact"], "Contact et atelier · Gouyerian Tech",
    "Appelez le 06 05 79 48 94 ou écrivez à gouyerian.tech@gmail.com. Atelier sur rendez-vous : 1 route de Segonzac, 16130 Saint-Fort-sur-le-Né.",
    [HOME, ("Contact", "/contact/")], ld=[BUSINESS], prio="0.8")
add("/mon-espace/", "compte", VIEW["compte"], "Mon espace client · Gouyerian Tech",
    "Espace client Gouyerian Tech : suivez vos réparations, acceptez vos devis, retrouvez vos garanties et votre panier. Connexion par code reçu par e-mail.",
    [HOME, ("Mon espace", "/mon-espace/")], prio="0.3", index=False)
add("/suivi/", "suivi", VIEW["suivi"], "Suivre ma réparation · Gouyerian Tech",
    "Suivez l’avancée de la réparation de votre téléphone, tablette, ordinateur ou console chez Gouyerian Tech avec le numéro et le code de votre bon de dépôt.",
    [HOME, ("Suivre ma réparation", "/suivi/")], prio="0.4")
add("/mentions-legales/", "legal", VIEW["legal"], "Mentions légales et confidentialité · Gouyerian Tech",
    "Éditeur, hébergement, protection des données personnelles et crédits du site Gouyerian Tech, réparateur informatique à Saint-Fort-sur-le-Né.",
    [HOME, ("Mentions légales", "/mentions-legales/")], prio="0.3")
add("/cgv/", "cgv", VIEW["cgv"], "Conditions générales de vente · Gouyerian Tech",
    "Conditions générales de vente de Gouyerian Tech (EI, TVA non applicable) : devis, prix, commandes, livraison, paiement, garanties, rétractation et médiation.",
    [HOME, ("Conditions générales de vente", "/cgv/")], prio="0.3")
add("/hors-ligne/", "off", VOFF, "Hors connexion · Gouyerian Tech", "Vous êtes hors connexion.", [HOME], index=False)
add("/404.html", "404", V404, "Page introuvable · Gouyerian Tech", "Cette page n’existe pas ou a été déplacée.", [HOME], index=False)

IMGS = {"/": [f"/assets/img/cat-{k}-1280.webp" for k in ("telephones", "tablettes", "ordinateurs", "consoles", "assistance")],
        "/professionnels/": [f"/assets/img/pro-{k}-1280.webp" for k in ("virus", "reseau", "depannage")]}
for pdef in PAGES:
    pdef["imgs"] = tuple(pdef["imgs"]) + tuple(IMGS.get(pdef["path"], ()))
for pdef in PAGES:
    assert len(pdef["title"]) <= 70, pdef["title"]
    assert len(pdef["desc"]) <= 175, (pdef["path"], len(pdef["desc"]))
assert len({pd["path"] for pd in PAGES}) == len(PAGES)


def head(pd):
    url = SITE + pd["path"]
    t, d = escape(pd["title"]), escape(pd["desc"])
    ld = [crumbs_ld(pd["crumbs"])] if len(pd["crumbs"]) > 1 else []
    ld += pd["ld"]
    lds = "\n".join('<script type="application/ld+json">' + json.dumps(x, ensure_ascii=False, separators=(",", ":")) + "</script>" for x in ld)
    robots = "index,follow,max-image-preview:large" if pd["index"] else "noindex,follow"
    canon = f'<link rel="canonical" href="{url}">\n' if pd["index"] else ""
    return f"""<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{t}</title>
<meta name="description" content="{d}">
<meta name="robots" content="{robots}">
{canon}<meta name="author" content="Gouyerian Tech">
<meta name="theme-color" content="#f4f5f6" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b0c0e" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_FR">
<meta property="og:site_name" content="Gouyerian Tech">
<meta property="og:title" content="{t}">
<meta property="og:description" content="{d}">
<meta property="og:url" content="{url}">
<meta property="og:image" content="{SITE}{pd["og"] or "/assets/og-image.jpg"}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Gouyerian Tech, réparation et maintenance informatique à Saint-Fort-sur-le-Né, près de Cognac">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/assets/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Gouyerian Tech">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="preload" href="/assets/fonts/archivo-latin-wdth-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/ibm-plex-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
{pd["extra_head"]}
<link rel="stylesheet" href="/assets/site.css?v={VER}">
<script>try{{var t=localStorage.getItem("gt-theme2");if(t)document.documentElement.dataset.gt=t}}catch(e){{}}</script>
{lds}"""


def page_html(pd):
    pgj = json.dumps(pd["pg"], ensure_ascii=False)
    return f"""<!doctype html>
<html lang="fr">
<head>
{head(pd)}
</head>
<body>
{pre}
{pd["markup"]}
{post}
<script>window.GT_PAGE={pgj}</script>
<script src="/assets/app.js?v={VER}" defer></script>
</body>
</html>
"""


def out_file(path):
    return DIST / ("index.html" if path == "/" else path.strip("/") + ("" if path.endswith(".html") else "/index.html"))


# ---------- Fichiers communs ----------
A = DIST / "assets"
(A / "fonts").mkdir(parents=True)
(A / "site.css").write_text(site_css, encoding="utf-8")
(A / "app.js").write_text("/* Gouyerian Tech, script du site */\nwindow.GT_IMGV=" + json.dumps(IMGV, separators=(",", ":")) + ";\n" + js.strip() + "\n", encoding="utf-8")
(A / "logo.png").write_bytes(__import__("base64").b64decode((B / "donnees" / "logo.b64").read_text().strip()))
(A / "adresses.js").write_text('window.GT_BANZ="' + (B / "donnees" / "ban.b64").read_text().strip() + '";\n', encoding="utf-8")
for f in (B / "fonts").iterdir():
    shutil.copy(f, A / "fonts" / f.name)
IMG = B / "img"
(A / "img").mkdir()
for f in (B / "img" / "ill").iterdir():  # illustrations (gen_illus.py)
    shutil.copy(f, A / "img" / f.name)
for n in ("og-image.jpg", "logo-carre.png", "favicon-32.png", "icon-192.png", "icon-512.png", "icon-maskable-512.png"):
    shutil.copy(IMG / n, A / n)
shutil.copy(IMG / "favicon.ico", DIST / "favicon.ico")
shutil.copy(IMG / "apple-touch-icon.png", DIST / "apple-touch-icon.png")

for pd in PAGES:
    f = out_file(pd["path"])
    f.parent.mkdir(parents=True, exist_ok=True)
    f.write_text(page_html(pd), encoding="utf-8")

# ---------- 3. Pré-rendu ----------
CLEAN = """()=>{
  const s=document.getElementById('status');if(s){s.classList.remove('open');const t=s.querySelector('.st-t');if(t)t.textContent='Lun–sam 9h–20h · dim 9h–13h'}
  document.querySelectorAll('[data-hours] tr.today').forEach(r=>r.classList.remove('today'));
  document.querySelectorAll('.slide').forEach(x=>{x.removeAttribute('aria-hidden');x.removeAttribute('inert')});
  const d=document.getElementById('dots');if(d)d.classList.remove('run','hold');
  ['gb','gt','gf'].forEach(i=>{const e=document.getElementById(i);if(e)e.innerHTML=''});
  const za=document.getElementById('za');if(za)za.textContent='Photographies aériennes © IGN';
  document.querySelectorAll('script[src*="adresses.js"]').forEach(x=>x.remove());
  const m=document.getElementById('mnav');if(m)m.removeAttribute('style');
  ['cookie','locp','locb','insth'].forEach(i=>{const e=document.getElementById(i);if(e)e.hidden=true});document.querySelectorAll('[data-locline],[data-install]').forEach(e=>e.hidden=true);
  const fn=document.getElementById('faqn');if(fn)fn.textContent='';
  document.documentElement.removeAttribute('data-gt');
  return '<!doctype html>\\n'+document.documentElement.outerHTML+'\\n'}"""


def serve(route):
    url = route.request.url
    if not url.startswith(SITE):
        return route.abort()
    path = url[len(SITE):].split("?")[0].split("#")[0]
    f = DIST / path.lstrip("/")
    if path.endswith("/"):
        f = f / "index.html"
    if not f.exists():
        return route.fulfill(status=404, path=str(DIST / "404.html"), content_type="text/html; charset=utf-8")
    ct = mimetypes.guess_type(str(f))[0] or "application/octet-stream"
    if ct.startswith("text/") or ct.endswith("javascript"):
        ct += "; charset=utf-8"
    return route.fulfill(status=200, path=str(f), content_type=ct)


with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(service_workers="block", viewport={"width": 1366, "height": 900}, locale="fr-FR", timezone_id="Europe/Paris")
    ctx.route("**/*", serve)
    errs = []
    for pd in PAGES:
        pg = ctx.new_page()
        pg.on("pageerror", lambda e, u=pd["path"]: errs.append((u, str(e))))
        pg.goto(SITE + pd["path"], wait_until="load")
        pg.wait_for_timeout(450)
        html = pg.evaluate(CLEAN)
        out_file(pd["path"]).write_text(html, encoding="utf-8")
        pg.close()
    b.close()
print("erreurs de pré-rendu :", errs or "aucune")

# ---------- 4. Référencement et hébergement ----------
urls = [pd for pd in PAGES if pd["index"]]
(DIST / "sitemap.xml").write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">\n' + "".join(
    f"  <url><loc>{SITE}{pd['path']}</loc><lastmod>{pd['lastmod']}</lastmod><priority>{pd['prio']}</priority>" + "".join(f"<image:image><image:loc>{SITE}{i}</image:loc></image:image>" for i in pd["imgs"]) + "</url>\n" for pd in urls) + "</urlset>\n", encoding="utf-8")
(DIST / "envoye.html").write_text("""<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Demande envoyée · Gouyerian Tech</title><style>body{margin:0;font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;background:#f4f5f6;color:#0e0f11}main{max-width:420px;padding:24px;text-align:center}a{color:#2563eb}</style></head>
<body><main><h1>Demande envoyée</h1><p>Merci, Gouyerian Tech vous rappelle rapidement.</p><p><a href="/">Retour au site</a></p></main></body></html>
""", encoding="utf-8")
# Fichier de validation Google Search Console (ne pas supprimer)
(DIST / "googlec2ee2d1354c69554.html").write_text("google-site-verification: googlec2ee2d1354c69554.html", encoding="utf-8")
(DIST / "robots.txt").write_text(f"User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /gestion/\n\nSitemap: {SITE}/sitemap.xml\n", encoding="utf-8")
# Suivi des réparations : serveur PHP (api/) et page de gestion de l'atelier (gestion/), copiés tels quels
SRV = B.parent / "serveur"
for sub in ("api", "gestion"):
    if (SRV / sub).is_dir():
        shutil.copytree(SRV / sub, DIST / sub, dirs_exist_ok=True)
(DIST / "site.webmanifest").write_text(json.dumps({
    "id": "/", "name": "Gouyerian Tech", "short_name": "Gouyerian Tech", "description": "Réparation, maintenance et vente informatique près de Cognac",
    "lang": "fr", "dir": "ltr", "start_url": "/", "scope": "/", "display": "standalone", "orientation": "any",
    "background_color": "#f4f5f6", "theme_color": "#0a1530", "categories": ["business", "utilities", "shopping"],
    "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any"},
              {"src": "/assets/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any"},
              {"src": "/assets/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"}],
    "shortcuts": [{"name": "Prendre rendez-vous", "short_name": "Rendez-vous", "url": "/rendez-vous/", "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]},
                  {"name": "Boutique", "url": "/boutique/", "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]},
                  {"name": "Services et tarifs", "short_name": "Tarifs", "url": "/services/", "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]},
                  {"name": "Infos pratiques", "short_name": "Infos", "url": "/infos-pratiques/", "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]}]},
    ensure_ascii=False, indent=2), encoding="utf-8")
fonts_pre = ["/assets/fonts/archivo-latin-wdth-normal.woff2"] + [f"/assets/fonts/{f}-latin-{w}-normal.woff2" for f, ws in (("ibm-plex-sans", (400, 500, 600)), ("ibm-plex-mono", (400, 500)), ("cinzel", (600, 700))) for w in ws]
CORE = ["/", "/hors-ligne/", f"/assets/site.css?v={VER}", f"/assets/app.js?v={VER}", "/assets/logo.png", "/assets/icon-192.png", "/site.webmanifest"] + fonts_pre
for u in CORE:
    assert (DIST / (u.split("?")[0].lstrip("/") or "index.html")).exists() or u.endswith("/"), u
(DIST / "sw.js").write_text(f"""/* Gouyerian Tech, service worker : le site s’ouvre comme une application et reste consultable hors connexion. */
const V="gt-{VER}",CORE={json.dumps(CORE)};
self.addEventListener("install",e=>{{e.waitUntil(caches.open(V).then(c=>c.addAll(CORE.map(u=>new Request(u,{{cache:"reload"}})))).then(()=>self.skipWaiting()))}});
self.addEventListener("activate",e=>{{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(x=>x!==V).map(x=>caches.delete(x)))).then(()=>self.clients.claim()))}});
self.addEventListener("fetch",e=>{{
  const r=e.request;if(r.method!=="GET")return;const u=new URL(r.url);if(u.origin!==location.origin||u.pathname.startsWith("/api/")||u.pathname.startsWith("/gestion/"))return;
  if(r.mode==="navigate"){{
    e.respondWith(fetch(r).then(res=>{{if(res.ok){{const c=res.clone();caches.open(V).then(x=>x.put(r,c))}}return res}})
      .catch(()=>caches.match(r,{{ignoreSearch:true}}).then(m=>m||caches.match("/hors-ligne/"))));return}}
  if(u.pathname.startsWith("/assets/")||u.pathname==="/site.webmanifest"){{
    e.respondWith(caches.open(V).then(c=>c.match(r).then(m=>{{const f=fetch(r).then(res=>{{if(res.ok)c.put(r,res.clone());return res}}).catch(()=>m);return m||f}})))}}
}});
""", encoding="utf-8")
SEC = "  X-Content-Type-Options: nosniff\n  Referrer-Policy: strict-origin-when-cross-origin\n  Permissions-Policy: camera=(), microphone=(), geolocation=(self)\n  X-Frame-Options: SAMEORIGIN\n"
(DIST / "_headers").write_text("/*\n" + SEC + "\n/sw.js\n  Cache-Control: no-cache\n\n/assets/fonts/*\n  Cache-Control: public, max-age=31536000, immutable\n\n/assets/img/*\n  Cache-Control: public, max-age=2592000\n\n/assets/*\n  Cache-Control: public, max-age=604800\n", encoding="utf-8")
(DIST / ".htaccess").write_text("""# Gouyerian Tech, réglages pour l’hébergement IONOS (Apache)
ErrorDocument 404 /404.html
Options -Indexes
DirectoryIndex index.html

# Toujours en https et sans « www »
RewriteEngine On
RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} ^www\\. [NC]
RewriteRule ^ https://gouyerian-tech.fr%{REQUEST_URI} [L,R=301]

<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
  Header set Permissions-Policy "camera=(), microphone=(), geolocation=(self)"
  Header set X-Frame-Options "SAMEORIGIN"
</IfModule>
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType font/woff2 "access plus 1 year"
  ExpiresByType image/png "access plus 1 month"
  ExpiresByType image/jpeg "access plus 1 month"
  ExpiresByType image/webp "access plus 1 month"
  ExpiresByType text/css "access plus 1 week"
  ExpiresByType text/javascript "access plus 1 week"
  ExpiresByType application/javascript "access plus 1 week"
</IfModule>
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/json image/svg+xml application/xml
</IfModule>
AddType font/woff2 .woff2
AddType image/webp .webp
AddType application/manifest+json .webmanifest

# Le service worker (application) doit toujours être relu
<Files "sw.js">
  <IfModule mod_expires.c>
    ExpiresActive Off
  </IfModule>
  <IfModule mod_headers.c>
    Header set Cache-Control "no-cache"
  </IfModule>
</Files>
""", encoding="utf-8")
json.dump([{"path": pd["path"], "title": pd["title"], "desc": pd["desc"], "index": pd["index"]} for pd in PAGES],
          open(B / "pages.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("pages :", len(PAGES))
