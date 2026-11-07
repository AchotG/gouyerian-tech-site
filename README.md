# gouyerian-tech.fr

Site de Gouyerian Tech, réparation et vente informatique à Saint-Fort-sur-le-Né (Charente).

- `source/gouyerian-tech.html` : tout le site dans un seul fichier (textes, styles, script, actualités dans `const NEWS=[…]`).
- `outils/build.py` : découpe ce fichier en 81 pages statiques pré-rendues, avec sitemap et données Google.
- `outils/test_dist.py` : teste chaque page ; si un problème est trouvé, rien n'est publié.
- `.github/workflows/publier.yml` : à chaque modification de `main` (et chaque nuit), construit, teste et publie sur GitHub Pages.

Les actualités sont ajoutées par une tâche automatique (vérification par deux sources indépendantes) qui modifie `source/gouyerian-tech.html` et pousse sur `main`.
