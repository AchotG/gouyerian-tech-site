<?php
/* Gouyerian Tech : gestion des réparations par l'atelier (page /gestion/).
   Connexion par mot de passe (haché dans la configuration), jeton de session en base, cookie HttpOnly + Secure + SameSite=Strict.
   Chaque demande doit porter l'en-tête « X-GT: 1 » (un autre site ne peut pas l'ajouter sans autorisation). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
$GLOBALS['GT_DIAG'] = true;

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

function vue(array $r, bool $complet = false): array {
  $v = [
    'id' => (int)$r['id'], 'numero' => $r['numero'], 'nom' => $r['nom'], 'telephone' => $r['telephone'], 'email' => $r['email'],
    'avis_email' => (bool)(int)$r['avis_email'], 'appareil' => $r['appareil'], 'etape' => $r['etape'],
    'libelle' => ETAPES[$r['etape']][0] ?? $r['etape'], 'prix' => $r['prix'], 'maj_le' => $r['maj_le'], 'cree_le' => $r['cree_le'],
    'devis_etat' => (string)$r['devis_etat'], 'garantie_mois' => (int)$r['garantie_mois'], 'garantie_fin' => fin_garantie($r),
  ];
  if ($complet) {
    $v += ['code' => $r['code'], 'panne' => (string)$r['panne'], 'note_interne' => (string)$r['note_interne'], 'lien' => lien_suivi($r),
      'historique' => array_map(fn($h) => $h + ['libelle' => ETAPES[$h['etape']][0] ?? $h['etape']], historique((int)$r['id']))];
  }
  return $v;
}

function champs(array $d): array {
  $email = txt($d['email'] ?? '', 190);
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide.');
  $f = [
    'nom' => txt($d['nom'] ?? '', 120), 'telephone' => txt($d['telephone'] ?? '', 40), 'email' => $email,
    'avis_email' => !empty($d['avis_email']) && $email !== '' ? 1 : 0, 'appareil' => txt($d['appareil'] ?? '', 160),
    'panne' => txt($d['panne'] ?? '', 2000), 'prix' => txt($d['prix'] ?? '', 60), 'note_interne' => txt($d['note_interne'] ?? '', 2000),
    'garantie_mois' => max(0, min(36, (int)($d['garantie_mois'] ?? 0))),
  ];
  if ($f['nom'] === '' || $f['appareil'] === '') fail('Le nom du client et l’appareil sont obligatoires.');
  if (!empty($d['avis_email']) && $email === '') fail('Indiquez l’e-mail du client pour lui envoyer le suivi.');
  return $f;
}

/* ---------- Sans connexion : état, connexion ---------- */
if ($action === 'etat') {
  out(['ok' => true, 'connecte' => connecte($COOKIE), 'etapes' => array_map(fn($k, $v) => ['etape' => $k, 'libelle' => $v[0]], array_keys(ETAPES), ETAPES)]);
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
    out(['ok' => true, 'fiche' => vue(fiche((int)($d['id'] ?? 0)), true)]);

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
    $mail = mail_bienvenue($r);
    $pdo->prepare('INSERT INTO gt_historique (reparation_id, etape, note, le, mail) VALUES (?, ?, ?, ?, ?)')
      ->execute([$id, 'recu', txt($d['note'] ?? '', 1000), now(), $mail ? 1 : 0]);
    out(['ok' => true, 'fiche' => vue($r, true), 'mail' => $mail]);

  case 'etape':
    $r = fiche((int)($d['id'] ?? 0));
    $etape = txt($d['etape'] ?? '', 20);
    if (!isset(ETAPES[$etape])) fail('Étape inconnue.');
    $note = txt($d['note'] ?? '', 1000);
    $mail = !empty($d['mail']) ? mail_etape($r, $etape, $note) : false;
    $pdo->prepare('UPDATE gt_reparations SET etape = ?, maj_le = ?, clos_le = ?, devis_etat = ? WHERE id = ?')
      ->execute([$etape, now(), in_array($etape, CLOS, true) ? now() : null, $etape === 'devis' ? 'attente' : (string)$r['devis_etat'], $r['id']]);
    $pdo->prepare('INSERT INTO gt_historique (reparation_id, etape, note, le, mail) VALUES (?, ?, ?, ?, ?)')
      ->execute([$r['id'], $etape, $note, now(), $mail ? 1 : 0]);
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
    $pdo->prepare('DELETE FROM gt_historique WHERE reparation_id = ?')->execute([$r['id']]);
    $pdo->prepare('DELETE FROM gt_reparations WHERE id = ?')->execute([$r['id']]);
    out(['ok' => true]);

  case 'test_mail':
    $to = txt($d['to'] ?? (cfg()['mail_reply'] ?? ''), 190);
    $ok = envoyer_mail($to, 'Test du suivi Gouyerian Tech', "Bonjour,\n\nCet e-mail confirme que le site gouyerian-tech.fr sait envoyer les e-mails de suivi des réparations.\n\nEnvoyé le " . date('d/m/Y à H:i') . ".\n");
    out(['ok' => $ok, 'to' => $to]);
}
fail('Action inconnue.');
