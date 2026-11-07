# File d'attente des actualités

`actualites.json` : actualités trouvées par les tâches de recherche (toutes les 10 h) et d'alertes (toutes les heures), **pas encore publiées**.

- Chaque entrée = une actualité au format du site (`d, dl, sc, th, tag, (urg), t, s, tip, src`) + `ajoute_le` (date et heure de Paris) + `par` (« recherche » ou « alerte ») + `verif` (ce que la tâche a vérifié).
- La tâche « Vérification et publication » (toutes les 2 h) revérifie chaque entrée, corrige ou supprime, puis insère les bonnes dans `source/gouyerian-tech.html` et vide la file. C'est la seule tâche qui modifie le site.
- Ce dossier n'est pas publié et une modification de ce dossier ne relance pas la publication.
