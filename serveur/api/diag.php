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
$log = prive_dir() . '/mail.log';
$res['journal'] = is_file($log) ? array_map('trim', array_slice(file($log) ?: [], -40)) : [];
out($res);
