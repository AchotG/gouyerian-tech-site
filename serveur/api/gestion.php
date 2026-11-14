<?php
/* Gouyerian Tech : gestion des réparations par l'atelier (page /gestion/).
   Connexion par mot de passe (haché dans la configuration), jeton de session en base, cookie HttpOnly + Secure + SameSite=Strict.
   Chaque demande doit porter l'en-tête « X-GT: 1 » (un autre site ne peut pas l'ajouter sans autorisation). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
$GLOBALS['GT_DIAG'] = true;

/* Pièce jointe d'une demande (?pj=) ou d'un message (?mpj=) : lien direct (GET) ouvert depuis la page de gestion, réservé à l'atelier connecté */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['pj']) || isset($_GET['mpj']))) {
  $msg = isset($_GET['mpj']);
  if (!preg_match('/^(\d+)-(\d+)$/', (string)($msg ? $_GET['mpj'] : $_GET['pj']), $m) || !connecte('gtadm')) { http_response_code(403); exit('Accès refusé.'); }
  $s = db()->prepare($msg ? 'SELECT pj FROM gt_messages WHERE id = ?' : 'SELECT pj FROM gt_demandes WHERE id = ?');
  $s->execute([(int)$m[1]]);
  $f = (json_decode((string)$s->fetchColumn(), true) ?: [])[(int)$m[2]] ?? null;
  $path = $f ? prive_dir() . '/pj/' . basename($f['fichier']) : '';
  if (!$f || !is_file($path)) { http_response_code(404); exit('Fichier introuvable.'); }
  envoyer_fichier($path, $f['type'], $f['nom']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
if (($_SERVER['HTTP_X_GT'] ?? '') !== '1') fail('Demande refusée.', 403);
if (!https_ok()) fail('Ouvrez la page en https://', 403);

$d = entree();
$action = txt($d['action'] ?? '', 30);
$COOKIE = 'gtadm';
$JOURS = 30;

function sessions_table(): void {
  $pdo = db();
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $pdo->exec('CREATE TABLE IF NOT EXISTS gt_sessions (jeton CHAR(64) NOT NULL PRIMARY KEY, expire DATETIME NOT NULL)'
    . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
}

function set_cookie(string $name, string $val, int $exp): void {
  setcookie($name, $val, [
    'expires' => $exp, 'path' => '/api/', 'secure' => empty(cfg()['dev']), 'httponly' => true, 'samesite' => 'Strict',
  ]);
}

function connecte(string $cookie): bool {
  $j = $_COOKIE[$cookie] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $j)) return false;
  sessions_table();
  $pdo = db();
  $pdo->prepare('DELETE FROM gt_sessions WHERE expire < ?')->execute([now()]);
  $s = $pdo->prepare('SELECT 1 FROM gt_sessions WHERE jeton = ?');
  $s->execute([hash('sha256', $j)]);
  return (bool)$s->fetchColumn();
}

function fiche(int $id): array {
  $s = db()->prepare('SELECT * FROM gt_reparations WHERE id = ?');
  $s->execute([$id]);
  $r = $s->fetch();
  if (!$r) fail('Réparation introuvable.', 404);
  return $r;
}

/* Adresse exacte donnée par le client (rendez-vous à domicile, livraison), pour le bouton « S'y rendre » ; vide si seulement la commune */
function adresse_de(array $x): string {
  $ok = fn($v) => is_string($v) && trim($v) !== '' && trim($v) !== '-';
  if ($ok($x['Adresse'] ?? '')) return trim($x['Adresse']) . ($ok($x['Commune'] ?? '') && stripos($x['Adresse'], trim($x['Commune'])) === false ? ', ' . trim($x['Commune']) : '');
  $r = (string)($x['Retrait'] ?? '');
  if (preg_match('/^Livraison à domicile : (.+?)(?: \(environ|, livraison|$)/u', $r, $m)) return trim($m[1]);
  return '';
}
function adresse_reparation(int $id): string {
  $s = db()->prepare('SELECT details FROM gt_demandes WHERE reparation_id = ? ORDER BY id DESC LIMIT 1');
  $s->execute([$id]);
  return adresse_de(json_decode((string)$s->fetchColumn(), true) ?: []);
}

function vue(array $r, bool $complet = false): array {
  $v = [
    'id' => (int)$r['id'], 'numero' => $r['numero'], 'nom' => $r['nom'], 'telephone' => $r['telephone'], 'email' => $r['email'],
    'avis_email' => (bool)(int)$r['avis_email'], 'appareil' => $r['appareil'], 'etape' => $r['etape'],
    'libelle' => ETAPES[$r['etape']][0] ?? $r['etape'], 'prix' => $r['prix'], 'maj_le' => $r['maj_le'], 'cree_le' => $r['cree_le'],
    'devis_etat' => (string)$r['devis_etat'], 'devis_vu' => (bool)(int)$r['devis_vu'], 'garantie_mois' => (int)$r['garantie_mois'], 'garantie_fin' => fin_garantie($r),
    'non_lus' => non_lus((int)$r['id']),
  ];
  if ($complet) {
    $v += ['code' => $r['code'], 'panne' => (string)$r['panne'], 'travaux' => travaux_de($r), 'adresse' => adresse_reparation((int)$r['id']), 'note_interne' => (string)$r['note_interne'], 'lien' => lien_suivi($r),
      'messages' => messages((int)$r['id'], 'atelier'),
      'paiements' => paiements_de('reparation_id', (int)$r['id']),
      'historique' => array_map(fn($h) => $h + ['libelle' => lib_histo($h['etape'])], historique((int)$r['id']))];
  }
  return $v;
}

function non_lus(int $id): int {
  $s = db()->prepare("SELECT COUNT(*) FROM gt_messages WHERE reparation_id = ? AND auteur = 'client' AND lu = 0");
  $s->execute([$id]);
  return (int)$s->fetchColumn();
}

function champs(array $d): array {
  $email = txt($d['email'] ?? '', 190);
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide.');
  $f = [
    'nom' => txt($d['nom'] ?? '', 120), 'telephone' => txt($d['telephone'] ?? '', 40), 'email' => $email,
    'avis_email' => !empty($d['avis_email']) && $email !== '' ? 1 : 0, 'appareil' => txt($d['appareil'] ?? '', 160),
    'panne' => txt($d['panne'] ?? '', 2000), 'prix' => txt($d['prix'] ?? '', 60), 'note_interne' => txt($d['note_interne'] ?? '', 2000),
    'garantie_mois' => max(0, min(120, (int)($d['garantie_mois'] ?? 0))),
  ];
  if ($f['nom'] === '' || $f['appareil'] === '') fail('Le nom du client et l’appareil sont obligatoires.');
  if (!empty($d['avis_email']) && $email === '') fail('Indiquez l’e-mail du client pour lui envoyer le suivi.');
  return $f;
}

function demande(int $id): array {
  $s = db()->prepare('SELECT * FROM gt_demandes WHERE id = ?');
  $s->execute([$id]);
  $r = $s->fetch();
  if (!$r) fail('Demande introuvable.', 404);
  return $r;
}

function vue_demande(array $r, bool $complet = false): array {
  $pj = json_decode((string)$r['pj'], true) ?: [];
  $v = ['id' => (int)$r['id'], 'ref' => $r['ref'], 'type' => $r['type'], 'statut' => $r['statut'], 'nom' => $r['nom'], 'telephone' => $r['telephone'],
    'email' => $r['email'], 'sujet' => $r['sujet'], 'cree_le' => $r['cree_le'], 'maj_le' => $r['maj_le'], 'nb_pj' => count($pj),
    'client' => $r['client_id'] !== null, 'reparation_id' => $r['reparation_id'] !== null ? (int)$r['reparation_id'] : null];
  if ($complet) {
    $v['details'] = json_decode((string)$r['details'], true) ?: [];
    $v['adresse'] = adresse_de($v['details']);
    $v['note_interne'] = (string)$r['note_interne'];
    $v['pj'] = array_map(fn($f, $i) => ['nom' => $f['nom'], 'type' => $f['type'], 'taille' => $f['taille'], 'url' => '/api/gestion.php?pj=' . $r['id'] . '-' . $i], $pj, array_keys($pj));
  }
  return $v;
}

function paiements_fil(array $fil): array {
  if ($fil[0] !== 'c') return paiements_de($fil[0] === 'r' ? 'reparation_id' : 'demande_id', (int)$fil[1]);
  $s = db()->prepare("SELECT * FROM gt_paiements WHERE client_id = ? AND reparation_id = 0 AND demande_id = 0 AND statut <> 'annule' ORDER BY id DESC");
  $s->execute([(int)$fil[1]]);
  return array_map('vue_paiement', $s->fetchAll());
}

/* Description d'une conversation pour la gestion (clé r:ID, d:ID ou c:ID) */
function fil_gestion(array $x): ?array {
  $pdo = db();
  if ((int)$x['reparation_id']) {
    $q = $pdo->prepare('SELECT id, numero, nom, email, telephone, appareil FROM gt_reparations WHERE id = ?'); $q->execute([$x['reparation_id']]); $r = $q->fetch();
    return $r ? ['cle' => 'r:' . $r['id'], 'type' => 'reparation', 'nom' => $r['nom'], 'email' => $r['email'], 'telephone' => $r['telephone'], 'sujet' => 'Réparation ' . $r['numero'] . ' · ' . $r['appareil'], 'id' => (int)$r['id']] : null;
  }
  if ((int)$x['demande_id']) {
    $q = $pdo->prepare('SELECT id, ref, nom, email, telephone, sujet FROM gt_demandes WHERE id = ?'); $q->execute([$x['demande_id']]); $r = $q->fetch();
    return $r ? ['cle' => 'd:' . $r['id'], 'type' => 'demande', 'nom' => $r['nom'], 'email' => $r['email'], 'telephone' => $r['telephone'], 'ref' => $r['ref'], 'sujet' => 'Demande ' . $r['ref'] . ' · ' . preg_replace('/^Demande( de devis \(espace client\))? : /', '', (string)$r['sujet']), 'id' => (int)$r['id']] : null;
  }
  if ((int)$x['client_id']) {
    $q = $pdo->prepare('SELECT id, nom, email, telephone FROM gt_clients WHERE id = ?'); $q->execute([$x['client_id']]); $r = $q->fetch();
    return $r ? ['cle' => 'c:' . $r['id'], 'type' => 'client', 'nom' => $r['nom'], 'email' => $r['email'], 'telephone' => $r['telephone'], 'sujet' => 'Espace client · conversation générale', 'id' => (int)$r['id']] : null;
  }
  return null;
}

function fil_ouvrir(string $cle): array {
  if (!preg_match('/^([rdc]):(\d+)$/', $cle, $m)) fail('Conversation inconnue.');
  if ($m[1] === 'd') {  /* demande devenue réparation : on ouvre la conversation de la réparation */
    $q = db()->prepare('SELECT reparation_id FROM gt_demandes WHERE id = ?'); $q->execute([(int)$m[2]]); $rid = (int)$q->fetchColumn();
    if ($rid > 0) $m = [0, 'r', (string)$rid];
  }
  $x = ['reparation_id' => $m[1] === 'r' ? $m[2] : 0, 'demande_id' => $m[1] === 'd' ? $m[2] : 0, 'client_id' => $m[1] === 'c' ? $m[2] : 0];
  $ctx = fil_gestion($x);
  if (!$ctx) fail('Conversation introuvable.', 404);
  $fil = [$m[1], (int)$m[2]];
  if ($m[1] === 'd') {  /* rattache la conversation au client de la demande, pour la retrouver dans son espace */
    $q = db()->prepare('SELECT client_id FROM gt_demandes WHERE id = ?'); $q->execute([(int)$m[2]]); $fil[] = (int)$q->fetchColumn();
  }
  return [$fil, $ctx];
}

/* ---------- Sans connexion : état, connexion ---------- */
if ($action === 'etat') {
  out(['ok' => true, 'sumup' => sumup_actif(), 'connecte' => connecte($COOKIE), 'etapes' => array_map(fn($k, $v) => ['etape' => $k, 'libelle' => $v[0]], array_keys(ETAPES), ETAPES)]);
}
if ($action === 'connexion') {
  $hash = cfg()['admin_hash'] ?? '';
  if ($hash === '') fail('Le mot de passe de la gestion n’est pas encore défini.', 503);
  limite('login:' . ip(), 8, 15);
  limite('login:tous', 40, 60);
  if (!password_verify(txt($d['mdp'] ?? '', 200), $hash)) {
    essai('login:' . ip());
    essai('login:tous');
    usleep(400000);
    fail('Mot de passe incorrect.', 401);
  }
  sessions_table();
  $j = bin2hex(random_bytes(32));
  $exp = time() + $JOURS * 86400;
  db()->prepare('INSERT INTO gt_sessions (jeton, expire) VALUES (?, ?)')->execute([hash('sha256', $j), date('Y-m-d H:i:s', $exp)]);
  set_cookie($COOKIE, $j, $exp);
  nettoyer();
  nettoyer_demandes();
  nettoyer_messages();
  out(['ok' => true]);
}

/* ---------- Avec connexion ---------- */
if (!connecte($COOKIE)) fail('Connexion nécessaire.', 401);
$pdo = db();

switch ($action) {
  case 'deconnexion':
    $j = $_COOKIE[$COOKIE] ?? '';
    $pdo->prepare('DELETE FROM gt_sessions WHERE jeton = ?')->execute([hash('sha256', $j)]);
    set_cookie($COOKIE, '', time() - 3600);
    out(['ok' => true]);

  case 'liste':
    $vue = txt($d['vue'] ?? 'cours', 10);
    $q = txt($d['q'] ?? '', 80);
    $sql = 'SELECT * FROM gt_reparations WHERE 1=1';
    $args = [];
    if ($vue === 'cours') $sql .= " AND etape NOT IN ('rendu','annule','prete')";
    elseif ($vue === 'prete') $sql .= " AND etape = 'prete'";
    elseif ($vue === 'clos') $sql .= " AND etape IN ('rendu','annule')";
    if ($q !== '') {
      $sql .= ' AND (numero LIKE ? OR nom LIKE ? OR telephone LIKE ? OR appareil LIKE ? OR email LIKE ?)';
      $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
      array_push($args, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY maj_le DESC LIMIT 200';
    $s = $pdo->prepare($sql);
    $s->execute($args);
    $c = $pdo->query("SELECT SUM(CASE WHEN etape NOT IN ('rendu','annule','prete') THEN 1 ELSE 0 END) AS cours, SUM(CASE WHEN etape='prete' THEN 1 ELSE 0 END) AS prete FROM gt_reparations")->fetch();
    out(['ok' => true, 'items' => array_map('vue', $s->fetchAll()), 'compte' => ['cours' => (int)$c['cours'], 'prete' => (int)$c['prete']]]);

  case 'fiche':
    $r = fiche((int)($d['id'] ?? 0));
    if (!(int)$r['devis_vu']) $pdo->prepare('UPDATE gt_reparations SET devis_vu = 1 WHERE id = ?')->execute([$r['id']]);
    $v = vue($r, true);  // les messages du client sont affichés comme nouveaux une dernière fois, puis marqués lus
    $pdo->prepare("UPDATE gt_messages SET lu = 1, lu_le = ? WHERE reparation_id = ? AND auteur = 'client' AND lu = 0")->execute([now(), $r['id']]);
    out(['ok' => true, 'fiche' => $v]);

  case 'message':
    $r = fiche((int)($d['id'] ?? 0));
    $texte = txt($d['texte'] ?? '', 2000);
    $pj = recevoir_pj(6);
    if ($texte === '' && !$pj) fail('Écrivez un message ou joignez un fichier.');
    $mail = !empty($d['mail']) ? mail_message($r, $texte, count($pj)) : false;
    $pdo->prepare("INSERT INTO gt_messages (reparation_id, auteur, texte, pj, le, lu, mail) VALUES (?, 'atelier', ?, ?, ?, 0, ?)")
      ->execute([$r['id'], $texte, json_encode($pj, JSON_UNESCAPED_UNICODE), now(), $mail ? 1 : 0]);
    $pdo->prepare("UPDATE gt_messages SET lu = 1, lu_le = ? WHERE reparation_id = ? AND auteur = 'client' AND lu = 0")->execute([now(), $r['id']]);
    $v = vue(fiche((int)$r['id']), true);
    out(['ok' => true, 'fiche' => $v, 'mail' => $mail]);

  case 'a_traiter':
    $n = (int)$pdo->query("SELECT COUNT(*) FROM gt_demandes WHERE statut IN ('nouvelle','en_cours')")->fetchColumn();
    $dv = $pdo->query("SELECT * FROM gt_reparations WHERE devis_vu = 0 ORDER BY maj_le DESC")->fetchAll();
    $ms = $pdo->query("SELECT * FROM gt_reparations WHERE id IN (SELECT reparation_id FROM gt_messages WHERE auteur = 'client' AND lu = 0) ORDER BY maj_le DESC")->fetchAll();
    $nm = (int)$pdo->query("SELECT COUNT(*) FROM gt_messages WHERE auteur = 'client' AND lu = 0")->fetchColumn();
    out(['ok' => true, 'demandes' => $n, 'devis' => array_map('vue', $dv), 'messages' => array_map('vue', $ms), 'messages_non_lus' => $nm, 'notif' => meta('notif', 'chaque')]);

  /* ---------- Messagerie : toutes les conversations ---------- */
  case 'fils':
    /* une seule ligne par conversation (comme les SMS) : réparation, sinon demande, sinon conversation générale du client
       (les messages de l'atelier et du client d'une même demande n'ont pas toujours le même client_id) */
    $rows = $pdo->query("SELECT g.rid AS reparation_id, g.did AS demande_id, g.cid AS client_id, MAX(g.le) AS dernier, COUNT(*) AS n,
        SUM(CASE WHEN g.auteur = 'client' AND g.lu = 0 THEN 1 ELSE 0 END) AS non_lus, MAX(g.id) AS mid
      FROM (SELECT id, le, auteur, lu, reparation_id AS rid,
              CASE WHEN reparation_id > 0 THEN 0 ELSE demande_id END AS did,
              CASE WHEN reparation_id > 0 OR demande_id > 0 THEN 0 ELSE client_id END AS cid FROM gt_messages) g
      GROUP BY g.rid, g.did, g.cid ORDER BY dernier DESC LIMIT 150")->fetchAll();
    $fils = [];
    foreach ($rows as $x) {
      $f = fil_gestion($x);
      if (!$f) continue;
      $m = $pdo->prepare('SELECT auteur, texte, pj FROM gt_messages WHERE id = ?');
      $m->execute([$x['mid']]);
      $dm = $m->fetch();
      $f += ['dernier' => $x['dernier'], 'n' => (int)$x['n'], 'non_lus' => (int)$x['non_lus'],
        'apercu' => ($dm['auteur'] === 'atelier' ? 'Vous : ' : '') . ((string)$dm['texte'] !== '' ? mb_substr((string)$dm['texte'], 0, 90) : '📎 fichier')];
      $fils[] = $f;
    }
    out(['ok' => true, 'fils' => $fils]);

  case 'fil':
    [$fil, $ctx] = fil_ouvrir(txt($d['fil'] ?? '', 20));
    $ms = messages_fil($fil, 'atelier');
    marquer_lus($fil, 'client');
    out(['ok' => true, 'fil' => $ctx, 'messages' => $ms, 'paiements' => paiements_fil($fil)]);

  /* ---------- Paiement en ligne (SumUp) ---------- */
  case 'paiement_creer':
    [$fil, $ctx] = fil_ouvrir(txt($d['fil'] ?? '', 20));
    $montant = round((float)str_replace(',', '.', (string)($d['montant'] ?? 0)), 2);
    if ($montant < 1 || $montant > 5000) fail('Montant entre 1 € et 5 000 €.');
    $lib = txt($d['libelle'] ?? '', 200);
    if ($lib === '') $lib = $ctx['sujet'];
    $cols = fil_cols($fil);
    $jeton = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO gt_paiements (jeton, reparation_id, demande_id, client_id, nom, email, libelle, montant, statut, cree_le, maj_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$jeton, $cols['reparation_id'], $cols['demande_id'], $cols['client_id'], (string)$ctx['nom'], strtolower((string)$ctx['email']), $lib, $montant, 'attente', now(), now()]);
    $p = paiement_par_jeton($jeton);
    paiement_evt((int)$p['id'], 'cree', 'par l’atelier');
    $m = number_format($montant, 2, ',', ' ') . ' €';
    $mail = false;
    if (!empty($d['mail']) && $p['email'] !== '') $mail = mail_pro($p['email'], "Paiement en ligne : $m", ['pre' => "Réglez $m par carte, en toute sécurité.", 'etiquette' => 'Paiement en ligne', 'titre' => "Votre paiement de $m",
      'paras' => [($p['nom'] !== '' ? 'Bonjour ' . prenom($p['nom']) : 'Bonjour') . ',', "Vous pouvez régler par carte bancaire, en ligne : $lib."],
      'infos' => [['Montant', $m], ['Objet', $lib]], 'bouton' => ["Payer $m par carte", lien_paiement($p)],
      'note' => 'Le paiement se fait sur la page sécurisée de SumUp : Gouyerian Tech ne voit jamais vos données de carte. Vous pouvez aussi régler à l’atelier.']);
    if ($mail) paiement_evt((int)$p['id'], 'envoye', $p['email']);
    ajouter_message($fil, 'atelier', "💳 Demande de paiement : $m ($lib)\nPayer par carte : " . lien_paiement($p), [], $mail);
    out(['ok' => true, 'paiement' => vue_paiement($p), 'mail' => $mail, 'messages' => messages_fil($fil, 'atelier'), 'paiements' => paiements_fil($fil)]);

  case 'paiement_annuler':
    $p = paiement_par_jeton(txt($d['jeton'] ?? '', 40));
    if (!$p) fail('Paiement introuvable.', 404);
    $p = paiement_verifier($p);
    if ($p['statut'] === 'paye') fail('Ce paiement est déjà réglé : il ne peut plus être annulé ici (remboursement depuis SumUp).');
    $pdo->prepare("UPDATE gt_paiements SET statut = 'annule', maj_le = ? WHERE id = ?")->execute([now(), $p['id']]);
    paiement_evt((int)$p['id'], 'annule');
    out(['ok' => true]);

  /* Remboursement (total ou partiel) d'un paiement en ligne, décidé et lancé par l'atelier */
  case 'paiement_rembourser':
    $p = paiement_par_jeton(txt($d['jeton'] ?? '', 40));
    if (!$p) fail('Paiement introuvable.', 404);
    if ($p['statut'] !== 'paye') fail('Seul un paiement reçu peut être remboursé.');
    $reste = round((float)$p['montant'] - (float)$p['rembourse'], 2);
    $mt = round((float)str_replace(',', '.', (string)($d['montant'] ?? $reste)), 2);
    if ($mt <= 0 || $mt > $reste) fail('Montant à rembourser entre 0,01 € et ' . number_format($reste, 2, ',', ' ') . ' €.');
    $txid = (string)$p['transaction_id'];
    if ($txid === '' && $p['checkout_id'] !== '') {  /* paiements reçus avant l'historique : on retrouve la transaction chez SumUp */
      [$c0, $co] = sumup('GET', '/v0.1/checkouts/' . rawurlencode($p['checkout_id']));
      foreach ($co['transactions'] ?? [] as $t) if (($t['status'] ?? '') === 'SUCCESSFUL') $txid = (string)($t['id'] ?? '');
      if ($txid !== '') $pdo->prepare('UPDATE gt_paiements SET transaction_id = ? WHERE id = ?')->execute([$txid, $p['id']]);
    }
    if ($txid === '') fail('Transaction SumUp introuvable : remboursez depuis l’application SumUp.');
    [$code, $rp] = sumup('POST', '/v0.1/me/refund/' . rawurlencode($txid), $mt < $reste || (float)$p['rembourse'] > 0 ? ['amount' => $mt] : []);
    if ($code < 200 || $code >= 300) {
      $why = (string)($rp['message'] ?? $rp['error_code'] ?? $rp['detail'] ?? '');
      paiement_evt((int)$p['id'], 'rembourse_echec', "$code $why");
      fail('SumUp a refusé le remboursement (' . $code . ($why !== '' ? ' : ' . $why : '') . '). Le plus souvent : solde SumUp insuffisant tant que le paiement n’est pas versé, ou droits de la clé. Réessayez plus tard ou contactez SumUp.');
    }
    $tot = round((float)$p['rembourse'] + $mt, 2);
    $pdo->prepare('UPDATE gt_paiements SET rembourse = ?, statut = ?, maj_le = ? WHERE id = ?')->execute([$tot, $tot >= (float)$p['montant'] ? 'rembourse' : 'paye', now(), $p['id']]);
    $m = number_format($mt, 2, ',', ' ') . ' €';
    paiement_evt((int)$p['id'], 'rembourse', $m);
    if ($p['email'] !== '') mail_pro($p['email'], "Remboursement de $m", ['etiquette' => 'Paiement en ligne', 'titre' => 'Remboursement effectué',
      'paras' => [($p['nom'] !== '' ? 'Bonjour ' . prenom($p['nom']) : 'Bonjour') . ',', "Nous vous avons remboursé $m sur la carte utilisée pour : {$p['libelle']}."],
      'note' => 'Selon votre banque, le remboursement apparaît sur votre compte sous 3 à 10 jours ouvrés.']);
    out(['ok' => true, 'paiement' => vue_paiement(paiement_par_jeton($p['jeton']))]);

  case 'paiement_detail':
    $p = paiement_par_jeton(txt($d['jeton'] ?? '', 40));
    if (!$p) fail('Paiement introuvable.', 404);
    $p = paiement_verifier($p);
    $fil = (int)$p['reparation_id'] ? 'r:' . $p['reparation_id'] : ((int)$p['demande_id'] ? 'd:' . $p['demande_id'] : ((int)$p['client_id'] ? 'c:' . $p['client_id'] : ''));
    out(['ok' => true, 'paiement' => vue_paiement($p) + ['nom' => $p['nom'], 'email' => $p['email'], 'fil' => $fil, 'reparation_id' => (int)$p['reparation_id']], 'historique' => paiement_evts((int)$p['id'])]);

  case 'paiement_verifier':
    $p = paiement_par_jeton(txt($d['jeton'] ?? '', 40));
    if (!$p) fail('Paiement introuvable.', 404);
    out(['ok' => true, 'paiement' => vue_paiement(paiement_verifier($p))]);

  /* Historique de tous les paiements (avec le nombre d'échecs de chacun) et totaux */
  case 'paiements':
    $rows = $pdo->query("SELECT * FROM gt_paiements ORDER BY id DESC LIMIT 300")->fetchAll();
    $liste = [];
    foreach ($rows as $p) {
      if ($p['statut'] === 'attente') $p = paiement_verifier($p);
      $e = $pdo->prepare("SELECT COUNT(*) FROM gt_paiement_evts WHERE paiement_id = ? AND evenement IN ('echec','expire')");
      $e->execute([$p['id']]);
      $liste[] = vue_paiement($p) + ['nom' => $p['nom'], 'echecs' => (int)$e->fetchColumn()];
    }
    $mois = date('Y-m');
    $enc = 0; $encm = 0; $att = 0;
    foreach ($liste as $x) {
      if (in_array($x['statut'], ['paye', 'rembourse'], true)) { $net = $x['montant'] - $x['rembourse']; $enc += $net; if (substr((string)$x['paye_le'], 0, 7) === $mois) $encm += $net; }
      if ($x['statut'] === 'attente') $att += $x['montant'];
    }
    out(['ok' => true, 'paiements' => $liste, 'totaux' => ['encaisse' => round($enc, 2), 'encaisse_mois' => round($encm, 2), 'en_attente' => round($att, 2)]]);

  case 'fil_envoyer':
    [$fil, $ctx] = fil_ouvrir(txt($d['fil'] ?? '', 20));
    $texte = txt($d['texte'] ?? '', 2000);
    $pj = recevoir_pj(6);
    if ($texte === '' && !$pj) fail('Écrivez un message ou joignez un fichier.');
    $mail = false;
    if (!empty($d['mail'])) {
      if ($fil[0] === 'r') $mail = mail_message(fiche($fil[1]), $texte, count($pj));
      else $mail = mail_message_espace($ctx['email'], $ctx['nom'], $texte, count($pj), $fil[0] === 'd' ? 'Demande ' . $ctx['ref'] : 'Espace client');
    }
    ajouter_message($fil, 'atelier', $texte, $pj, $mail);
    marquer_lus($fil, 'client');
    out(['ok' => true, 'fil' => $ctx, 'messages' => messages_fil($fil, 'atelier'), 'paiements' => paiements_fil($fil), 'mail' => $mail]);

  case 'demandes':
    $vue = txt($d['vue'] ?? 'a_traiter', 12);
    $q = txt($d['q'] ?? '', 80);
    $sql = 'SELECT * FROM gt_demandes WHERE 1=1';
    $args = [];
    if ($vue === 'a_traiter') $sql .= " AND statut IN ('nouvelle','en_cours')";
    elseif ($vue === 'traitee') $sql .= " AND statut IN ('traitee','archivee')";
    if ($q !== '') { $sql .= ' AND (ref LIKE ? OR nom LIKE ? OR telephone LIKE ? OR email LIKE ? OR sujet LIKE ?)'; $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; array_push($args, $l, $l, $l, $l, $l); }
    $s = $pdo->prepare($sql . ' ORDER BY cree_le DESC LIMIT 200');
    $s->execute($args);
    out(['ok' => true, 'items' => array_map('vue_demande', $s->fetchAll())]);

  case 'demande':
    out(['ok' => true, 'demande' => vue_demande(demande((int)($d['id'] ?? 0)), true)]);

  case 'demande_maj':
    $dm = demande((int)($d['id'] ?? 0));
    $statut = txt($d['statut'] ?? $dm['statut'], 12);
    if (!isset(STATUTS_DEMANDE[$statut])) fail('Statut inconnu.');
    $note = array_key_exists('note_interne', $d) ? txt($d['note_interne'], 2000) : (string)$dm['note_interne'];
    $pdo->prepare('UPDATE gt_demandes SET statut = ?, note_interne = ?, maj_le = ? WHERE id = ?')->execute([$statut, $note, now(), $dm['id']]);
    out(['ok' => true, 'demande' => vue_demande(demande((int)$dm['id']), true)]);

  case 'demande_supprimer':
    $dm = demande((int)($d['id'] ?? 0));
    foreach (json_decode((string)$dm['pj'], true) ?: [] as $f) { $p = prive_dir() . '/pj/' . basename($f['fichier']); if (is_file($p)) @unlink($p); }
    $pdo->prepare('DELETE FROM gt_demandes WHERE id = ?')->execute([$dm['id']]);
    out(['ok' => true]);

  case 'reglages':
    if (isset($d['notif'])) {
      $n = txt($d['notif'], 10);
      if (!in_array($n, ['chaque', 'regroupe', 'jamais'], true)) fail('Réglage inconnu.');
      meta_set('notif', $n);
    }
    out(['ok' => true, 'notif' => meta('notif', 'chaque')]);

  case 'creer':
    $f = champs($d);
    $mois = date('ym');
    $s = $pdo->prepare('SELECT COUNT(*) FROM gt_reparations WHERE numero LIKE ?');
    $s->execute(["GT-$mois-%"]);
    $n = (int)$s->fetchColumn() + 1;
    $id = 0;
    for ($i = 0; $i < 20 && !$id; $i++, $n++) {
      $numero = sprintf('GT-%s-%03d', $mois, $n);
      try {
        $pdo->prepare('INSERT INTO gt_reparations (numero, code, nom, telephone, email, avis_email, stop_token, appareil, panne, prix, note_interne, garantie_mois, etape, cree_le, maj_le)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$numero, code_aleatoire(), $f['nom'], $f['telephone'], strtolower($f['email']), $f['avis_email'],
          bin2hex(random_bytes(24)), $f['appareil'], $f['panne'], $f['prix'], $f['note_interne'], $f['garantie_mois'], 'recu', now(), now()]);
        $id = (int)$pdo->lastInsertId();
      } catch (PDOException $e) {
        if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'Duplicate')) throw $e;
      }
    }
    if (!$id) fail('Impossible de créer le numéro de suivi, réessayez.', 500);
    $r = fiche($id);
    if (!empty($d['demande_id'])) {  /* réparation créée depuis une demande : la demande est rattachée et traitée */
      $pdo->prepare("UPDATE gt_demandes SET reparation_id = ?, statut = 'traitee', maj_le = ? WHERE id = ?")->execute([$id, now(), (int)$d['demande_id']]);
      fusionner_demande((int)$d['demande_id'], (int)$id);
    }
    $mail = mail_bienvenue($r);
    $pdo->prepare('INSERT INTO gt_historique (reparation_id, etape, note, le, mail) VALUES (?, ?, ?, ?, ?)')
      ->execute([$id, 'recu', txt($d['note'] ?? '', 1000), now(), $mail ? 1 : 0]);
    out(['ok' => true, 'fiche' => vue($r, true), 'mail' => $mail]);

  case 'etape':
    $r = fiche((int)($d['id'] ?? 0));
    $etape = txt($d['etape'] ?? '', 20);
    if (!isset(ETAPES[$etape])) fail('Étape inconnue.');
    $note = txt($d['note'] ?? '', 1000);
    $mail = !empty($d['mail']) ? mail_etape($r, $etape, $note, true) : false;
    $pdo->prepare('UPDATE gt_reparations SET etape = ?, maj_le = ?, clos_le = ?, devis_etat = ? WHERE id = ?')
      ->execute([$etape, now(), in_array($etape, CLOS, true) ? now() : null, $etape === 'devis' ? 'attente' : (string)$r['devis_etat'], $r['id']]);
    $pdo->prepare('INSERT INTO gt_historique (reparation_id, etape, note, le, mail) VALUES (?, ?, ?, ?, ?)')
      ->execute([$r['id'], $etape, $note, now(), $mail ? 1 : 0]);
    out(['ok' => true, 'fiche' => vue(fiche((int)$r['id']), true), 'mail' => $mail]);

  /* Prix et travaux : détail ligne par ligne ; mode interne (rien au client), informer (historique + e-mail) ou accord (le client doit accepter) */
  case 'travaux':
    $r = fiche((int)($d['id'] ?? 0));
    $mode = txt($d['mode'] ?? 'interne', 10);
    if (!in_array($mode, ['interne', 'informer', 'accord'], true)) fail('Choix inconnu.');
    $lignes = lignes_travaux($d['lignes'] ?? null);
    if (!$lignes) fail('Ajoutez au moins une ligne avec son prix.');
    if ($mode === 'accord' && in_array($r['etape'], CLOS, true)) fail('Cette réparation est terminée : impossible de demander un nouvel accord.');
    $avant = (string)$r['prix'];
    $total = euros(array_sum(array_column($lignes, 'p')));
    $note = txt($d['note'] ?? '', 1000);
    $pdo->prepare('UPDATE gt_reparations SET travaux = ?, prix = ?, maj_le = ?' . ($mode === 'accord' ? ", devis_etat = 'attente', devis_vu = 1" : '') . ' WHERE id = ?')
      ->execute([json_encode($lignes, JSON_UNESCAPED_UNICODE), $total, now(), $r['id']]);
    $mail = false;
    if ($mode !== 'interne') {
      $r2 = fiche((int)$r['id']);
      $mail = !empty($d['mail']) ? mail_travaux($r2, $lignes, $avant, $note, $mode === 'accord') : false;
      $h = ($mode === 'accord' ? 'Votre accord est demandé. ' : '') . 'Nouveau montant : ' . $total . ($avant !== '' && $avant !== $total ? " (avant : $avant)" : '') . '.'
        . "\n" . implode("\n", array_map(fn($x) => '• ' . $x['l'] . ' : ' . euros($x['p']), $lignes)) . ($note !== '' ? "\n\n" . $note : '');
      $pdo->prepare('INSERT INTO gt_historique (reparation_id, etape, note, le, mail) VALUES (?, ?, ?, ?, ?)')->execute([$r['id'], 'travaux', $h, now(), $mail ? 1 : 0]);
    }
    out(['ok' => true, 'fiche' => vue(fiche((int)$r['id']), true), 'mail' => $mail]);

  /* Fiche réparation : prix ET étape enregistrés ensemble, UN SEUL e-mail au client */
  case 'maj':
    $r = fiche((int)($d['id'] ?? 0));
    $note = txt($d['note'] ?? '', 1000);
    $veut_mail = !empty($d['mail']);
    $tr = null; $etape = '';
    if (!empty($d['travaux'])) {
      $mode = txt($d['mode'] ?? 'interne', 10);
      if (!in_array($mode, ['interne', 'informer', 'accord'], true)) fail('Choix inconnu.');
      $lignes = lignes_travaux($d['lignes'] ?? null);
      if (!$lignes) fail('Ajoutez au moins une ligne avec son prix.');
      if ($mode === 'accord' && in_array($r['etape'], CLOS, true)) fail('Cette réparation est terminée : impossible de demander un nouvel accord.');
      $avant = (string)$r['prix'];
      $total = euros(array_sum(array_column($lignes, 'p')));
      $pdo->prepare('UPDATE gt_reparations SET travaux = ?, prix = ?, maj_le = ?' . ($mode === 'accord' ? ", devis_etat = 'attente', devis_vu = 1" : '') . ' WHERE id = ?')
        ->execute([json_encode($lignes, JSON_UNESCAPED_UNICODE), $total, now(), $r['id']]);
      if ($mode !== 'interne') $tr = ['lignes' => $lignes, 'avant' => $avant, 'total' => $total, 'accord' => $mode === 'accord'];
    }
    if (($d['etape'] ?? '') !== '') {
      $etape = txt($d['etape'], 20);
      if (!isset(ETAPES[$etape])) fail('Étape inconnue.');
      $pdo->prepare('UPDATE gt_reparations SET etape = ?, maj_le = ?, clos_le = ?, devis_etat = ? WHERE id = ?')
        ->execute([$etape, now(), in_array($etape, CLOS, true) ? now() : null, $etape === 'devis' ? 'attente' : (string)fiche((int)$r['id'])['devis_etat'], $r['id']]);
    }
    if (!$tr && $etape === '' && empty($d['travaux'])) fail('Rien à enregistrer.');
    $r2 = fiche((int)$r['id']);
    $mail = false;
    if ($veut_mail && ($tr || $etape !== '')) $mail = $etape !== '' ? mail_etape($r2, $etape, $note, true, $tr) : mail_travaux($r2, $tr['lignes'], $tr['avant'], $note, $tr['accord']);
    $h = $pdo->prepare('INSERT INTO gt_historique (reparation_id, etape, note, le, mail) VALUES (?, ?, ?, ?, ?)');
    if ($tr) $h->execute([$r['id'], 'travaux', ($tr['accord'] ? 'Votre accord est demandé. ' : '') . 'Nouveau montant : ' . $tr['total'] . ($tr['avant'] !== '' && $tr['avant'] !== $tr['total'] ? " (avant : {$tr['avant']})" : '') . '.'
      . "\n" . implode("\n", array_map(fn($x) => '• ' . $x['l'] . ' : ' . euros($x['p']), $tr['lignes'])) . ($etape === '' && $note !== '' ? "\n\n" . $note : ''), now(), $mail ? 1 : 0]);
    if ($etape !== '') $h->execute([$r['id'], $etape, $note, now(), $mail ? 1 : 0]);
    out(['ok' => true, 'fiche' => vue(fiche((int)$r['id']), true), 'mail' => $mail]);

  case 'modifier':
    $r = fiche((int)($d['id'] ?? 0));
    $f = champs($d);
    $pdo->prepare('UPDATE gt_reparations SET nom = ?, telephone = ?, email = ?, avis_email = ?, appareil = ?, panne = ?, prix = ?, note_interne = ?, garantie_mois = ?, maj_le = ? WHERE id = ?')
      ->execute([$f['nom'], $f['telephone'], strtolower($f['email']), $f['avis_email'], $f['appareil'], $f['panne'], $f['prix'], $f['note_interne'], $f['garantie_mois'], now(), $r['id']]);
    $r2 = fiche((int)$r['id']);
    $mail = (!(int)$r['avis_email'] || $r['email'] !== $r2['email']) && (int)$r2['avis_email'] ? mail_bienvenue($r2) : false;
    out(['ok' => true, 'fiche' => vue($r2, true), 'mail' => $mail]);

  case 'renvoyer':
    $r = fiche((int)($d['id'] ?? 0));
    if (!(int)$r['avis_email']) fail('Le client n’a pas demandé le suivi par e-mail.');
    out(['ok' => true, 'mail' => mail_bienvenue($r)]);

  case 'supprimer':
    $r = fiche((int)($d['id'] ?? 0));
    supprimer_reparation((int)$r['id']);
    out(['ok' => true]);

  case 'test_mail':
    $to = txt($d['to'] ?? (cfg()['mail_reply'] ?? ''), 190);
    $ok = mail_pro($to, 'Test des e-mails Gouyerian Tech', ['etiquette' => 'Test', 'titre' => 'Les e-mails du site fonctionnent',
    'paras' => ['Bonjour,', 'Cet e-mail confirme que le site gouyerian-tech.fr envoie bien ses e-mails automatiques (suivi des réparations, codes de connexion, messages).'],
    'note' => 'Envoyé le ' . date('d/m/Y à H:i') . '.']);
    out(['ok' => $ok, 'to' => $to]);
}
fail('Action inconnue.');
