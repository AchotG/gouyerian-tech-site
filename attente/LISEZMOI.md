# Actualités programmées

`programmees.json` : actualités **déjà vérifiées** par la grande recherche du dimanche (tâche Claude hebdomadaire), en attente de leur heure de publication.

- Chaque entrée = une actualité au format du site (`d, dl, sc, th, tag, (urg), t, s, tip, src`) + `prevu` (« AAAA-MM-JJTHH:MM », heure de Paris = date de publication) + `ajoute_le` + `verif`.
- Le workflow GitHub « Publier les actualités programmées » (`.github/workflows/publier-actualites.yml`, **sans Claude, gratuit**) tourne chaque jour vers 8h et 16h (Paris) : il insère dans `source/gouyerian-tech.html` les entrées dont `prevu` est passé (script `outils/publier_programmees.py`), les retire du fichier, puis relance « Publier le site ».
- Objectif : 1 à 2 publications par jour, ≈ 10 à 14 par semaine. Limite du site : 70 actualités.
- Publier à la main : onglet Actions → « Publier les actualités programmées » → Run workflow.
- Ce dossier n'est pas publié sur le site.
