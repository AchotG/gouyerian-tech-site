# Site Gouyerian Tech : découpe en pages statiques et référencement (mis à jour le 2 nov. 2026)

## Source unique
- Aperçu publié : https://claude.ai/artifact/N8VVo3rxStGMYbLexxwQ7U (fichier unique, navigation par #).
- Le script du site a un « mode en ligne » : `window.GT_PAGE={view, slug?, cat?}` fixe la page affichée, et les liens en # sont réécrits en vraies adresses (`link()`, `fixLinks()`, table `ROUTES`).

## Découpe (build.py, dans ce dossier)
1. Extrait du fichier de l'aperçu : la CSS (vers assets/site.css), le JS (vers assets/app.js) et chaque vue (`<div class="view" id="v-…">`). Les deux fichiers sont appelés avec `?v=<empreinte>` pour qu'une mise à jour soit vue tout de suite.
2. Génère 46 pages : accueil, /services/ et 4 catégories, /boutique/, /rendez-vous/, /zone-intervention/, /communes/ et les 20 villes principales (CPG), /professionnels/, /faq/, /infos-pratiques/ (ex-/conseils/) et 7 articles (/conseils/<slug>/, données `ARTS`), /actualites/ (données `NEWS`), /contact/, /cgv/, /mentions-legales/, /hors-ligne/, 404.html.
3. Pré-rend chaque page dans Chromium (Playwright). Le contenu est donc dans le HTML, puis il est nettoyé : statut d'ouverture, tuiles de la carte, diaporama, bandeau de localisation, boutons d'installation.
4. Écrit sitemap.xml, robots.txt, site.webmanifest (standalone, icône maskable, raccourcis), sw.js (service worker), _headers (Netlify et Cloudflare) et .htaccess (Apache).

Tests : test_dist.py (toutes les pages en ordinateur et mobile, liens, JSON-LD, panier avec case CGV, formulaires, FAQ, actualités, pros) et test_pwa.py (service worker et hors connexion sur un serveur local).

Le script a besoin de quelques fichiers, à recréer s'ils manquent :
- logo.b64 et ban.b64 : on les obtient en lisant assets/logo.png et assets/adresses.js de l'aperçu.
- Les polices : clone sparse de fontsource/font-files sur GitHub (Archivo variable wdth, Cinzel 600/700, IBM Plex Sans 400/500/600, IBM Plex Mono 400/500, latin et latin-ext).
- gen_images.py : icônes (dont icon-maskable-512.png), logo carré, image de partage.

## Contenus ajoutés le 2 nov. 2026
- CGV en 15 articles (EI, micro-entrepreneur, TVA non applicable art. 293 B du CGI). Case « J'accepte les CGV » obligatoire dans le panier ; l'e-mail porte « CGV : acceptées ».
- Garantie corrigée partout : neuf ET reconditionné = garantie légale de conformité de 2 ans (présomption 12 mois pour le reconditionné) ; en plus, garantie Gouyerian Tech de 6 à 12 mois sur le reconditionné et de 3 à 12 mois sur les réparations.
- Page Professionnels (3 nov.) : seulement 3 prestations, sur demande d'Achot : prévention et nettoyage des virus (30 € par poste), Wi-Fi et réseau Wi-Fi/Ethernet entre bâtiments ou bureaux (sur devis), problèmes informatiques courants (sur devis). Plus de forfaits mensuels. Formulaire « Devis pro » avec le champ Prestation. Ancres #pro-virus, #pro-reseau, #pro-depannage, #pro-devis (ROUTES et ALIAS).
- FAQ dédiée (24 questions, 6 groupes, recherche).
- Actualités : 16 résumés vérifiés à leur source, 8 thèmes (snapshot du 3 nov. 2026, à rafraîchir 1 à 2 fois par mois). Règle d'Achot : écarter les sujets qui desservent l'activité (auto-réparation, programmes de réparation des fabricants, bonus réparation réservé aux labellisés, critiques du reconditionné, promotions de concurrents).
- Accueil (3 nov.) : fil d'actualités défilant en haut du diaporama, 6 diapositives dont une « Professionnels », accès directs sur la 1re, trois portes d'entrée (réparation, boutique, pros), bandeau pros, dernières actualités.
- Menu (3 nov.) : Services et tarifs, Boutique, Pros, « Actus et infos » (libellé complet « Actualités et infos pratiques » dans le menu mobile), Contact (rendez-vous, atelier, zone, communes) ; accès rapides Rendez-vous et Appeler en tête du menu mobile.
- Contrôle de cohérence à chaque modification (demande d'Achot) : prix, garanties, CGV, FAQ, assistant, formulaires, panier, liens.
- Application installable (PWA) : bouton « Installer l'application », aide iPhone, pages vues relisibles hors connexion.

## Choix de référencement
- Domaine prévu : https://gouyerian-tech.fr. Chaque page a son URL canonique, sa balise title (70 caractères au plus) et sa description.
- Données JSON-LD :
  - ComputerStore (@id #entreprise) sur l'accueil et le contact : adresse, géolocalisation du 1 route de Segonzac (45.5749, -0.30094), horaires, SIRET, tarifs fixes, zone de 40 km et 20 villes. Pas d'aggregateRating.
  - Service sur les pages de catégorie, de villes et /professionnels/ (3 prestations).
  - FAQPage sur /faq/ ; ItemList sur /infos-pratiques/ ; Article sur chaque info pratique.
  - BreadcrumbList partout.
- Polices hébergées sur le site, aucun appel à Google Fonts.
- Permissions-Policy : geolocation=(self), nécessaire au bouton « Utiliser ma position actuelle ».

## À faire par Achot après la mise en ligne
- Compléter l'hébergeur dans les mentions légales.
- Choisir un médiateur de la consommation et le donner pour l'article 13 des CGV.
- Faire relire les CGV (CMA, CCI ou juriste).
- Activer FormSubmit : au premier envoi, un e-mail de confirmation arrive à gouyerian.tech@gmail.com.
- Search Console : vérifier le domaine et envoyer le sitemap.
- Ajouter l'URL du site sur la fiche Google.

## Illustrations (3 nov.)
- 7 nov. (suite) : illustrations redessinées avec les nouveaux éléments : arnaques-en-ligne (hameçon qui attrape une carte bancaire), batterie-smartphone (chaud, froid, charge à 80 %), cat-consoles (écran, manette générique, HDMI ; plus de console ressemblant à un modèle réel). Chaque adresse d'image porte une empreinte `?v=` (build.py `IMGV`, `window.GT_IMGV` dans app.js, `imgv()` dans picHTML) : une image redessinée est vue tout de suite malgré le cache de 30 jours. GitHub contrôle les dessins (`gen_illus.py --controle`) avant de construire, et build.py s'arrête si une info pratique n'a pas d'illustration.
- 7 nov. : gen_illus.py amélioré : 24 éléments de dessin ajoutés (arrow, link, calendar, padlock, envelope, bankcard, gift, cart, pricetag, magnifier, warning, snowflake, thermometer, chip, router, gamepad, headphones, usbkey, clock, hourglass, house, screenlines, badge) ; contrôle automatique de chaque dessin (élément coupé au bord, texte qui chevauche ou trop petit, dessin trop petit ou décentré ; code de sortie 1) ; options `nom…`, `--controle`, `--planche` (grand + vignette téléphone + partage), `--catalogue` (tous les éléments), `--liste`, `--aide`. Sorties de contrôle dans outils/img/ill-src/ (non versionné). Les images existantes sont inchangées.
- gen_illus.py dessine 14 illustrations originales en SVG (fond bleu nuit, traits blancs et cyan), exportées en WebP 640/1280 px (~5 à 20 Ko) et en JPEG 1200×630 pour le partage des 7 articles.
- Affichage : `picHTML()` (srcset, sizes, width/height, loading="lazy", decoding="async") ; `[data-pic]` rempli au chargement ; seule l'image d'en-tête d'article est prioritaire.
- build.py : copie dans assets/img/, og:image par article, images dans le sitemap, cache 30 jours.
- Des photos réelles (atelier, réparations) pourront remplacer ou compléter ces visuels.
- Localisation (3 nov.) : le bouton « Utiliser ma position actuelle » n'est proposé que s'il peut marcher (masqué dans l'aperçu, si le navigateur a refusé, ou après un échec pendant la visite) ; en cas d'échec, aucun message d'erreur, juste « tapez simplement votre commune » et le curseur dans le champ.
- Panier (3 nov.) : jusqu'à 100 exemplaires d'un même article (QMAX), quantité saisissable au clavier ; pastille « 99+ ».
- Rendez-vous (3 nov.) : une date de rappel passée est remplacée par le premier jour possible, même sur iPhone qui ignore la date minimale du calendrier.
- Dimanche (3 nov.) : rappel uniquement entre 9h et 13h (créneau unique, « Peu importe (9h-13h le dimanche) », e-mail « Dimanche, entre 9h et 13h ») ; après 13h le dimanche, premier jour possible = lundi.

## Actualités automatiques (3 nov.)
- Deux tâches programmées mettent à jour les actualités DANS L'APERÇU (artefact, slug ab1bae07-fef2-464a-9555-3ac0b02136b3) : « Actualités tech du site Gouyerian Tech (toutes les 5 h) » (jusqu'à 4 actualités) et « Alertes urgentes du site Gouyerian Tech (toutes les heures) » (urg:1, épinglées 3 jours avec un badge « Alerte »).
- Elles insèrent les entrées après `const NEWS=[` et mettent à jour `const NEWS_UPD`. 40 actualités au plus. Filtre : sujets qui desservent l'activité écartés.
- IMPORTANT avant toute modification locale du site : relire l'artefact (read_artifact) et reprendre son tableau NEWS, sinon on écrase les actualités ajoutées automatiquement. Le zip (vrai site) n'est à jour qu'à la dernière reconstruction : à brancher sur GitHub + Netlify quand le site sera en ligne.
- Hébergeur (3 nov.) : IONOS (IONOS SARL, 7 place de la Gare, BP 70109, 57201 Sarreguemines Cedex, 09 70 80 89 11) indiqué dans les mentions légales ; LISEZMOI réécrit pour une mise en ligne chez IONOS (SFTP/FileZilla, SSL inclus, .htaccess). Pour les actualités automatiques sur le vrai site : piste IONOS Deploy Now + GitHub.
- Page /infos-pratiques/ (3 nov.) : recentrée sur les infos pratiques (h1 « Infos pratiques ») ; une seule petite case discrète montre l'actualité la plus récente ou l'alerte en cours (#hubMini). Avant de publier, l'aperçu a été resynchronisé avec les actualités ajoutées par les tâches programmées.
- Boutons retour (3 nov.) : pastille « ‹ Infos pratiques / Actualités / Communes desservies » en haut des articles, actualités et pages de ville (backTo, data-back : revient en arrière avec filtres et position si la page d'avant est la page parente, sinon ouvre la page parente) ; navigation précédent / suivant en fin d'article et d'actualité (pnHTML) ; bouton « haut de page » (#totop) sur les pages longues. Panier et rendez-vous avaient déjà leurs boutons Retour.

## v88 : livraison du panier selon la commune du client (3 novembre 2026)
- Commune mémorisée : avec accord, localStorage `gt-loc` 6 mois ; sans accord, sessionStorage `gt-loc` (durée de la visite) pour le panier et les formulaires. `rememberLoc` enregistre désormais toujours (local ou session) ; si l'accord est donné après coup, la commune de la visite passe en localStorage. `forgetLoc` vide les deux.
- Toute commune ou adresse choisie (bandeau « Votre commune », formulaire de rendez-vous, adresse ou commune du panier) remplace la commune mémorisée.
- `livCond(km)` donne les conditions d'une distance : frais, seuil de livraison offerte, ou sur devis (> 40 km).
- Panier étape 1 : `freeBar` affiche seulement le palier du client (`livHome`) : ex. Segonzac 8 km → « Plus que X € … offerte chez vous à Segonzac », sinon 10 €. Le seuil 80 € / 40 km n'apparaît plus pour un client proche. La distance montrée est gardée dans `CB`.
- Étape 2 : `bandNote(f)` compare l'adresse de livraison à `CB`. Plus loin et conditions différentes → message orange « Finalement, l'adresse de livraison est à N km, au-delà de 10 km : la livraison passe à 20 € (au lieu de 10 €)… » ; plus près → message vert « Bonne nouvelle ». Retrait à l'atelier : « Envie d'être livré à <commune> ? … ».
- Confidentialité (mentions légales) : phrase ajoutée sur la mémoire de visite sans accord.

## v89 : accueil compact sur téléphone (3 novembre 2026)
- Mesure iPhone 13 (390 × 664) : accueil 19,8 écrans → 9,3 écrans pied de page compris. Ordinateur inchangé (tout est dans `@media (max-width:640px)`).
- Choix du client : pastilles d'accès rapide sous l'en-tête (`.qchips` : Réparation, Boutique, Actualités, Infos pratiques, Professionnels, Zone, FAQ) ; haut de page fixe (diaporama coupé sur téléphone : `MQM` dans le script du diaporama, seule la 1re diapo, sans défilement auto ni glissement) ; 3 grands blocs Réparation / Vente / Pros masqués (`.h-pills`). Carte radar de la zone conservée.
- Aussi : garanties 2 × 2, appareils en liste (icône `.card-mi`, sans illustration ni tarifs), étapes sans texte, bandeau pros en 3 lignes + 1 bouton, textes d'intro des sections masqués (`.m-hide`, `.sec-h p`), infos pratiques en cartes à faire glisser (`.h-swipe`), 3 titres d'actualité.
- Pied de page : sur téléphone, Réparation / Boutique / Informations / Secteur se replient (boutons `.fa-t` créés par le script, retirés sur ordinateur ; contact toujours ouvert). Pré-rendu à 1366 px : le HTML garde les titres simples.
- Correctif v88 : sans accord, la commune de la visite (sessionStorage) ne sert qu'au panier via `cartLoc()` ; `locNow()` redevient réservé à l'accord (sinon la page Zone et le rendez-vous se pré-remplissaient). Tests du site : PROBLÈMES 0.

## v90 : croix sur le bandeau de localisation (3 novembre 2026)
- Bouton `#ckx` « Fermer sans choisir » : aucun accord enregistré, `gt-ck-x` (horodatage, localStorage, sinon sessionStorage) ; le bandeau ne revient plus tout seul pendant 6 mois (`ckClosed()`).
- Si le client indique ensuite une commune sans accord (`saveLoc` → `ckLater`), le bandeau est reproposé une seule fois par visite, à la page suivante, avec le titre « Garder <commune> en mémoire ? » (`ckPending`, au chargement et au changement de vue dans l'aperçu). « Accepter » garde alors cette commune 6 mois, sans rouvrir la fenêtre de commune.
- « Localisation et cookies » (pied de page) rouvre toujours le bandeau avec son titre normal. Phrase ajoutée dans les mentions légales.

## v91 : horaires du jour dans le bandeau du haut (3 novembre 2026)
- `openState()` : « Ouvert · 9h–20h » (dimanche « Ouvert · 9h–13h »), « Fermé · ouvre à 9h », « Fermé · ouvre demain à 9h » (« demain » masqué sous 480 px). Les heures restent visibles sur téléphone ; « · sur rendez-vous » seulement sur grand écran. Infobulle avec les horaires de la semaine.
- Sur téléphone, le statut ne se coupe jamais ; c'est le bouton de commune qui se raccourcit (testé à 360 et 390 px, avec « Barbezieux-Saint-Hilaire »).

## v92 : audit complet du site et corrections (3 novembre 2026)
- Audit : 73 pages × (téléphone 390 px, ordinateur 1366 px) avec un script maison (erreurs JS, ressources, débordements, h1, identifiants, noms accessibles, étiquettes, cibles tactiles, ordre des titres, contraste, JSON-LD, liens internes), cohérence des textes (prix, horaires, garanties, paiement, CGV), parcours (test_dist : PROBLÈMES 0), 23 questions à l'assistant. Scripts : scratchpad/audit/audit.js, coh.py, bot.js, tel.js.
- Actualités : synchronisées avec l'artefact (27) avant toute modification. Les tâches programmées plafonnent déjà le tableau à 40.
- Corrigé : assistant (garantie du reconditionné, « rachetez », « entreprise » pris pour « reprise », acompte, téléphone dans l'eau, WhatsApp, suivi, marques/Mac, « Bonjour, … », « vous êtes où », commune citée → distance, déplacement et livraison) ; téléphone vérifié (`telOk`, `TELMSG`) dans rendez-vous, panier et devis pro ; champ piège `.hp` dans les formulaires (envoi ignoré s'il est rempli) ; contrastes AA en thème clair (`--acc2:#0e7490`, `--acc4:#c2410c`, texte sur fond teinté en `--acc-h`) : 105 alertes → 1 (bouton désactivé, exempté) ; titres du pied de page en `h2.fh` ; cibles tactiles du pied de page et du bandeau ; quantité du panier en 16 px (zoom iPhone) ; actualité Windows 11 24H2 (fin du support le 13 octobre, source BleepingComputer ajoutée) ; mention de provenance des avis ; formulaire type de rétractation dans les CGV (version du 3 novembre 2026) ; titres ≤ 65 caractères et descriptions ≤ 160 (build.py).
- Restent à décider (proposés au client) : liste des actualités plus courte sur téléphone (23 écrans), page Communes groupée par distance (36 écrans), dates des avis, médiateur, alias FormSubmit, photos réelles, prix « à partir de » pour écrans et batteries.

## v93 : actualités 10 par 10, communes par distance, double vérification (3 novembre 2026)
- Actualités : 10 cartes puis « Voir plus d’actualités (N de plus) » (`NPAGE`, `nshow`, mémorisé pendant la visite dans sessionStorage `gt-nshow` avec le thème) ; les cartes suivantes restent dans la page (attribut hidden) pour Google. Sur téléphone, cartes courtes : titre et 2 lignes, sans conseil ni sources (sur la page de l'actualité). Téléphone : 23 → 5 écrans ; ordinateur : 13 → 6.
- Communes : 3 rubriques repliables `details.cg` (jusqu’à 10 km · 28 ; 10 à 20 km · 75 ; 20 à 40 km · 261) avec déplacement et seuil de livraison offerte calculés par `livCond` ; la recherche ouvre les rubriques concernées ; 4 colonnes sur ordinateur, 2 sur téléphone (code postal masqué). Téléphone : 36 → 5 écrans.
- Tâches programmées : prompts des tâches « 5 h » (trig_012w3een8bb8XuHZza9EK7kW) et « alertes » (trig_01454PLRJMKs4ho5zLiFxdDr) réécrits avec double vérification (source principale + source indépendante ou officielle, relecture fait par fait, contrôle des contradictions, rien publié en cas de doute, 2 sources dans src). Nouvelle tâche « Contrôle quotidien des actualités » (trig_01XCLHtN5XikRnyXZvydWmjd, 5 h 43 heure de Paris) : revérifie jusqu'à 12 actualités récentes ou datées, corrige ou supprime, n'ajoute rien.
- Dates des avis : en attente (fiche Google illisible depuis la session, aucun navigateur connecté) — captures demandées au client.

## v94 : avis datés (3 novembre 2026)
- Relevé de la fiche Google (vidéo envoyée par Achot, tri « Les plus récents ») : `REVIEWS` = [nom, texte, mois de la visite], classés du plus récent au plus ancien. Visites : juillet 2026 (Marie Claire H.), mai 2026 (Mélina C.), mars 2026 (Jean Philippe M., Kaïs D.), décembre 2025 (Noémia P., Adèle M., Noemie R., Cédric R.), novembre 2025 (Robert R.), octobre 2025 (Megrier T.), avril 2025 (Quentin L., Armindo P., Mickael P. R.), mars 2025 (ChenZen). L'avis B Informatique reste exclu à sa demande.
- Carte : « Avis Google / visite en <mois année> ». Mention sous le fil : sélection, non vérifiés par l'atelier, du plus récent au plus ancien, date = visite telle qu'affichée par Google, date de publication visible sur la fiche. (Google n'affiche la publication qu'en relatif, « il y a 3 mois », d'où le choix de la date de visite, exacte au mois.)
- Pour un nouvel avis : ajouter une ligne en tête de `REVIEWS` avec son mois de visite.

## v95 : carrousel d'avis (3 novembre 2026)
- L'ancien défilement CSS continu (animation de 140 s, cartes en double) ne se laissait pas glisser au doigt, et sur téléphone un toucher le figeait (survol « collé »). Remplacé par un vrai carrousel : conteneur `#rev` défilant avec aimantation (scroll-snap), cartes une seule fois, boutons `#rprev`/`#rnext`, flèches du clavier, avance auto toutes les 5 s (pause au survol, 10 s après tout geste, hors écran, onglet caché, mouvement réduit), retour au début après la dernière carte.

## v96 : avis en défilement continu (3 novembre 2026)
- Demande d'Achot : défilement automatique continu, sans bug. Le carrousel `#rev` avance en douceur (~35 px/s, requestAnimationFrame, position gardée en nombre décimal puis arrondie, pour iPhone) ; 2e série de cartes cachée aux lecteurs d'écran (aria-hidden) pour boucler sans saut ; pause au toucher, au glissement, au survol et au focus, reprise 3 s après, là où le visiteur s'est arrêté ; arrêt hors écran (IntersectionObserver), onglet caché et mouvement réduit. Aimantation (scroll-snap) retirée car elle bloquait le mouvement doux. Boutons et flèches du clavier conservés.

## v97 : consoles de jeux (4 novembre 2026)
- Demande d'Achot : il répare les consoles (connecteur de charge Switch, port HDMI PS5/Xbox, etc.). Nouvelle catégorie « Consoles » partout :
  - `SV` : 6 prestations sur devis (connecteur de charge, port HDMI, joysticks et manettes, surchauffe et nettoyage, écran et batterie de console portable) ;
  - `DEVS["Consoles"]` (marques Nintendo, Sony, Microsoft) ; `CATS` ; icône `gamepad` ; couleur `--acc5` (#be185d clair, #f472b6 sombre) ;
  - page `/services/consoles/` (build.py `CATP`), carte d'accueil avec illustration `cat-consoles` (gen_illus.py), menu, pied de page ;
  - rendez-vous : 4e appareil « Console » (`DEV`, classe `.opts.four`) — les tests cliquent désormais `.opt >> nth=4` pour le premier besoin ;
  - assistant : intention `consoles` placée avant `accessoires` (« port HDMI de ma PS5 » ne part plus vers la boutique ; « switch réseau » exclu) ;
  - FAQ : « Réparez-vous les consoles de jeux ? » (25 questions) ; CGV art. 2 étendu, version du 4 novembre 2026 ; textes d'accueil, services, communes et données structurées.
- Accueil : 5 cartes sur ordinateur, 3 + 2 sur tablette, liste sur téléphone.
- Fiche Google (le même jour, dans Chrome) : nom « Gouyerian Tech » ; catégories secondaires « Service de réparation d'ordinateurs », « Atelier de réparation de téléphones mobiles », « Magasin de téléphonie mobile » (Google n'a pas de catégorie « réparation de consoles ») ; nouvelle description avec les consoles ; 11 novembre fermé. Tout est en attente de validation par Google. Le champ « site Web » pointe encore vers Facebook : le passer sur gouyerian-tech.fr à la mise en ligne.

## Mise en ligne (4 novembre 2026)
- Hébergement IONOS « Hébergement Web Plus » (contrat 300525065). Fichiers envoyés par WebTransfert (Webspace Explorer) : archive `site-ionos.zip` (contenu de dist, sans dossier autour) décompressée dans `/public` avec « Extraire les archives », puis l'archive rangée à la racine `/` (hors du site).
- Domaine gouyerian-tech.fr relié au dossier `/public` (Domaines & SSL, type d'utilisation « Espace Web »).
- Certificat SSL Starter Wildcard inclus dans le contrat, activé par Achot (conditions acceptées par lui). https fonctionne, http et www redirigent vers https://gouyerian-tech.fr/, la page 404 fonctionne, `.htaccess` est protégé (403), le sitemap a 79 adresses, le service worker est actif.
- Correctif après mise en ligne : bloc « Faire réparer » de l'accueil → « Téléphone, tablette, ordinateur, console » (seul index.html remplacé).
- Fiche Google : site Web passé de Facebook à https://gouyerian-tech.fr/ (Facebook reste dans les réseaux sociaux).
- Pour une mise à jour : reconstruire, comparer dist avec la version envoyée, et n'envoyer que les fichiers modifiés (WebTransfert, « écraser : Oui »), ou une archive complète à extraire dans `/public`.
- Attention : les tâches automatiques d'actualités modifient l'aperçu (artifact), pas le site en ligne. Les actualités du site IONOS ne changent que lors d'un nouvel envoi.

## Google Search Console (4 novembre 2026)
- Propriété « préfixe d'URL » https://gouyerian-tech.fr/ validée par fichier HTML `googlec2ee2d1354c69554.html` (à la racine du site, généré par build.py — ne jamais le supprimer).
- Sitemap `sitemap.xml` envoyé : accepté, 79 pages découvertes. Indexation de l'accueil demandée.
- Une propriété « domaine » gouyerian-tech.fr a aussi été créée mais n'est pas validée (il faudrait un enregistrement DNS TXT chez IONOS, que la session n'a pas pu enregistrer). Sans effet ; à valider plus tard si besoin, ou à laisser.

## Passage sur GitHub (7 novembre 2026)
- Dépôt public AchotG/gouyerian-tech-site : `source/gouyerian-tech.html` (tout le site), `outils/` (build.py, test_dist.py, gen_*.py, polices, images, données logo/adresses), `.github/workflows/publier.yml`.
- À chaque push sur main et chaque nuit à 3 h 17 UTC : construction (Playwright), tests (aucune publication s'il y a un problème), publication GitHub Pages. Domaine personnalisé gouyerian-tech.fr déclaré dans les réglages Pages.
- Piège corrigé : dans la construction et les tests, le service worker du site allait chercher l'ancien site en ligne (Internet ouvert sur GitHub) → `service_workers="block"`. Test de l'ancre Wi-Fi : tolérance de -20 px.
- Les 3 tâches d'actualités écrivent maintenant dans le dépôt (add_repo + clone + commit + push), puis recopient la modification dans l'aperçu claude.ai.
- Reste à faire par Achot chez IONOS (DNS) : A @ → 185.199.108.153 / .109 / .110 / .111 ; supprimer AAAA @ (IONOS) ; www → CNAME achotg.github.io (supprimer A/AAAA www). Garder MX/SPF/DKIM (e-mail). Puis cocher « Enforce HTTPS » dans GitHub Pages.

## Mode clair retravaillé (v98, 7 nov.)
- Jetons du mode clair seulement (bloc « v98 » en fin de CSS) : fond bleuté #f4f7fc, encre bleu nuit #0b1426, gris #4f5b6e, lignes #d9e1ec, ombre douce des cartes `--elev`, en-tête blanc translucide `--hdr`, boutons secondaires et boutons ronds sur fond blanc `--ghost`, halo de l'en-tête plus visible (`--hero-w1/w2/p2`), bande des avis `--band-bg`.
- Le mode sombre redéfinit chacun de ces jetons à l'identique de l'ancien rendu (vérifié par comparaison de captures : identique).

## Suivi des réparations (v99, 7 nov.)
- Page client `/suivi/` (vue `suivi` du site) : numéro (GT-AAMM-NNN) + code (6 caractères) → étapes, historique, messages de l'atelier. Le lien des e-mails `/suivi/#NUMERO.CODE` remplit le formulaire puis efface le code de la barre d'adresse. « Se souvenir de ce suivi » : localStorage `gt-suivi`.
- Serveur PHP `serveur/api/` (copié dans `_site/api/`) : `suivi.php` (public, 20 essais / 10 min / IP, désinscription des e-mails en 2 temps : lien puis bouton), `gestion.php` (atelier : connexion par mot de passe haché, jeton de session en base 30 jours, cookie HttpOnly Secure SameSite=Strict, en-tête X-GT obligatoire, 8 essais / 15 min), `_lib.php` (interdit en accès direct). Tables `gt_reparations`, `gt_historique`, `gt_essais`, `gt_sessions` créées automatiquement. Effacement automatique 24 mois après la fin.
- Page atelier `serveur/gestion/index.html` → `/gestion/` (noindex, exclue du sitemap et du service worker) : liste, recherche, création, étapes avec message et e-mail, bon de dépôt imprimable, lien de suivi à copier, test d'envoi d'e-mail.
- E-mails envoyés par `mail()` d'IONOS (expéditeur `suivi@gouyerian-tech.fr`, réponse vers gouyerian.tech@gmail.com), seulement si le client l'a demandé (case dans le formulaire de rendez-vous, recopiée par Achot à la création de la fiche).
- Configuration : secrets GitHub SUIVI_DB_HOST, SUIVI_DB_NAME, SUIVI_DB_USER, SUIVI_DB_PASSWORD, GESTION_MOT_DE_PASSE → le workflow écrit `/prive/gt-config.php` (hors du site) à chaque publication. Sans ces secrets, la page /suivi/ indique que le service n'est pas encore activé.
- Tests locaux : `php -S` + SQLite (`dsn` sqlite, `dev` true, `mail_log`) ; scénario complet vérifié (création, étapes, e-mails, désinscription, limites, déconnexion).

## Espace client (v101, 7 nov.)
- Page `/mon-espace/` (vue `compte`, noindex) et icône compte dans l'en-tête (pastille verte quand connecté). Connexion sans mot de passe : code à 6 chiffres par e-mail (15 min, 5 essais, 5 demandes / 15 min par adresse, 20 / h par IP), session 30 jours (cookie `gtcli` HttpOnly Secure SameSite=Strict, jeton haché dans `gt_csessions`).
- L'espace montre les réparations enregistrées avec l'adresse e-mail du client (en cours, terminées avec fin de garantie), les devis à accepter/refuser (aussi depuis la page /suivi/), le panier synchronisé entre appareils (`gt_paniers`), les coordonnées (préremplies dans le rendez-vous et le panier), la suppression de l'espace.
- Gestion : champ « Garantie après réparation » (mois), état du devis sur la fiche et dans la liste ; e-mail à gouyerian.tech@gmail.com quand un client accepte ou refuse un devis.
- Base : schéma versionné (`gt_meta`, SCHEMA_VERSION = 2) ; colonnes ajoutées à `gt_reparations` : devis_etat, devis_le, garantie_mois. API `serveur/api/compte.php`.

## Demandes centralisées (v102, 7 nov.)
- Tous les formulaires du site (rendez-vous, panier, devis pro, assistant) passent d'abord par `api/demande.php` : enregistrés dans `gt_demandes` (référence D-AAMM-NNN), pièces jointes dans `/prive/pj` (hors du site, 6 fichiers, 30 Mo chacun, photos/vidéos/PDF). Si le serveur ne répond pas, repli automatique sur FormSubmit (`sendFormFS`).
- Espace client : formulaire « Demander un devis » prérempli (avec photos) et liste « Mes demandes » (Reçue / En cours / Traitée).
- Gestion : onglets Demandes / Réparations ; « Réponses aux devis » (devis acceptés/refusés pas encore vus, colonne `devis_vu`) ; fiche demande (appeler, SMS, répondre par e-mail, pièces jointes, note interne, statuts, « Créer la réparation » préremplie qui rattache et clôt la demande) ; garantie libre en mois (0 à 120).
- E-mails à l'atelier : une ligne + lien vers /gestion/, réglage dans la gestion (`gt_meta` notif = chaque [défaut] / regroupe [au plus 1 toutes les 3 h] / jamais). Schéma v3.

## v103 : messagerie par réparation
- Table gt_messages (schéma v4) ; api/messages.php (client : numéro + code, ou espace client avec la même adresse e-mail ; texte + 4 fichiers).
- Gestion : carte « Nouveaux messages des clients », badge 💬 dans la liste, fil dans la fiche (pièces jointes ?mpj=), réponse avec e-mail court facultatif (mail_message).
- Le client voit « lu ✓ » côté atelier ; messages et fichiers effacés avec la fiche (supprimer_reparation, RGPD 24 mois).
- v103b : l'atelier peut joindre des fichiers (6 au plus) ; le client les ouvre depuis son suivi ou son espace (messages.php action « pj »).
  E-mail au client à chaque message de l'atelier dès qu'une adresse est connue (case cochée par défaut). Indicateurs « ✓ Distribué · e-mail envoyé » et « ✓✓ Lu le … » (colonnes mail et lu_le, schéma v5), aussi côté client.
- v104 : nouveau design de la messagerie (en-tête avec avatar, fond pointillé, bulles en dégradé, séparateurs de jour, aperçus des photos, barre d'envoi avec trombone et flèche, coches Distribué / Lu) sur le site et dans la gestion.
- v105 : encart « Nouveau · Espace client » sur l'accueil (suivi, messagerie, devis) avec maquette animée de la conversation, liens vers /mon-espace/ et /suivi/.
- v106 : messagerie complète. Conversations par réparation, par demande (d:REF) et générale par client (c), schéma v6 (gt_messages.demande_id / client_id).
  Espace client : section « Messages avec l'atelier » (liste) + fenêtre de discussion plein écran sur téléphone ; bouton « Écrire à l'atelier » dans l'assistant.
  Gestion : onglet « 💬 Messages » (toutes les conversations, non lus), vue conversation, bouton « Écrire au client » sur les demandes.
  Espace client réorganisé (résumé, réparations | devis et demandes, panier | coordonnées), demandes dépliables ; bandeau téléphone du bas masqué là où un encadré téléphone existe déjà.
  E-mails : en-tête intégré à l'e-mail (image cid), lisible même quand le webmail bloque les images distantes.
- v107 : conversations en direct (relues toutes les 6 s côté client et gestion), conversation plein écran sur téléphone dans la gestion, effacement des conversations et pièces jointes 3 mois après la fin (réparation rendue/annulée, demande traitée, 3 mois sans message pour la générale), entretien automatique quotidien.
- v108 : une demande devenue réparation garde sa conversation (messages déplacés dans la conversation de la réparation, schéma v7 pour les anciennes) ; plus de conversation en double.
- v109 : paiement en ligne SumUp (page hébergée). Table gt_paiements (schéma v8), api/paiement.php (voir, payer, notification ?sumup=), page /paiement/?t=JETON (noindex), boutons Payer dans le suivi, l'espace et la messagerie, panneau Paiement dans la fiche et dans chaque conversation de la gestion, e-mails demande/reçu, CGV art. 8. Secrets : SUMUP_API_KEY, SUMUP_MERCHANT_CODE.
