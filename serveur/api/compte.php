<?php
/* Gouyerian Tech : espace client (page /mon-espace/).
   Connexion sans mot de passe : un code à 6 chiffres est envoyé par e-mail (valable 15 minutes, 5 essais).
   Session : jeton aléatoire en base (haché), cookie HttpOnly + Secure + SameSite=Strict, 30 jours.
   Le client voit les réparations enregistrées avec son adresse e-mail, son panier et ses coordonnées. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
if (($_SERVER['HTTP_X_GT'] ?? '') !== '1') fail('Demande refusée.', 403);
if (!https_ok()) fail('Ouvrez la page en https://', 403);

const CK_CLIENT = 'gtcli';
const JOURS_CLIENT = 30;

$d = entree();
$action = txt($d['action'] ?? '', 30);
$pdo = db();

function cookie_client(string $val, int $exp): void {
  setcookie(CK_CLIENT, $val, ['expires' => $exp, 'path' => '/api/', 'secure' => empty(cfg()['dev']), 'httponly' => true, 'samesite' => 'Strict']);
}

function client_connecte(): ?array {
  $j = $_COOKIE[CK_CLIENT] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $j)) return null;
  $pdo = db();
  $s = $pdo->prepare('SELECT c.* FROM gt_csessions s JOIN gt_clients c ON c.id = s.client_id WHERE s.jeton = ? AND s.expire > ?');
  $s->execute([hash('sha256', $j), now()]);
  $c = $s->fetch();
  return $c ?: null;
}

function email_ok(string $e): string {
  $e = strtolower(txt($e, 190));
  if (!filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide.');
  return $e;
}

function profil(array $c): array {
  $pdo = db();
  $s = $pdo->prepare('SELECT * FROM gt_reparations WHERE LOWER(email) = ? ORDER BY cree_le DESC LIMIT 50');
  $s->execute([strtolower($c['email'])]);
  $reps = array_map('vue_client', $s->fetchAll());
  $p = $pdo->prepare('SELECT contenu, maj_le FROM gt_paniers WHERE client_id = ?');
  $p->execute([$c['id']]);
  $pan = $p->fetch();
  $dq = $pdo->prepare('SELECT ref, type, statut, sujet, cree_le, maj_le FROM gt_demandes WHERE client_id = ? OR (email <> \'\' AND LOWER(email) = ?) ORDER BY cree_le DESC LIMIT 30');
  $dq->execute([$c['id'], strtolower($c['email'])]);
  $dem = array_map(fn($x) => ['ref' => $x['ref'], 'type' => $x['type'], 'sujet' => $x['sujet'], 'statut' => STATUTS_DEMANDE[$x['statut']] ?? 'Reçue',
    'traitee' => in_array($x['statut'], ['traitee', 'archivee'], true), 'cree_le' => $x['cree_le']], $dq->fetchAll());
  return [
    'ok' => true,
    'demandes' => $dem,
    'client' => ['email' => $c['email'], 'nom' => $c['nom'], 'telephone' => $c['telephone'], 'cree_le' => $c['cree_le']],
    'reparations' => $reps,
    'panier' => $pan ? (json_decode($pan['contenu'], true) ?: []) : [],
    'panier_maj' => $pan['maj_le'] ?? null,
  ];
}

/* ---------- Connexion ---------- */
if ($action === 'etat') {
  $c = client_connecte();
  out($c ? profil($c) + ['connecte' => true] : ['ok' => true, 'connecte' => false]);
}

if ($action === 'demander_code') {
  $email = email_ok($d['email'] ?? '');
  limite('code-ip:' . ip(), 20, 60);
  limite('code-mail:' . $email, 5, 15);
  essai('code-ip:' . ip());
  essai('code-mail:' . $email);
  $code = (string)random_int(100000, 999999);
  $pdo->prepare('DELETE FROM gt_codes WHERE email = ? OR expire < ?')->execute([$email, now()]);
  $pdo->prepare('INSERT INTO gt_codes (email, code_hash, expire, essais) VALUES (?, ?, ?, 0)')
    ->execute([$email, hash('sha256', $email . '|' . $code), date('Y-m-d H:i:s', time() + 900)]);
  $site = rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/');
  envoyer_mail($email, "Votre code de connexion Gouyerian Tech : $code",
    "Bonjour,\n\nVoici votre code pour vous connecter à votre espace client Gouyerian Tech :\n\n    $code\n\n"
    . "Il est valable 15 minutes. Si vous n’avez pas demandé ce code, ignorez simplement cet e-mail : personne ne peut se connecter sans lui.\n\n"
    . "Gouyerian Tech\n1 route de Segonzac, 16130 Saint-Fort-sur-le-Né\n06 05 79 48 94 · $site\n");
  out(['ok' => true]);  // même réponse que l'adresse soit connue ou non
}

if ($action === 'verifier') {
  $email = email_ok($d['email'] ?? '');
  $code = preg_replace('/\D/', '', txt($d['code'] ?? '', 12)) ?? '';
  limite('verif-ip:' . ip(), 30, 15);
  $s = $pdo->prepare('SELECT * FROM gt_codes WHERE email = ? AND expire > ? ORDER BY id DESC LIMIT 1');
  $s->execute([$email, now()]);
  $row = $s->fetch();
  if (!$row) fail('Code expiré ou introuvable. Demandez un nouveau code.', 401);
  if ((int)$row['essais'] >= 5) fail('Trop d’essais avec ce code. Demandez un nouveau code.', 429);
  if (!hash_equals($row['code_hash'], hash('sha256', $email . '|' . $code))) {
    $pdo->prepare('UPDATE gt_codes SET essais = essais + 1 WHERE id = ?')->execute([$row['id']]);
    essai('verif-ip:' . ip());
    fail('Code incorrect. Vérifiez les 6 chiffres reçus par e-mail.', 401);
  }
  $pdo->prepare('DELETE FROM gt_codes WHERE email = ?')->execute([$email]);
  $s = $pdo->prepare('SELECT * FROM gt_clients WHERE email = ?');
  $s->execute([$email]);
  $c = $s->fetch();
  if (!$c) {
    /* Nouveau compte : nom et téléphone repris de sa dernière réparation, s'il y en a une */
    $r = $pdo->prepare('SELECT nom, telephone FROM gt_reparations WHERE LOWER(email) = ? ORDER BY cree_le DESC LIMIT 1');
    $r->execute([$email]);
    $last = $r->fetch() ?: ['nom' => '', 'telephone' => ''];
    $pdo->prepare('INSERT INTO gt_clients (email, nom, telephone, cree_le, vu_le) VALUES (?, ?, ?, ?, ?)')->execute([$email, $last['nom'], $last['telephone'], now(), now()]);
    $s->execute([$email]);
    $c = $s->fetch();
  } else {
    $pdo->prepare('UPDATE gt_clients SET vu_le = ? WHERE id = ?')->execute([now(), $c['id']]);
  }
  $j = bin2hex(random_bytes(32));
  $exp = time() + JOURS_CLIENT * 86400;
  $pdo->prepare('DELETE FROM gt_csessions WHERE expire < ?')->execute([now()]);
  $pdo->prepare('INSERT INTO gt_csessions (jeton, client_id, expire) VALUES (?, ?, ?)')->execute([hash('sha256', $j), $c['id'], date('Y-m-d H:i:s', $exp)]);
  cookie_client($j, $exp);
  out(profil($c) + ['connecte' => true]);
}

/* ---------- Avec connexion ---------- */
$c = client_connecte();
if (!$c) fail('Connexion nécessaire.', 401);

switch ($action) {
  case 'moi':
    out(profil($c) + ['connecte' => true]);

  case 'profil':
    $nom = txt($d['nom'] ?? '', 120);
    $tel = txt($d['telephone'] ?? '', 40);
    $pdo->prepare('UPDATE gt_clients SET nom = ?, telephone = ? WHERE id = ?')->execute([$nom, $tel, $c['id']]);
    $c['nom'] = $nom;
    $c['telephone'] = $tel;
    out(profil($c) + ['connecte' => true]);

  case 'panier':
    $items = is_array($d['items'] ?? null) ? array_slice($d['items'], 0, 30) : [];
    $propre = [];
    foreach ($items as $x) {
      if (!is_array($x) || !is_string($x['n'] ?? null)) continue;
      $q = (int)($x['q'] ?? 0);
      if ($q < 1) continue;
      $propre[] = ['n' => txt($x['n'], 160), 'p' => is_numeric($x['p'] ?? null) ? (float)$x['p'] : null, 'q' => min(100, $q),
        'ic' => txt($x['ic'] ?? '', 20), 'note' => txt($x['note'] ?? '', 200)];
    }
    $json = json_encode($propre, JSON_UNESCAPED_UNICODE);
    $pdo->prepare('DELETE FROM gt_paniers WHERE client_id = ?')->execute([$c['id']]);
    if ($propre) $pdo->prepare('INSERT INTO gt_paniers (client_id, contenu, maj_le) VALUES (?, ?, ?)')->execute([$c['id'], $json, now()]);
    out(['ok' => true, 'n' => count($propre)]);

  case 'devis':
    $s = $pdo->prepare('SELECT * FROM gt_reparations WHERE numero = ? AND LOWER(email) = ?');
    $s->execute([norm_numero(txt($d['numero'] ?? '', 30)), strtolower($c['email'])]);
    $r = $s->fetch();
    if (!$r) fail('Réparation introuvable.', 404);
    repondre_devis($r, txt($d['reponse'] ?? '', 10));
    out(profil($c) + ['connecte' => true]);

  case 'deconnexion':
    $pdo->prepare('DELETE FROM gt_csessions WHERE jeton = ?')->execute([hash('sha256', $_COOKIE[CK_CLIENT] ?? '')]);
    cookie_client('', time() - 3600);
    out(['ok' => true]);

  case 'supprimer':
    foreach (['DELETE FROM gt_csessions WHERE client_id = ?', 'DELETE FROM gt_paniers WHERE client_id = ?', 'DELETE FROM gt_clients WHERE id = ?'] as $q) {
      $pdo->prepare($q)->execute([$c['id']]);
    }
    cookie_client('', time() - 3600);
    out(['ok' => true]);
}
fail('Action inconnue.');
