<?php
/* Gouyerian Tech : diagnostic des e-mails, appelé par l'action GitHub « Diagnostic des e-mails ».
   Protégé par un jeton à usage unique déposé par SFTP dans /prive (diag-token), effacé dès la première utilisation et au bout de 10 minutes.
   Ne renvoie aucune donnée personnelle : seulement la méthode d'envoi, le résultat et le journal (domaines). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
$f = prive_dir() . '/diag-token';
$jeton = is_file($f) ? trim((string)file_get_contents($f)) : '';
$age = is_file($f) ? time() - (int)filemtime($f) : 9999;
if (is_file($f)) @unlink($f);
if ($jeton === '' || $age > 600 || !hash_equals($jeton, (string)($_SERVER['HTTP_X_DIAG'] ?? ''))) fail('Accès refusé.', 403);
$d = entree();
$c = cfg();
/* Destinataire du test : l'adresse de l'atelier, ou celle de la dernière demande de code de l'espace client (jamais écrite dans les journaux) */
$cible = txt($d['cible'] ?? '', 20);
$to = '';
if ($cible === 'contact') $to = (string)($c['mail_reply'] ?? '');
if ($cible === 'dernier_code') $to = (string)(db()->query('SELECT email FROM gt_codes ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '');
if ($cible !== '' && $to === '') $res_err = 'aucun destinataire pour « ' . $cible . ' »';
$res = ['ok' => true, 'methode' => empty($c['smtp_pass']) ? 'mail()' : 'smtp', 'expediteur' => $c['mail_from'] ?? '', 'php' => PHP_VERSION,
  'sendmail_path' => (string)ini_get('sendmail_path')];
if (isset($res_err)) $res['erreur_cible'] = $res_err;
if ($to !== '') {
  $res['envoi'] = mail_pro($to, 'Test des e-mails Gouyerian Tech', ['etiquette' => 'Test', 'titre' => 'Les e-mails du site fonctionnent',
    'paras' => ['Bonjour,', 'Cet e-mail confirme que le site gouyerian-tech.fr envoie bien ses e-mails automatiques (suivi des réparations, codes de connexion, messages).'],
    'note' => 'Envoyé le ' . date('d/m/Y à H:i') . '.']);
}
/* Variantes de mail() pour trouver ce que l'hébergement accepte */
if ($to !== '' && !empty($d['variantes'])) {
  $from = $c['mail_from'] ?? 'suivi@gouyerian-tech.fr';
  $v = [];
  $essai = function (string $nom, callable $f) use (&$v) { error_clear_last(); $ok = $f(); $v[$nom] = $ok ? 'OK' : ('ECHEC ' . (error_get_last()['message'] ?? '')); };
  if (empty($d['seulement_nouvelles'])) {
  $essai('a_minimal', fn() => mail($to, 'Test A Gouyerian Tech', "Test A (minimal)\r\n"));
  $essai('b_from_simple', fn() => mail($to, 'Test B Gouyerian Tech', "Test B (From simple)\r\n", "From: $from"));
  $essai('c_from_f', fn() => mail($to, 'Test C Gouyerian Tech', "Test C (From + -f)\r\n", "From: $from", '-f' . $from));
  $essai('d_from_nom', fn() => mail($to, 'Test D Gouyerian Tech', "Test D (From avec nom)\r\n", 'From: ' . mb_encode_mimeheader('Gouyerian Tech (ne pas répondre)', 'UTF-8') . " <$from>"));
  $essai('e_from_contact', fn() => mail($to, 'Test E Gouyerian Tech', "Test E (From contact)\r\n", 'From: contact@gouyerian-tech.fr'));
  }
  $nomb = '=?UTF-8?B?' . base64_encode('Gouyerian Tech (ne pas répondre)') . '?=';
  $sujb = '=?UTF-8?B?' . base64_encode('Test H : réparation GT-2611-001 étape') . '?=';
  $essai('f_nom_base64', fn() => mail($to, 'Test F Gouyerian Tech', "Test F (nom encodé à la main)\r\n", "From: $nomb <$from>", '-f' . $from));
  $essai('g_nom_ascii', fn() => mail($to, 'Test G Gouyerian Tech', "Test G (nom ASCII entre guillemets)\r\n", "From: \"Gouyerian Tech - ne pas repondre\" <$from>", '-f' . $from));
  $essai('h_sujet_accents', fn() => mail($to, $sujb, "Test H (sujet accentué)\r\n", "From: $nomb <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit", '-f' . $from));
  $res['mb_nom'] = mb_encode_mimeheader('Gouyerian Tech (ne pas répondre)', 'UTF-8');
  $res['variantes'] = $v;
}
/* SumUp : clé présente ? code marchand trouvé ? (jamais la clé elle-même) */
$res['sumup'] = ['cle' => !empty($c['sumup_key']), 'marchand' => ($m = sumup_marchand()) !== '' ? substr($m, 0, 2) . '…' . substr($m, -2) : '', 'actif' => sumup_actif()];
if (!empty($c['sumup_key'])) {
  $k = (string)$c['sumup_key'];
  $pre = 'autre'; foreach (['sup_sk_test_', 'sup_sk_live_', 'sup_sk_', 'sup_pk_', 'sk_test_', 'sk_live_', 'pk_', 'sk_'] as $x) if (str_starts_with($k, $x)) { $pre = $x; break; }
  $res['sumup']['cle_forme'] = "commence par $pre, longueur " . strlen($k) . (trim($k) !== $k ? ', espaces autour' : '');
  foreach (['/v0.1/me', '/v0.1/me/merchant-profile', '/v0.1/checkouts', '/v1/merchants'] as $ch) { [$code, $j] = sumup('GET', $ch); $res['sumup']['api'][$ch] = $code . ' ' . mb_substr((string)($j['message'] ?? $j['error_code'] ?? $j['title'] ?? ''), 0, 80); }
}
$f = prive_dir() . '/sumup.log';
if (is_file($f)) $res['sumup']['erreurs'] = array_map('trim', array_slice(file($f) ?: [], -5));
/* Paiement test de 1 € : crée la demande et vérifie que SumUp accepte d'ouvrir la page de paiement */
if (!empty($d['test_paiement']) && sumup_actif()) {
  $jeton = bin2hex(random_bytes(16));
  db()->prepare("INSERT INTO gt_paiements (jeton, nom, email, libelle, montant, statut, cree_le, maj_le) VALUES (?, 'Achot Gouyerian', ?, 'Test du paiement en ligne', 1, 'attente', ?, ?)")
    ->execute([$jeton, (string)($c['mail_reply'] ?? 'contact@gouyerian-tech.fr'), now(), now()]);
  $site = rtrim($c['site'] ?? 'https://gouyerian-tech.fr', '/');
  [$code, $co] = sumup('POST', '/v0.1/checkouts', ['checkout_reference' => 'GT-TEST-' . substr($jeton, 0, 8), 'amount' => 1.0, 'currency' => 'EUR', 'merchant_code' => sumup_marchand(),
    'description' => 'Gouyerian Tech : test', 'return_url' => "$site/api/paiement.php?sumup=$jeton", 'redirect_url' => "$site/paiement/?t=$jeton&retour=1", 'hosted_checkout' => ['enabled' => true]]);
  $res['test_paiement'] = ['sumup' => $code . ' ' . mb_substr((string)($co['message'] ?? $co['error_code'] ?? ''), 0, 120), 'page_sumup' => !empty($co['hosted_checkout_url']), 'lien' => "$site/paiement/?t=$jeton"];
  if (!empty($co['id'])) db()->prepare('UPDATE gt_paiements SET checkout_id = ? WHERE jeton = ?')->execute([(string)$co['id'], $jeton]);
}
$log = prive_dir() . '/mail.log';
$res['journal'] = is_file($log) ? array_map('trim', array_slice(file($log) ?: [], -40)) : [];
out($res);
