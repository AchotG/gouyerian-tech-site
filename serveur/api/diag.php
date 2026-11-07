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
  $res['envoi'] = envoyer_mail($to, 'Test du suivi Gouyerian Tech', "Bonjour,\n\nCeci est un e-mail de test envoyé par le diagnostic du site gouyerian-tech.fr, le " . date('d/m/Y à H:i') . ".\n");
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
$log = prive_dir() . '/mail.log';
$res['journal'] = is_file($log) ? array_map('trim', array_slice(file($log) ?: [], -40)) : [];
out($res);
