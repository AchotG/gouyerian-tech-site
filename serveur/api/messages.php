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
$numero = norm_numero(txt($d['numero'] ?? '', 30));
if ($numero === '') fail('Numéro de suivi manquant.');

$pdo = db();
$s = $pdo->prepare('SELECT * FROM gt_reparations WHERE numero = ?');
$s->execute([$numero]);
$r = $s->fetch();

/* Accès : code de suivi, ou espace client connecté avec la même adresse e-mail */
$ok = false;
$code = norm_code(txt($d['code'] ?? '', 20));
if ($r && $code !== '' && hash_equals($r['code'], $code)) $ok = true;
if ($r && !$ok) {
  $j = $_COOKIE['gtcli'] ?? '';
  if (preg_match('/^[a-f0-9]{64}$/', $j)) {
    $c = $pdo->prepare('SELECT c.email FROM gt_csessions s JOIN gt_clients c ON c.id = s.client_id WHERE s.jeton = ? AND s.expire > ?');
    $c->execute([hash('sha256', $j), now()]);
    $em = $c->fetchColumn();
    if ($em && strtolower((string)$em) === strtolower((string)$r['email'])) $ok = true;
  }
}
if (!$ok) {
  limite('suivi:' . ip(), 20, 10);
  essai('suivi:' . ip());
  fail('Accès refusé à cette réparation.', 403);
}

/* Lien temporaire (2 h) vers une pièce jointe, pour la lire directement (vidéos) */
if ($action === 'lien') {
  $s = $pdo->prepare('SELECT pj FROM gt_messages WHERE id = ? AND reparation_id = ?');
  $s->execute([(int)($d['id'] ?? 0), $r['id']]);
  $i = (int)($d['i'] ?? -1);
  if (!isset((json_decode((string)$s->fetchColumn(), true) ?: [])[$i])) fail('Fichier introuvable.', 404);
  $base = (int)$d['id'] . "-$i-" . (time() + 7200);
  out(['ok' => true, 'url' => '/api/messages.php?pj=' . $base . '-' . substr(hash_hmac('sha256', $base, jeton_secret()), 0, 32)]);
}

/* Ouvrir une pièce jointe de la conversation */
if ($action === 'pj') {
  $s = $pdo->prepare('SELECT pj FROM gt_messages WHERE id = ? AND reparation_id = ?');
  $s->execute([(int)($d['id'] ?? 0), $r['id']]);
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
  $pdo->prepare('INSERT INTO gt_messages (reparation_id, auteur, texte, pj, le, lu) VALUES (?, ?, ?, ?, ?, 0)')
    ->execute([$r['id'], 'client', $texte, json_encode($pj, JSON_UNESCAPED_UNICODE), now()]);
  $pdo->prepare('UPDATE gt_reparations SET maj_le = ? WHERE id = ?')->execute([now(), $r['id']]);
  notifier_atelier("message de {$r['nom']} ({$r['numero']})",
    "Nouveau message de {$r['nom']} pour la réparation {$r['numero']} ({$r['appareil']}) :\n« " . mb_substr($texte, 0, 300) . (mb_strlen($texte) > 300 ? '…' : '') . ' »'
    . ($pj ? "\n" . count($pj) . ' pièce(s) jointe(s)' : ''));
}

/* Les messages de l'atelier sont marqués comme lus par le client */
if ($action === 'lire' || $action === 'envoyer') {
  $pdo->prepare("UPDATE gt_messages SET lu = 1, lu_le = ? WHERE reparation_id = ? AND auteur = 'atelier' AND lu = 0")->execute([now(), $r['id']]);
}
out(['ok' => true, 'messages' => messages((int)$r['id'], 'client')]);
