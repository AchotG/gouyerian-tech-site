<?php
/* Gouyerian Tech : messagerie client ↔ atelier, une conversation par réparation.
   Le client s'identifie soit avec le numéro de suivi et le code, soit par son espace client (même adresse e-mail). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

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

/* Ouvrir une pièce jointe de la conversation */
if ($action === 'pj') {
  $s = $pdo->prepare('SELECT pj FROM gt_messages WHERE id = ? AND reparation_id = ?');
  $s->execute([(int)($d['id'] ?? 0), $r['id']]);
  $f = (json_decode((string)$s->fetchColumn(), true) ?: [])[(int)($d['i'] ?? -1)] ?? null;
  $path = $f ? prive_dir() . '/pj/' . basename($f['fichier']) : '';
  if (!$f || !is_file($path)) fail('Fichier introuvable.', 404);
  header('Content-Type: ' . $f['type']);
  header('Content-Length: ' . filesize($path));
  header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/u', '_', $f['nom']) . '"');
  header('Cache-Control: private, no-store');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
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
