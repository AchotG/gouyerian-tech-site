<?php
/* Gouyerian Tech : fonctions communes du suivi des réparations (base MySQL chez IONOS).
   Ce fichier n'affiche rien et ne s'ouvre pas directement (voir api/.htaccess). */
declare(strict_types=1);
date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

const ETAPES = [
  'recu'       => ['Appareil reçu', 'Votre appareil est bien arrivé à l’atelier. Nous allons l’examiner.'],
  'diagnostic' => ['Diagnostic en cours', 'Nous recherchons l’origine de la panne.'],
  'devis'      => ['Devis envoyé', 'Le devis vous a été communiqué : la réparation commence dès votre accord.'],
  'piece'      => ['En attente de pièce', 'La pièce nécessaire est commandée. Nous vous prévenons dès son arrivée.'],
  'reparation' => ['Réparation en cours', 'Votre appareil est entre nos mains.'],
  'prete'      => ['Prêt à récupérer', 'Votre appareil est réparé et vous attend à l’atelier (ou nous convenons d’une livraison).'],
  'rendu'      => ['Rendu', 'L’appareil vous a été rendu. Merci de votre confiance !'],
  'annule'     => ['Annulé', 'Cette réparation a été annulée.'],
];
const PARCOURS = ['recu', 'diagnostic', 'devis', 'piece', 'reparation', 'prete', 'rendu'];
const CLOS = ['rendu', 'annule'];
const CONSERVATION_MOIS = 24;  // données personnelles effacées 24 mois après la fin de la réparation

function cfg(): array {
  static $c = null;
  if ($c !== null) return $c;
  $paths = array_filter([getenv('GT_CONFIG') ?: null, __DIR__ . '/../../prive/gt-config.php',
    !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/../prive/gt-config.php' : null]);
  foreach ($paths as $p) {
    if (is_file($p)) { $c = require $p; return $c; }
  }
  $c = [];
  return $c;
}

function out(array $data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function fail(string $msg, int $code = 400): never { out(['ok' => false, 'erreur' => $msg], $code); }

function https_ok(): bool {
  if (!empty(cfg()['dev'])) return true;
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
      || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = cfg();
  if (empty($c['dsn'])) fail('Le suivi en ligne n’est pas encore activé. Appelez le 06 05 79 48 94.', 503);
  try {
    $pdo = new PDO($c['dsn'], $c['db_user'] ?? null, $c['db_pass'] ?? null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (Throwable $e) {
    error_log('GT db: ' . $e->getMessage());
    fail('Le suivi est momentanément indisponible. Réessayez plus tard ou appelez le 06 05 79 48 94.', 503);
  }
  schema($pdo);
  return $pdo;
}

function schema(PDO $pdo): void {
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $opt = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_reparations (
    id $id,
    numero VARCHAR(20) NOT NULL UNIQUE,
    code VARCHAR(12) NOT NULL,
    nom VARCHAR(120) NOT NULL,
    telephone VARCHAR(40) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    avis_email TINYINT NOT NULL DEFAULT 0,
    stop_token VARCHAR(64) NOT NULL,
    appareil VARCHAR(160) NOT NULL,
    panne TEXT,
    prix VARCHAR(60) NOT NULL DEFAULT '',
    note_interne TEXT,
    etape VARCHAR(20) NOT NULL,
    cree_le DATETIME NOT NULL,
    maj_le DATETIME NOT NULL,
    clos_le DATETIME NULL
  )$opt");
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_historique (
    id $id,
    reparation_id INT NOT NULL,
    etape VARCHAR(20) NOT NULL,
    note TEXT,
    le DATETIME NOT NULL,
    mail TINYINT NOT NULL DEFAULT 0" . ($sqlite ? '' : ', INDEX (reparation_id)') . "
  )$opt");
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_essais (
    id $id,
    cle VARCHAR(100) NOT NULL,
    le DATETIME NOT NULL" . ($sqlite ? '' : ', INDEX (cle, le)') . "
  )$opt");
  if ($sqlite) {
    $pdo->exec('CREATE INDEX IF NOT EXISTS gt_h_rep ON gt_historique (reparation_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS gt_e_cle ON gt_essais (cle, le)');
  }
}

function now(): string { return date('Y-m-d H:i:s'); }

function ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0', 0, 60); }

/* Limite le nombre d'essais (mauvais codes, mots de passe) : au-delà, on bloque quelques minutes. */
function limite(string $cle, int $max, int $minutes): void {
  $pdo = db();
  $pdo->prepare('DELETE FROM gt_essais WHERE le < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
  $s = $pdo->prepare('SELECT COUNT(*) FROM gt_essais WHERE cle = ? AND le > ?');
  $s->execute([$cle, date('Y-m-d H:i:s', time() - $minutes * 60)]);
  if ((int)$s->fetchColumn() >= $max) fail("Trop d’essais. Réessayez dans $minutes minutes.", 429);
}

function essai(string $cle): void {
  db()->prepare('INSERT INTO gt_essais (cle, le) VALUES (?, ?)')->execute([$cle, now()]);
}

function entree(): array {
  $raw = file_get_contents('php://input') ?: '';
  if (strlen($raw) > 20000) fail('Demande trop longue.', 413);
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}

function txt($v, int $max): string {
  $v = is_scalar($v) ? (string)$v : '';
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
  return mb_substr(trim($v), 0, $max);
}

function norm_numero(string $n): string { return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $n) ?? ''); }
function norm_code(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c) ?? ''); }

function code_aleatoire(int $n = 6): string {
  $a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  $s = '';
  for ($i = 0; $i < $n; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
  return $s;
}

function lien_suivi(array $r): string {
  return rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/') . '/suivi/#' . $r['numero'] . '.' . $r['code'];
}

function prenom(string $nom): string {
  $p = preg_split('/\s+/', trim($nom)) ?: [''];
  return $p[0];
}

/* Envoi d'un e-mail texte (UTF-8). En test, la configuration « mail_log » écrit l'e-mail dans un fichier. */
function envoyer_mail(string $to, string $sujet, string $corps): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $c = cfg();
  $from = $c['mail_from'] ?? 'suivi@gouyerian-tech.fr';
  $reply = $c['mail_reply'] ?? 'gouyerian.tech@gmail.com';
  if (!empty($c['mail_log'])) {
    file_put_contents($c['mail_log'], json_encode(['to' => $to, 'sujet' => $sujet, 'corps' => $corps], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return true;
  }
  $headers = implode("\r\n", [
    'From: ' . mb_encode_mimeheader('Gouyerian Tech', 'UTF-8') . " <$from>",
    "Reply-To: $reply",
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: Gouyerian Tech suivi',
  ]);
  return mail($to, mb_encode_mimeheader($sujet, 'UTF-8'), str_replace("\n", "\r\n", $corps), $headers, '-f' . $from);
}

function pied_mail(array $r): string {
  $site = rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/');
  return "\n\nSuivre votre réparation : " . lien_suivi($r)
    . "\nNuméro de suivi : {$r['numero']} · code : {$r['code']}"
    . "\n\nGouyerian Tech\n1 route de Segonzac, 16130 Saint-Fort-sur-le-Né\n06 05 79 48 94 · $site"
    . "\n\nVous recevez cet e-mail car vous avez demandé à être informé de l’avancée de votre réparation."
    . "\nNe plus recevoir ces e-mails : $site/api/suivi.php?stop={$r['stop_token']}\n";
}

function mail_etape(array $r, string $etape, string $note): bool {
  if (!(int)$r['avis_email'] || $r['email'] === '') return false;
  [$lib, $phrase] = ETAPES[$etape];
  $sujet = "Votre réparation {$r['numero']} : $lib";
  $corps = 'Bonjour ' . prenom($r['nom']) . ",\n\n"
    . "Votre {$r['appareil']} : $lib.\n$phrase"
    . ($note !== '' ? "\n\nMessage de l’atelier : $note" : '')
    . pied_mail($r);
  return envoyer_mail($r['email'], $sujet, $corps);
}

function mail_bienvenue(array $r): bool {
  if (!(int)$r['avis_email'] || $r['email'] === '') return false;
  $sujet = "Suivi de votre réparation {$r['numero']}";
  $corps = 'Bonjour ' . prenom($r['nom']) . ",\n\n"
    . "Votre {$r['appareil']} est enregistré à l’atelier Gouyerian Tech.\n"
    . "Vous recevrez un e-mail à chaque étape de la réparation, et vous pouvez suivre son avancée à tout moment sur le site."
    . pied_mail($r);
  return envoyer_mail($r['email'], $sujet, $corps);
}

/* RGPD : efface les réparations terminées depuis plus de CONSERVATION_MOIS mois. */
function nettoyer(): void {
  $pdo = db();
  $lim = date('Y-m-d H:i:s', strtotime('-' . CONSERVATION_MOIS . ' months'));
  $ids = $pdo->prepare('SELECT id FROM gt_reparations WHERE clos_le IS NOT NULL AND clos_le < ?');
  $ids->execute([$lim]);
  foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $pdo->prepare('DELETE FROM gt_historique WHERE reparation_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM gt_reparations WHERE id = ?')->execute([$id]);
  }
}

function historique(int $id): array {
  $s = db()->prepare('SELECT etape, note, le, mail FROM gt_historique WHERE reparation_id = ? ORDER BY le, id');
  $s->execute([$id]);
  return $s->fetchAll();
}
