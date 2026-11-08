<?php
/* Gouyerian Tech : paiement en ligne par carte avec SumUp (page de paiement hébergée par SumUp).
   L'atelier crée une demande de paiement (montant fixé par lui) ; le client ouvre /paiement/?t=JETON et paie chez SumUp.
   Le montant n'est jamais fourni par le navigateur. SumUp prévient le site (return_url) et l'état est toujours relu chez SumUp. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

/* Notification de SumUp (le contenu n'est pas cru : on relit le paiement chez SumUp) */
if (isset($_GET['sumup'])) {
  $p = paiement_par_jeton((string)$_GET['sumup']);
  if ($p) paiement_verifier($p);
  http_response_code(200);
  exit('ok');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
if (($_SERVER['HTTP_X_GT'] ?? '') !== '1') fail('Demande refusée.', 403);
if (!https_ok()) fail('Ouvrez la page en https://', 403);
$d = entree();
$action = txt($d['action'] ?? 'voir', 20);
limite('pay:' . ip(), 60, 10);
$p = paiement_par_jeton(txt($d['jeton'] ?? '', 40));
if (!$p) { essai('pay:' . ip()); fail('Ce lien de paiement n’existe pas ou plus. Contactez l’atelier au 06 05 79 48 94.', 404); }

if ($action === 'voir') {
  $p = paiement_verifier($p);
  out(['ok' => true, 'paiement' => vue_paiement($p), 'actif' => sumup_actif()]);
}

if ($action === 'payer') {
  if ($p['statut'] === 'paye') out(['ok' => true, 'paiement' => vue_paiement($p)]);
  if ($p['statut'] !== 'attente') fail($p['statut'] === 'rembourse' ? 'Ce paiement a été remboursé.' : 'Ce paiement a été annulé par l’atelier.', 410);
  if (!sumup_actif()) fail('Le paiement en ligne n’est pas encore disponible. Vous pouvez régler à l’atelier.', 503);
  $p = paiement_verifier($p);
  if ($p['statut'] === 'paye') out(['ok' => true, 'paiement' => vue_paiement($p)]);
  $c = cfg();
  $site = rtrim($c['site'] ?? 'https://gouyerian-tech.fr', '/');
  /* une nouvelle page de paiement à chaque essai (celle de SumUp n'est valable que 30 minutes) */
  [$code, $co] = sumup('POST', '/v0.1/checkouts', [
    'checkout_reference' => 'GT-' . $p['id'] . '-' . substr(bin2hex(random_bytes(4)), 0, 8),
    'amount' => round((float)$p['montant'], 2),
    'currency' => 'EUR',
    'merchant_code' => sumup_marchand(),
    'description' => mb_substr('Gouyerian Tech : ' . $p['libelle'], 0, 120),
    'return_url' => "$site/api/paiement.php?sumup=" . $p['jeton'],
    'redirect_url' => lien_paiement($p) . '&retour=1',
    'hosted_checkout' => ['enabled' => true],
  ]);
  $url = (string)($co['hosted_checkout_url'] ?? '');
  if ($code < 200 || $code >= 300 || $url === '' || empty($co['id'])) {
    paiement_evt((int)$p['id'], 'echec', 'SumUp a refusé d’ouvrir la page (' . $code . ')');
    $GLOBALS['GT_DIAG'] = 'SumUp ' . $code . ' ' . mb_substr((string)($co['message'] ?? $co['error_code'] ?? json_encode($co)), 0, 200);
    @file_put_contents(prive_dir() . '/sumup.log', date('c') . ' ' . $GLOBALS['GT_DIAG'] . "\n", FILE_APPEND);
    fail('Le paiement en ligne est momentanément indisponible. Réessayez dans quelques minutes ou réglez à l’atelier.', 502);
  }
  db()->prepare('UPDATE gt_paiements SET checkout_id = ?, maj_le = ? WHERE id = ?')->execute([(string)$co['id'], now(), $p['id']]);
  paiement_evt((int)$p['id'], 'ouvert', (string)$co['id']);
  out(['ok' => true, 'url' => $url]);
}
fail('Action inconnue.');
