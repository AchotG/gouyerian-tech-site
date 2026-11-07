"""Teste le site découpé, servi comme s'il était en ligne sur https://gouyerian-tech.fr/."""
import json, re, pathlib, mimetypes, urllib.parse
from playwright.sync_api import sync_playwright

import os
DIST = pathlib.Path(os.environ.get("GT_DIST", pathlib.Path(__file__).resolve().parent.parent / "_site"))
SITE = "https://gouyerian-tech.fr"
SHOTS = pathlib.Path(os.environ.get("GT_SHOTS", "/tmp/gt-captures"))
SHOTS.mkdir(parents=True, exist_ok=True)
PAGES = json.load(open(pathlib.Path(__file__).parent / "pages.json", encoding="utf-8"))
external, problems, form_posts, multi = set(), [], [], []


def serve(route):
    url = route.request.url
    if url.startswith("https://formsubmit.co/ajax/"):
        form_posts.append(route.request.post_data)
        return route.fulfill(status=200, content_type="application/json", body='{"success":"true"}')
    if url.startswith("https://formsubmit.co/"):
        multi.append((route.request.headers.get("content-type", ""), route.request.post_data_buffer or b""))
        # comme FormSubmit après un envoi réussi : redirection vers la page _next (envoye.html du site)
        return route.fulfill(status=200, content_type="text/html", body='<script>location.replace("' + SITE + '/envoye.html")</script>')
    if not url.startswith(SITE):
        external.add(urllib.parse.urlparse(url).netloc)
        return route.abort()
    path = urllib.parse.unquote(url[len(SITE):].split("?")[0].split("#")[0])
    f = DIST / path.lstrip("/")
    if path.endswith("/"):
        f = f / "index.html"
    if not f.exists() or f.is_dir():
        return route.fulfill(status=404, path=str(DIST / "404.html"), content_type="text/html; charset=utf-8")
    ct = mimetypes.guess_type(str(f))[0] or "application/octet-stream"
    return route.fulfill(status=200, path=str(f), content_type=ct + ("; charset=utf-8" if ct.startswith("text/") or "javascript" in ct else ""))


def bad(msg):
    problems.append(msg)
    print("  ✗", msg)


# 1. Données structurées et liens internes (lecture des fichiers)
links = set()
for pd in PAGES:
    f = DIST / ("index.html" if pd["path"] == "/" else pd["path"].strip("/") + ("" if pd["path"].endswith(".html") else "/index.html"))
    s = f.read_text(encoding="utf-8")
    for m in re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        try:
            json.loads(m)
        except Exception as e:
            bad(f"JSON-LD invalide sur {pd['path']} : {e}")
    if "fonts.googleapis" in s:
        bad(f"Google Fonts encore appelé sur {pd['path']}")
    for h in re.findall(r'href="([^"]+)"', s):
        if h.startswith("/") and not h.startswith("//"):
            links.add(h.replace("&amp;", "&"))
        elif h.startswith("#") and h != "#main":
            bad(f"lien en # restant sur {pd['path']} : {h}")
anchors = {}
for h in sorted(links):
    p, _, frag = h.partition("#")
    p = p.split("?")[0]
    f = DIST / p.lstrip("/")
    if p.endswith("/"):
        f = f / "index.html"
    if not f.exists():
        bad(f"lien cassé : {h}")
    elif frag:
        anchors.setdefault(str(f), set()).add(frag)
for f, frs in anchors.items():
    s = pathlib.Path(f).read_text(encoding="utf-8")
    for fr in frs:
        if f'id="{fr}"' not in s:
            bad(f"ancre absente : {f} #{fr}")
print("liens internes vérifiés :", len(links))

with sync_playwright() as p:
    b = p.chromium.launch()
    for vw, vh, tag in ((1366, 900, "ordi"), (390, 844, "mobile")):
        ctx = b.new_context(service_workers="block", viewport={"width": vw, "height": vh}, locale="fr-FR", timezone_id="Europe/Paris", has_touch=(tag == "mobile"))
        ctx.add_init_script("try{if(!localStorage.getItem('gt-consent'))localStorage.setItem('gt-consent',JSON.stringify({v:'non',t:Date.now()}))}catch(e){}"); ctx.route("**/*", serve)
        for pd in PAGES:
            pg = ctx.new_page()
            errs = []
            pg.on("pageerror", lambda e: errs.append(str(e)))
            pg.on("console", lambda m: errs.append(m.text) if m.type == "error" and "ERR_FAILED" not in m.text and "net::" not in m.text else None)
            r = pg.goto(SITE + pd["path"], wait_until="load")
            pg.wait_for_timeout(350)
            st = pg.evaluate("""()=>({h:document.documentElement.scrollWidth>innerWidth,h1:document.querySelectorAll('h1').length,
              views:[...document.querySelectorAll('.view')].filter(v=>!v.hidden).length,title:document.title,
              dup:[...document.querySelectorAll('[data-ic]')].filter(e=>e.querySelectorAll(':scope>svg.i').length>1).length,
              fonts:[...document.fonts].filter(f=>f.status==='loaded').map(f=>f.family+' '+f.weight)})""")
            if errs: bad(f"{tag} {pd['path']} erreurs JS : {errs[:3]}")
            if st["h"]: bad(f"{tag} {pd['path']} défilement horizontal")
            if st["h1"] != 1: bad(f"{tag} {pd['path']} h1 = {st['h1']}")
            if st["views"] != 1: bad(f"{tag} {pd['path']} vues visibles = {st['views']}")
            if st["dup"]: bad(f"{tag} {pd['path']} icônes en double = {st['dup']}")
            if st["title"] != pd["title"]: bad(f"{tag} {pd['path']} titre modifié : {st['title']}")
            if pd["path"] != "/404.html" and r.status != 200: bad(f"{pd['path']} statut {r.status}")
            if pd["path"] == "/" and tag == "ordi":
                print("polices chargées :", sorted(set(st["fonts"])))
            pg.close()
        print(tag, ": pages parcourues", len(PAGES))
        ctx.close()

    # 2. Parcours fonctionnels
    ctx = b.new_context(service_workers="block", viewport={"width": 1366, "height": 900}, locale="fr-FR", timezone_id="Europe/Paris")
    ctx.add_init_script("try{if(!localStorage.getItem('gt-consent'))localStorage.setItem('gt-consent',JSON.stringify({v:'non',t:Date.now()}))}catch(e){}"); ctx.route("**/*", serve)
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: bad("erreur JS parcours : " + str(e)))

    pg.goto(SITE + "/")
    pg.screenshot(path=str(SHOTS / "accueil.png"))
    pg.hover("button[aria-controls=dd-services]"); pg.wait_for_timeout(300)
    pg.click("#dd-services a.mi >> nth=2"); pg.wait_for_load_state()
    print("menu → Ordinateurs :", pg.url, "|", pg.inner_text("h1"), "| onglet actif :", pg.eval_on_selector("#seg a[aria-current]", "e=>e.textContent"))
    print("  prestations :", pg.eval_on_selector_all("#svc .tk-n", "e=>e.length"), "| 1re :", pg.inner_text("#svc .tk-n >> nth=1"), pg.inner_text("#svc .tk-p >> nth=1"))
    pg.click("#seg a >> nth=0"); pg.wait_for_load_state()
    print("onglet Téléphones :", pg.url, "|", pg.inner_text("h1"))
    pg.click('#svc [data-b="Samsung"]'); pg.fill("#smod", "Galaxy A54"); pg.wait_for_timeout(100)
    href = pg.get_attribute("#svc [data-req] >> nth=0", "href")
    print("  lien devis :", urllib.parse.unquote(href))
    pg.click("#svc [data-req] >> nth=0"); pg.wait_for_load_state(); pg.wait_for_timeout(200)
    print("  formulaire :", pg.inner_text("label[for=fp]"), "|", pg.input_value("#fp"))
    pg.click("#nx"); pg.wait_for_timeout(100)
    pg.fill("#fn", "Test Client"); pg.fill("#ft", "0600000000")
    pg.type("#fcm", "Jarn", delay=40); pg.wait_for_timeout(500)
    print("  suggestions commune :", pg.eval_on_selector_all("#sg-c .sg span", "e=>e.slice(0,3).map(x=>x.textContent)"))
    pg.type("#fa", "1 route de Segonzac", delay=25); pg.wait_for_timeout(2500)
    sug = pg.eval_on_selector_all("#sg-a .sg span", "e=>e.map(x=>x.textContent)")
    print("  suggestions adresse :", sug[:4])
    i = next((k for k, t in enumerate(sug) if "Saint-Fort" in t), 0)
    pg.click(f"#sg-a .sg >> nth={i}"); pg.wait_for_timeout(150)
    print("  après choix :", pg.input_value("#fa"), "|", pg.input_value("#fcm"), "|", pg.inner_text("#fcmi"))
    pg.click("#ml"); pg.wait_for_timeout(600)
    print("  envoi :", pg.inner_text("#wiz h3") if pg.query_selector("#wiz h3") else pg.inner_text("#fm"), "| données envoyées :", (form_posts[-1] or "")[:160])

    pg.goto(SITE + "/boutique/?b=protection"); pg.wait_for_timeout(200)
    print("boutique ?b=protection :", pg.eval_on_selector_all("#shop h3", "e=>e.map(x=>x.textContent)"))
    pg.click("#shop [data-add] >> nth=0"); pg.wait_for_timeout(200)
    pg.fill(".ci-note", "iPhone 13"); pg.click("#cartx")
    pg.goto(SITE + "/boutique/?b=chargeurs-et-cables"); pg.wait_for_timeout(200)
    pg.click('[data-f="chargeur"] .cf-tog'); pg.click('[data-f="chargeur"] button[data-v="android"]'); pg.click('[data-f="chargeur"] [data-addcart]'); pg.click("#cartx")
    pg.goto(SITE + "/contact/"); pg.wait_for_timeout(200)
    print("panier conservé d’une page à l’autre :", pg.inner_text("#cartn"))
    pg.click("#cartb"); pg.wait_for_timeout(150)
    pg.fill(".ci-n >> nth=0", "150"); pg.press(".ci-n >> nth=0", "Enter"); pg.wait_for_timeout(100)
    print("  quantité 150 saisie →", pg.input_value(".ci-n >> nth=0"))
    if pg.input_value(".ci-n >> nth=0") != "100": bad("limite de 100 par article")
    pg.fill(".ci-n >> nth=0", "1"); pg.press(".ci-n >> nth=0", "Enter"); pg.wait_for_timeout(100)
    print("  articles :", pg.eval_on_selector_all(".cart-l .ci-t b", "e=>e.map(x=>x.textContent)"), "| note :", pg.input_value(".ci-note"))
    pg.click("#cartgo"); pg.fill("#cn", "Client Test"); pg.fill("#ct", "0600000000"); pg.click("#carts"); pg.wait_for_timeout(200)
    print("  sans case CGV :", pg.inner_text("#cartm"))
    if "conditions générales" not in pg.inner_text("#cartm"): bad("case CGV non exigée")
    print("  lien CGV :", pg.get_attribute("#cgvl", "href"), pg.get_attribute("#cgvl", "target"))
    pg.check("#ccgv"); pg.click("#carts"); pg.wait_for_timeout(600)
    print("  envoi panier :", pg.inner_text("#cartok h3"), "| données :", (form_posts[-1] or "")[:220])
    if '"CGV":"accept' not in (form_posts[-1] or ""): bad("acceptation des CGV absente de l’e-mail")
    print("  badge après envoi caché :", pg.evaluate("document.getElementById('cartn').hidden"))
    pg.click("#cartok .ok-act .btn.ghost")
    pg.goto(SITE + "/boutique/?b=stockage"); pg.wait_for_timeout(200)
    pg.click('[data-f="cle"] .cf-tog'); pg.click('[data-f="cle"] [data-addcart]'); pg.click("#cartgo"); pg.check('input[value="domicile"]')
    pg.type("#cadr", "1 route de Segonzac", delay=20); pg.wait_for_timeout(2500)
    sg = pg.eval_on_selector_all("#sg-ca .sg span", "e=>e.map(x=>x.textContent)")
    if sg:
        pg.click(f"#sg-ca .sg >> nth={next((k for k, t in enumerate(sg) if 'Saint-Fort' in t), 0)}"); pg.wait_for_timeout(150)
    print("livraison (site en ligne) :", pg.inner_text("#cartliv").replace("\n", " "), "| commune :", pg.input_value("#ccom"))
    if "Plus que 40" not in pg.inner_text("#cartliv"): bad("message de livraison offerte absent")
    pg.click("#cartx")
    pg.goto(SITE + "/rendez-vous/?app=T%C3%A9l%C3%A9phone&besoin=R%C3%A9parer+ou+d%C3%A9panner&detail=%C3%89cran+cass%C3%A9"); pg.wait_for_timeout(200)
    img = (DIST / "assets" / "og-image.jpg").read_bytes()
    pg.set_input_files("#att-rdv", [{"name": "ecran.jpg", "mimeType": "image/jpeg", "buffer": img}, {"name": "panne.mp4", "mimeType": "video/mp4", "buffer": b"\0" * 300000}]); pg.wait_for_timeout(1200)
    print("pièces jointes :", pg.eval_on_selector_all(".att-l li b", "e=>e.map(x=>x.textContent)"))
    pg.click("#nx"); pg.fill("#fn", "Client Photo"); pg.fill("#ft", "0612345678"); pg.fill("#fcm", "Cognac"); pg.click("#ml"); pg.wait_for_timeout(1500)
    ok = pg.query_selector("#wiz h3")
    print("envoi avec fichiers :", pg.inner_text("#wiz h3") if ok else pg.inner_text("#fm"))
    if not ok: bad("envoi avec fichiers non confirmé alors que FormSubmit a renvoyé vers envoye.html")
    if multi:
        ct, buf = multi[-1]
        print("  type :", ct.split(";")[0], "| taille :", len(buf) // 1024, "Ko | fichiers :", buf.count(b'filename="'), "| captcha désactivé :", b'name="_captcha"' in buf, "| suite :", b"envoye.html" in buf)
        if buf.count(b'filename="') != 2: bad("pièces jointes manquantes dans l’envoi")
    else:
        bad("aucun envoi avec pièces jointes")
    pg.goto(SITE + "/rendez-vous/?besoin=Vendre+mon+appareil"); pg.wait_for_timeout(200)
    print("rachat → besoin pré-choisi :", pg.eval_on_selector('.opt[aria-pressed="true"][data-k="n"]', "e=>e.textContent"))
    pg.goto(SITE + "/communes/cognac/"); pg.wait_for_timeout(200)
    pg.screenshot(path=str(SHOTS / "cognac.png"), full_page=True)
    pg.click("#cBody a.btn"); pg.wait_for_load_state(); pg.wait_for_timeout(200)
    pg.click(".opt >> nth=0"); pg.click(".opt >> nth=4"); pg.click("#nx"); pg.click("#nx"); pg.wait_for_timeout(100)
    print("Cognac → rendez-vous, commune pré-remplie :", pg.input_value("#fcm"), "|", pg.inner_text("#fcmi"))

    # Nouvelles pages : menu Infos, conseils, actualités, FAQ, professionnels
    pg.goto(SITE + "/"); pg.hover("button[aria-controls=dd-infos]"); pg.wait_for_timeout(300)
    pg.click("#dd-infos a.mi >> nth=1"); pg.wait_for_load_state()
    print("menu Infos → ", pg.url, "| conseils :", pg.eval_on_selector_all("#arts .art-card", "e=>e.length"), "| case actualité :", pg.inner_text("#hubMini b")[:50])
    if not pg.url.endswith("/infos-pratiques/"): bad("menu → Infos pratiques")
    pg.click("#arts .art-card >> nth=1"); pg.wait_for_load_state()
    print("  article :", pg.url, "|", pg.inner_text("h1"), "| sources :", pg.eval_on_selector_all("#aBody .art-src a", "e=>e.map(x=>x.hostname)"), "| à lire aussi :", pg.eval_on_selector_all("#aMore .art-card", "e=>e.length"))
    pg.click(".pn-a.nx"); pg.wait_for_load_state(); pg.wait_for_timeout(150)
    print("  article suivant →", pg.url)
    pg.goto(SITE + "/infos-pratiques/"); pg.click("#arts .art-card >> nth=2"); pg.wait_for_load_state(); pg.click("#aHead .goback"); pg.wait_for_load_state(); pg.wait_for_timeout(150)
    print("  bouton retour →", pg.url)
    if not pg.url.endswith("/infos-pratiques/"): bad("bouton retour de l’article")
    pg.goto(SITE + "/communes/jarnac/"); print("  retour commune :", pg.get_attribute("#cHead .goback", "href"))
    pg.goto(SITE + "/infos-pratiques/telephone-tombe-dans-l-eau/")
    pg.click("#aSide .btn >> nth=0"); pg.wait_for_load_state()
    print("  bouton service de l’article → ", pg.url)
    pg.goto(SITE + "/actualites/"); pg.wait_for_timeout(200)
    n_all = pg.eval_on_selector_all("#news .nw", "e=>e.length")
    pg.click('#nseg [data-n="France"]'); n_fr = pg.eval_on_selector_all("#news .nw", "e=>e.length")
    pg.click('#nseg [data-n="Sécurité"]'); n_sec = pg.eval_on_selector_all("#news .nw", "e=>e.length")
    hrefs_n = pg.eval_on_selector_all("#news a", "e=>e.map(x=>x.getAttribute('href'))")
    print("actualités : tout", n_all, "| France", n_fr, "| sécurité", n_sec, "| filtres :", pg.eval_on_selector_all("#nseg button", "e=>e.map(x=>x.textContent)"), "| liens en # :", [h for h in hrefs_n if h.startswith("#")])
    if n_all < 15: bad("pas assez d’actualités")
    if any(h.startswith("#") for h in hrefs_n): bad("lien en # dans les actualités après filtre")
    pg.click('#nseg [data-n="Tout"]'); pg.click("#news .nw-a >> nth=0"); pg.wait_for_load_state(); pg.wait_for_timeout(200)
    print("clic sur une actualité →", pg.url, "|", pg.inner_text("h1")[:60], "| sources :", pg.eval_on_selector_all("#nBody .art-src a", "e=>e.length"), "| autres :", pg.eval_on_selector_all("#nMore .hub-c", "e=>e.length"))
    if "/actualites/" not in pg.url or pg.url.endswith("/actualites/"): bad("actualité non cliquable")
    pg.goto(SITE + "/"); pg.click("#hnl a >> nth=1"); pg.wait_for_load_state()
    print("accueil → actualité :", pg.url)
    if pg.url.endswith("/actualites/") or "/actualites/" not in pg.url: bad("actualité de l’accueil non cliquable")
    pg.goto(SITE + "/faq/"); pg.fill("#faqq", "livraison"); pg.wait_for_timeout(100)
    print("FAQ « livraison » :", pg.inner_text("#faqn"), "| groupes visibles :", pg.eval_on_selector_all(".faq-g:not([hidden]) h2", "e=>e.map(x=>x.textContent)"))
    pg.fill("#faqq", "zzzz"); print("  FAQ sans résultat :", pg.inner_text("#faqn")[:40])
    pg.goto(SITE + "/professionnels/"); pg.wait_for_timeout(200)
    pg.click('#pro-reseau [data-plan]'); pg.wait_for_timeout(700)
    print("pros : prestation choisie →", pg.input_value("#pn-plan"), "| focus :", pg.evaluate("document.activeElement.id"))
    if pg.input_value("#pn-plan") != "Wi-Fi ou réseau entre bâtiments": bad("bouton de prestation non relié au formulaire pro")
    pg.click("#pn-s"); print("  sans champs :", pg.inner_text("#pn-m"))
    pg.fill("#pn-ent", "Domaine Test"); pg.fill("#pn-nom", "Jean Test"); pg.fill("#pn-tel", "0600000000"); pg.select_option("#pn-postes", "4 à 8"); pg.click("#pn-s"); pg.wait_for_timeout(500)
    print("  envoi :", pg.inner_text("#pn-ok h3"), "| données :", (form_posts[-1] or "")[:200])
    if '"Prestation":"Wi-Fi ou r' not in (form_posts[-1] or ""): bad("prestation absente de l’e-mail pro")
    # menu Pros → ancre de la prestation, depuis une autre page
    pg.goto(SITE + "/contact/"); pg.hover("button[aria-controls=dd-pro]"); pg.wait_for_timeout(300); pg.click("#dd-pro a.mi >> nth=1"); pg.wait_for_load_state(); pg.wait_for_timeout(300)
    top = pg.evaluate("Math.round(document.getElementById('pro-reseau').getBoundingClientRect().top)")
    print("menu Pros → Wi-Fi :", pg.url, "| position :", top)
    if not pg.url.endswith("/professionnels/#pro-reseau") or not (-20 <= top < 200): bad("ancre Wi-Fi du menu Pros")  # quelques pixels d’écart selon le chargement des polices
    # fil d'actualités de l'accueil
    pg.goto(SITE + "/"); t1 = pg.inner_text("#ntt"); pg.wait_for_timeout(5600); t2 = pg.inner_text("#ntt")
    print("fil d’actualités :", t1[:50], "→", t2[:50], "| lien :", pg.get_attribute("#nt", "href"))
    if t1 == t2: bad("le fil d’actualités ne défile pas")
    print("dernières actualités (accueil) :", pg.eval_on_selector_all("#hnl li", "e=>e.length"))
    # date de rappel : jamais dans le passé
    pg.goto(SITE + "/rendez-vous/"); pg.click(".opt >> nth=0"); pg.click(".opt >> nth=4"); pg.click("#nx"); pg.click("#nx"); pg.wait_for_timeout(100)
    print("date de rappel minimale :", pg.get_attribute("#fd", "min"), "| créneaux :", pg.eval_on_selector_all("#fc option", "e=>e.map(x=>x.textContent)"))
    pg.fill("#fd", "2020-01-01"); pg.wait_for_timeout(100)
    print("date passée saisie →", pg.input_value("#fd"))
    if pg.input_value("#fd") < pg.get_attribute("#fd", "min"): bad("date de rappel passée acceptée")
    pg.screenshot(path=str(SHOTS / "pros-ok.png"))
    pg.goto(SITE + "/cgv/"); print("CGV : articles", pg.eval_on_selector_all(".cgv h2", "e=>e.length"), "| médiateur :", pg.inner_text("#cgv-med")[:40])

    pg.goto(SITE + "/zone-intervention/"); pg.wait_for_timeout(300)
    pg.type("#zq", "Jarnac", delay=40); pg.wait_for_timeout(600)
    pg.click("#sg-z .sg >> nth=0"); pg.wait_for_timeout(300)
    print("zone Jarnac :", pg.inner_text("#zr")[:90].replace("\n", " "), "| légende carte :", pg.inner_text("#za"))
    pg.fill("#zq", ""); pg.type("#zq", "chez rob", delay=40); pg.wait_for_timeout(1800)
    print("  lieux-dits « chez rob » :", pg.eval_on_selector_all("#sg-z .sg span", "e=>e.slice(0,3).map(x=>x.textContent)"))
    pg.screenshot(path=str(SHOTS / "zone.png"))

    pg.goto(SITE + "/communes/"); pg.fill("#cfilter", "16200"); pg.wait_for_timeout(100)
    print("communes filtre 16200 :", pg.inner_text("#ccount"))

    pg.goto(SITE + "/contact/")
    print("itinéraire :", pg.get_attribute("[data-act=map]", "href")[:80])
    pg.click("#tg"); pg.goto(SITE + "/mentions-legales/")
    print("thème conservé d’une page à l’autre :", pg.evaluate("document.documentElement.dataset.gt"))
    pg.click("#cb"); pg.fill("#ci", "vous vendez des chargeurs ?"); pg.press("#ci", "Enter"); pg.wait_for_timeout(500)
    pg.click("#cq button.act"); pg.wait_for_load_state()
    print("assistant → ", pg.url)

    r = pg.goto(SITE + "/une-page-qui-nexiste-pas/")
    print("404 :", r.status, "|", pg.inner_text("h1"), "| robots :", pg.get_attribute('meta[name=robots]', "content"))
    pg.screenshot(path=str(SHOTS / "404.png"))
    ctx.close()

    ctx = b.new_context(service_workers="block", viewport={"width": 390, "height": 844}, locale="fr-FR", has_touch=True, is_mobile=True)
    ctx.add_init_script("try{if(!localStorage.getItem('gt-consent'))localStorage.setItem('gt-consent',JSON.stringify({v:'non',t:Date.now()}))}catch(e){}"); ctx.route("**/*", serve)
    pg = ctx.new_page()
    pg.goto(SITE + "/services/telephones/"); pg.wait_for_timeout(200)
    pg.screenshot(path=str(SHOTS / "mobile-telephones.png"))
    pg.click("#burger"); pg.wait_for_timeout(300)
    pg.screenshot(path=str(SHOTS / "mobile-menu.png"))
    pg.click("button[aria-controls=dd-contact]"); pg.wait_for_timeout(200); pg.click("#dd-contact a.mi >> nth=4"); pg.wait_for_load_state()
    print("mobile menu → ", pg.url)
    if not pg.url.endswith("/contact/"): bad("menu mobile Contact")
    pg.goto(SITE + "/professionnels/"); pg.wait_for_timeout(200); pg.screenshot(path=str(SHOTS / "mobile-pros.png"), full_page=True)
    pg.goto(SITE + "/infos-pratiques/telephone-tombe-dans-l-eau/"); pg.wait_for_timeout(200); pg.screenshot(path=str(SHOTS / "mobile-article.png"), full_page=True)
    ctx.close()
    b.close()

print("hôtes externes demandés :", sorted(external))
print("PROBLÈMES :", len(problems))
for x in problems:
    print(" -", x)

import sys
sys.exit(1 if problems else 0)
