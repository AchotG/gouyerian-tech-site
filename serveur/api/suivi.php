<?php
/* Gouyerian Tech : suivi d'une réparation par le client (numéro + code), et désinscription des e-mails. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

/* ---------- Désinscription : le lien de l'e-mail ouvre une page, le clic sur le bouton confirme ---------- */
if (isset($_GET['stop']) || isset($_POST['stop'])) {
  $tok = preg_replace('/[^a-f0-9]/', '', (string)($_POST['stop'] ?? $_GET['stop'])) ?? '';
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex');
  $page = function (string $titre, string $html): never {
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
      . '<title>' . htmlspecialchars($titre) . ' · Gouyerian Tech</title><style>body{margin:0;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f7fc;color:#0b1426}'
      . 'main{max-width:520px;margin:12vh auto;padding:28px 24px;background:#fff;border:1px solid #d9e1ec;border-radius:16px}h1{font-size:1.4rem;margin:0 0 12px}'
      . 'button,a.b{display:inline-block;margin-top:16px;padding:12px 20px;border:0;border-radius:999px;background:#2563eb;color:#fff;font:600 1rem system-ui;text-decoration:none;cursor:pointer}'
      . '@media (prefers-color-scheme:dark){body{background:#0b0c0e;color:#eff1f3}main{background:#131518;border-color:#2a2e34}button,a.b{background:#60a5fa;color:#06101f}}</style></head><body><main>'
      . '<h1>' . htmlspecialchars($titre) . '</h1>' . $html . '</main></body></html>';
    exit;
  };
  if (strlen($tok) !== 48) $page('Lien incomplet', '<p>Ce lien n’est pas valide. Pour ne plus recevoir d’e-mails, appelez le 06 05 79 48 94 ou répondez simplement à l’e-mail reçu.</p>');
  $pdo = db();
  $s = $pdo->prepare('SELECT id, numero, avis_email FROM gt_reparations WHERE stop_token = ?');
  $s->execute([$tok]);
  $r = $s->fetch();
  if (!$r) $page('Lien expiré', '<p>Cette réparation n’est plus enregistrée : vous ne recevrez plus d’e-mails à son sujet.</p>');
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare('UPDATE gt_reparations SET avis_email = 0, maj_le = ? WHERE id = ?')->execute([now(), $r['id']]);
    $page('C’est noté', '<p>Vous ne recevrez plus d’e-mails pour la réparation ' . htmlspecialchars($r['numero']) . '. Vous pouvez toujours suivre son avancée sur le site.</p><a class="b" href="/suivi/">Suivre ma réparation</a>');
  }
  $page('Ne plus recevoir les e-mails de suivi ?', '<p>Réparation ' . htmlspecialchars($r['numero']) . '.</p>'
    . '<form method="post"><input type="hidden" name="stop" value="' . $tok . '"><button type="submit">Ne plus recevoir ces e-mails</button></form>');
}

/* ---------- Consultation du suivi ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
$d = entree();
$numero = norm_numero(txt($d['numero'] ?? '', 30));
$code = norm_code(txt($d['code'] ?? '', 20));
if ($numero === '' || $code === '') fail('Indiquez le numéro de suivi et le code.');

$cle = 'suivi:' . ip();
limite($cle, 20, 10);

$s = db()->prepare('SELECT * FROM gt_reparations WHERE numero = ?');
$s->execute([$numero]);
$r = $s->fetch();
if (!$r || !hash_equals($r['code'], $code)) {
  essai($cle);
  fail('Numéro ou code incorrect. Vérifiez votre bon de dépôt ou l’e-mail reçu.', 404);
}

if (isset($d['devis'])) {
  $r = repondre_devis($r, txt($d['devis'], 10));
}
out(['ok' => true] + vue_client($r));
