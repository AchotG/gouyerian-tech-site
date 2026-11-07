<?php
/* Gouyerian Tech : messagerie client ↔ atelier, une conversation par réparation.
   Le client s'identifie soit avec le numéro de suivi et le code, soit par son espace client (même adresse e-mail). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

/* GET ?pj=… : lecture directe d'une vidéo ou d'un fichier (le lecteur vidéo du navigateur demande le fichier par morceaux).
   Le lien est signé et valable 2 heures ; il est délivré par l'action « lien » ci-dessous. */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pj'])) {
  if (!preg_match('/^(\d+)-(\d+)-(\d+)-([a-f0-9]{32})$/', (string)$_GET['pj'], $m) || (int)$m[3] < time()
    || !hash_equals(substr(hash_hmac('sha256', "$m[1]-$m[2]-$m[3]", jeton_secret()), 0, 32), $m[4])) { http_response_code(403); exit('Lien expiré : rouvrez la page de suivi.'); }
  $s = db()->prepare('SELECT pj FROM gt_messages WHERE id = ?');
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
if (!$d) fail('Demande illisible.');
$action = txt($d['action'] ?? 'lire', 20);
$pdo = db();

/* Client connecté à son espace (cookie de session) */
function client_session(): ?array {
  $j = $_COOKIE['gtcli'] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $j)) return null;
  $c = db()->prepare('SELECT c.* FROM gt_csessions s JOIN gt_clients c ON c.id = s.client_id WHERE s.jeton = ? AND s.expire > ?');
  $c->execute([hash('sha256', $j), now()]);
  return $c->fetch() ?: null;
}

/* Quelle conversation ? « fil » = d:RÉFÉRENCE (une demande) ou c (conversation générale), réservés à l'espace client ;
   sinon « numero » d'une réparation, avec son code de suivi ou l'espace client de la même adresse */
$fil = null; $r = null; $titre = ''; $email = ''; $nom = ''; $lien = rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/') . '/mon-espace/';
$cfil = txt($d['fil'] ?? '', 40);
if ($cfil !== '') {
  $cl = client_session();
  if (!$cl) fail('Connectez-vous à votre espace client.', 401);
  $nom = (string)$cl['nom']; $email = (string)$cl['email'];
  if ($cfil === 'c') { $fil = ['c', (int)$cl['id']]; $titre = 'conversation générale'; }
  elseif (preg_match('/^d:(D-\d{4}-\d{3,})$/', $cfil, $m)) {
    $q = $pdo->prepare("SELECT * FROM gt_demandes WHERE ref = ? AND (client_id = ? OR (email <> '' AND LOWER(email) = ?))");
    $q->execute([$m[1], $cl['id'], strtolower($cl['email'])]);
    $dm = $q->fetch();
    if (!$dm) fail('Demande introuvable.', 404);
    $fil = ['d', (int)$dm['id'], (int)$cl['id']]; $titre = 'demande ' . $dm['ref'];
  } else fail('Conversation inconnue.');
} else {
  $numero = norm_numero(txt($d['numero'] ?? '', 30));
  if ($numero === '') fail('Numéro de suivi manquant.');
  $s = $pdo->prepare('SELECT * FROM gt_reparations WHERE numero = ?');
  $s->execute([$numero]);
  $r = $s->fetch();
  $ok = false;
  $code = norm_code(txt($d['code'] ?? '', 20));
  if ($r && $code !== '' && hash_equals($r['code'], $code)) $ok = true;
  if ($r && !$ok) { $cl = client_session(); if ($cl && strtolower((string)$cl['email']) === strtolower((string)$r['email'])) $ok = true; }
  if (!$ok) {
    limite('suivi:' . ip(), 20, 10);
    essai('suivi:' . ip());
    fail('Accès refusé à cette réparation.', 403);
  }
  $fil = ['r', (int)$r['id']]; $titre = 'réparation ' . $r['numero'] . ' (' . $r['appareil'] . ')'; $nom = (string)$r['nom'];
}
[$W, $WP] = fil_where($fil);

/* Lien temporaire (2 h) vers une pièce jointe, pour la lire directement (vidéos) */
if ($action === 'lien') {
  $s = $pdo->prepare("SELECT pj FROM gt_messages WHERE id = ? AND $W");
  $s->execute(array_merge([(int)($d['id'] ?? 0)], $WP));
  $i = (int)($d['i'] ?? -1);
  if (!isset((json_decode((string)$s->fetchColumn(), true) ?: [])[$i])) fail('Fichier introuvable.', 404);
  $base = (int)$d['id'] . "-$i-" . (time() + 7200);
  out(['ok' => true, 'url' => '/api/messages.php?pj=' . $base . '-' . substr(hash_hmac('sha256', $base, jeton_secret()), 0, 32)]);
}

/* Ouvrir une pièce jointe de la conversation */
if ($action === 'pj') {
 $s = $pdo->prepare("SELECT pj FROM gt_messages WHERE id = ? AND $W");
  $s->execute(array_merge([(int)($d['id'] ?? 0)], $WP));
  $f = (json_decode((string)$s->fetchColumn(), true) ?: [])[(int)($d['i'] ?? -1)] ?? null;
  $path = $f ? prive_dir() . '/pj/' . basename($f['fichier']) : '';
  if (!$f || !is_file($path)) fail('Fichier introuvable.', 404);
  envoyer_fichier($path, $f['type'], $f['nom']);
}

if ($action === 'envoyer') {
  limite('msg:' . ip(), 30, 60);
  essai('msg:' . ip());
  $texte = txt($d['texte'] ?? '', 2000);
  $pj = recevoir_pj(4);
  if ($texte === '' && !$pj) fail('Écrivez un message.');
  ajouter_message($fil, 'client', $texte, $pj);
  if ($r) $pdo->prepare('UPDATE gt_reparations SET maj_le = ? WHERE id = ?')->execute([now(), $r['id']]);
  notifier_atelier('message de ' . ($nom !== '' ? $nom : $email) . " ($titre)",
    'Nouveau message de ' . ($nom !== '' ? $nom : $email) . " ($titre) :\n« " . mb_substr($texte, 0, 300) . (mb_strlen($texte) > 300 ? '…' : '') . ' »'
    . ($pj ? "\n" . count($pj) . ' pièce(s) jointe(s)' : ''));
}

/* Les messages de l'atelier sont marqués comme lus par le client */
if ($action === 'lire' || $action === 'envoyer') marquer_lus($fil, 'atelier');
out(['ok' => true, 'messages' => messages_fil($fil, 'client')]);
