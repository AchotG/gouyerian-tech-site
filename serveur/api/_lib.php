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
    if (is_file($p)) { $c = require $p; $GLOBALS['GT_CFG_DIR'] = dirname($p); return $c; }
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
    $m = 'Le suivi est momentanément indisponible. Réessayez plus tard ou appelez le 06 05 79 48 94.';
    if (!empty($GLOBALS['GT_DIAG'])) {  // page de gestion seulement : indique quel réglage vérifier, sans rien dévoiler
      $code = (string)($e->errorInfo[1] ?? $e->getCode());
      $m = match (true) {
        in_array($code, ['1045', '1044', '1698'], true) => 'Base de données : utilisateur ou mot de passe refusé. Vérifiez les secrets SUIVI_DB_USER et SUIVI_DB_PASSWORD.',
        $code === '1049' => 'Base de données : nom de base inconnu. Vérifiez le secret SUIVI_DB_NAME (de la forme dbs…).',
        in_array($code, ['2002', '2005', '2006'], true) => 'Base de données : serveur introuvable ou injoignable. Vérifiez le secret SUIVI_DB_HOST (de la forme db….hosting-data.io). Une base qui vient d’être créée peut aussi mettre quelques minutes à être prête.',
        default => 'Base de données injoignable (code ' . preg_replace('/[^A-Za-z0-9]/', '', $code) . '). Une base qui vient d’être créée peut mettre quelques minutes à être prête.',
      };
    }
    fail($m, 503);
  }
  schema($pdo);
  return $pdo;
}

const SCHEMA_VERSION = 5;

function schema(PDO $pdo): void {
  /* La structure n'est vérifiée qu'une fois par version (table gt_meta), pas à chaque visite. */
  try {
    $v = (int)$pdo->query("SELECT val FROM gt_meta WHERE cle = 'schema'")->fetchColumn();
    if ($v >= SCHEMA_VERSION) return;
  } catch (Throwable $e) { /* première installation */ }
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
  /* v2 : comptes clients, devis en ligne, garantie */
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_clients (
    id $id,
    email VARCHAR(190) NOT NULL UNIQUE,
    nom VARCHAR(120) NOT NULL DEFAULT '',
    telephone VARCHAR(40) NOT NULL DEFAULT '',
    cree_le DATETIME NOT NULL,
    vu_le DATETIME NOT NULL
  )$opt");
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_codes (
    id $id,
    email VARCHAR(190) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expire DATETIME NOT NULL,
    essais INT NOT NULL DEFAULT 0" . ($sqlite ? '' : ', INDEX (email)') . "
  )$opt");
  $pdo->exec('CREATE TABLE IF NOT EXISTS gt_csessions (jeton CHAR(64) NOT NULL PRIMARY KEY, client_id INT NOT NULL, expire DATETIME NOT NULL)' . $opt);
  $pdo->exec('CREATE TABLE IF NOT EXISTS gt_paniers (client_id INT NOT NULL PRIMARY KEY, contenu TEXT NOT NULL, maj_le DATETIME NOT NULL)' . $opt);
  ajouter_colonne($pdo, 'gt_reparations', 'devis_etat', "VARCHAR(10) NOT NULL DEFAULT ''");
  ajouter_colonne($pdo, 'gt_reparations', 'devis_le', 'DATETIME NULL');
  ajouter_colonne($pdo, 'gt_reparations', 'garantie_mois', 'INT NOT NULL DEFAULT 0');
  $pdo->exec('CREATE TABLE IF NOT EXISTS gt_meta (cle VARCHAR(40) NOT NULL PRIMARY KEY, val VARCHAR(190) NOT NULL)' . $opt);
  /* v3 : demandes centralisées (formulaires du site, espace client), pièces jointes, devis vus */
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_demandes (
    id $id,
    ref VARCHAR(20) NOT NULL UNIQUE,
    type VARCHAR(20) NOT NULL,
    statut VARCHAR(12) NOT NULL DEFAULT 'nouvelle',
    client_id INT NULL,
    nom VARCHAR(120) NOT NULL DEFAULT '',
    telephone VARCHAR(40) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    sujet VARCHAR(250) NOT NULL DEFAULT '',
    details TEXT,
    pj TEXT,
    note_interne TEXT,
    reparation_id INT NULL,
    ip VARCHAR(60) NOT NULL DEFAULT '',
    cree_le DATETIME NOT NULL,
    maj_le DATETIME NOT NULL
  )$opt");
  ajouter_colonne($pdo, 'gt_reparations', 'devis_vu', 'TINYINT NOT NULL DEFAULT 1');
  /* v4 : messagerie entre l'atelier et le client, par réparation */
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_messages (
    id $id,
    reparation_id INT NOT NULL,
    auteur VARCHAR(10) NOT NULL,
    texte TEXT,
    pj TEXT,
    le DATETIME NOT NULL,
    lu TINYINT NOT NULL DEFAULT 0" . ($sqlite ? '' : ', INDEX (reparation_id)') . "
  )$opt");
  if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS gt_m_rep ON gt_messages (reparation_id)');
  ajouter_colonne($pdo, 'gt_messages', 'mail', 'TINYINT NOT NULL DEFAULT 0');   // v5 : e-mail d'avis envoyé au destinataire
  ajouter_colonne($pdo, 'gt_messages', 'lu_le', 'DATETIME NULL');               // v5 : date de lecture
  $pdo->prepare('DELETE FROM gt_meta WHERE cle = ?')->execute(['schema']);
  $pdo->prepare('INSERT INTO gt_meta (cle, val) VALUES (?, ?)')->execute(['schema', (string)SCHEMA_VERSION]);
}

function ajouter_colonne(PDO $pdo, string $table, string $col, string $def): void {
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  if ($sqlite) {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $c) if ($c['name'] === $col) return;
  } else {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $col]);
    if ((int)$s->fetchColumn() > 0) return;
  }
  $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
}

/* Garantie : date de fin (réparation rendue + mois de garantie), ou '' */
function fin_garantie(array $r): string {
  $m = (int)($r['garantie_mois'] ?? 0);
  if ($m <= 0 || $r['etape'] !== 'rendu' || empty($r['clos_le'])) return '';
  return date('Y-m-d', strtotime($r['clos_le'] . " +$m months"));
}

/* Réglages simples de l'atelier (table gt_meta) */
function meta(string $cle, string $defaut = ''): string {
  try { $s = db()->prepare('SELECT val FROM gt_meta WHERE cle = ?'); $s->execute([$cle]); $v = $s->fetchColumn(); return $v === false ? $defaut : (string)$v; }
  catch (Throwable $e) { return $defaut; }
}
function meta_set(string $cle, string $val): void {
  $pdo = db();
  $pdo->prepare('DELETE FROM gt_meta WHERE cle = ?')->execute([$cle]);
  $pdo->prepare('INSERT INTO gt_meta (cle, val) VALUES (?, ?)')->execute([$cle, $val]);
}

/* Prévient l'atelier qu'une demande ou une réponse attend dans la gestion (e-mail court, sans les détails).
   Réglage « notif » : chaque (défaut, un e-mail par demande), regroupe (au plus un e-mail toutes les 3 h), jamais. */
function notifier_atelier(string $titre, string $ligne): bool {
  $mode = meta('notif', 'chaque');
  if ($mode === 'jamais') return false;
  if ($mode === 'regroupe') {
    $last = (int)meta('notif_le', '0');
    if (time() - $last < 3 * 3600) return false;
    $titre = 'Nouvelles demandes à traiter';
  }
  meta_set('notif_le', (string)time());
  $c = cfg();
  $site = rtrim($c['site'] ?? 'https://gouyerian-tech.fr', '/');
  return envoyer_mail($c['mail_reply'] ?? 'contact@gouyerian-tech.fr', "Gouyerian Tech : $titre",
    "Bonjour,\n\n$ligne\n\nTout est à traiter dans votre espace gestion :\n$site/gestion/\n\n(Réglage des e-mails : en bas de la page de gestion.)\n");
}

/* Dossier privé (hors du site) : configuration et pièces jointes */
function prive_dir(): string {
  cfg();
  return $GLOBALS['GT_CFG_DIR'] ?? (__DIR__ . '/../../prive');
}

/* Réponse du client au devis (depuis le suivi ou son espace) ; prévient l'atelier par e-mail. */
function repondre_devis(array $r, string $rep): array {
  if (!in_array($rep, ['accepte', 'refuse'], true)) fail('Réponse inconnue.');
  if ($r['etape'] !== 'devis' || !in_array($r['devis_etat'], ['attente', ''], true)) fail('Ce devis n’attend plus de réponse. Appelez le 06 05 79 48 94 si besoin.');
  $pdo = db();
  $pdo->prepare('UPDATE gt_reparations SET devis_etat = ?, devis_le = ?, maj_le = ? WHERE id = ?')->execute([$rep, now(), now(), $r['id']]);
  $note = $rep === 'accepte' ? 'Devis accepté en ligne par le client.' : 'Devis refusé en ligne par le client.';
  $pdo->prepare('INSERT INTO gt_historique (reparation_id, etape, note, le, mail) VALUES (?, ?, ?, ?, 0)')->execute([$r['id'], 'devis', $note, now()]);
  $pdo->prepare('UPDATE gt_reparations SET devis_vu = 0 WHERE id = ?')->execute([$r['id']]);
  notifier_atelier(($rep === 'accepte' ? 'devis accepté' : 'devis refusé') . " ({$r['numero']})",
    "{$r['nom']} a " . ($rep === 'accepte' ? 'ACCEPTÉ' : 'refusé') . " le devis de la réparation {$r['numero']} ({$r['appareil']}).");
  $s = $pdo->prepare('SELECT * FROM gt_reparations WHERE id = ?');
  $s->execute([$r['id']]);
  return $s->fetch();
}

/* Messages d'une réparation ; $pour = 'client' ou 'atelier' (pièces jointes visibles seulement de l'atelier) */
function messages(int $rid, string $pour): array {
  $s = db()->prepare('SELECT * FROM gt_messages WHERE reparation_id = ? ORDER BY le, id');
  $s->execute([$rid]);
  return array_map(function ($m) use ($pour) {
    $pj = json_decode((string)$m['pj'], true) ?: [];
    $v = ['id' => (int)$m['id'], 'auteur' => $m['auteur'], 'texte' => (string)$m['texte'], 'le' => $m['le'], 'lu' => (bool)(int)$m['lu'],
      'lu_le' => $m['lu_le'] ?? null, 'mail' => (bool)(int)($m['mail'] ?? 0), 'nb_pj' => count($pj),
      'pj' => array_map(fn($f, $i) => ['i' => $i, 'nom' => $f['nom'], 'type' => $f['type'], 'taille' => (int)($f['taille'] ?? 0)]
        + ($pour === 'atelier' ? ['url' => '/api/gestion.php?mpj=' . $m['id'] . '-' . $i] : []), $pj, array_keys($pj))];
    return $v;
  }, $s->fetchAll());
}

/* Enregistre les pièces jointes envoyées ($_FILES['f']) dans /prive/pj ; renvoie la liste à stocker */
function recevoir_pj(int $max = 6): array {
  $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic' => 'heic', 'image/heif' => 'heif',
    'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/3gpp' => '3gp', 'application/pdf' => 'pdf'];
  $pj = [];
  if (empty($_FILES['f']) || !is_array($_FILES['f']['name'])) return $pj;
  $dir = prive_dir() . '/pj';
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail('Pièces jointes impossibles à enregistrer.', 500);
  $fi = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
  $total = 0;
  foreach ($_FILES['f']['name'] as $i => $name) {
    if (count($pj) >= $max) break;
    if (($_FILES['f']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) fail('Un fichier n’a pas pu être envoyé (trop lourd ?).', 413);
    $tmp = $_FILES['f']['tmp_name'][$i];
    $size = (int)$_FILES['f']['size'][$i];
    $total += $size;
    if ($size > 30 * 1048576 || $total > 80 * 1048576) fail('Fichiers trop lourds.', 413);
    $mime = $fi ? (string)$fi->file($tmp) : (string)($_FILES['f']['type'][$i] ?? '');
    if (!isset($types[$mime])) fail('Type de fichier refusé : photos, vidéos ou PDF seulement.', 415);
    $fichier = date('Ym') . '-' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (!move_uploaded_file($tmp, "$dir/$fichier") && !rename($tmp, "$dir/$fichier")) fail('Pièce jointe impossible à enregistrer.', 500);
    @chmod("$dir/$fichier", 0600);
    $pj[] = ['nom' => txt(basename((string)$name), 120), 'type' => $mime, 'taille' => $size, 'fichier' => $fichier];
  }
  return $pj;
}

/* Vue d'une réparation pour le client (jamais de coordonnées ni de note interne). */
function vue_client(array $r): array {
  return [
    'numero' => $r['numero'], 'appareil' => $r['appareil'], 'etape' => $r['etape'],
    'libelle' => ETAPES[$r['etape']][0] ?? $r['etape'], 'phrase' => ETAPES[$r['etape']][1] ?? '',
    'maj_le' => $r['maj_le'], 'cree_le' => $r['cree_le'], 'prix' => $r['prix'],
    'devis' => $r['etape'] === 'devis' ? ($r['devis_etat'] ?: 'attente') : (string)$r['devis_etat'],
    'garantie_mois' => (int)$r['garantie_mois'], 'garantie_fin' => fin_garantie($r),
    'parcours' => array_map(fn($e) => ['etape' => $e, 'libelle' => ETAPES[$e][0]], PARCOURS),
    'historique' => array_map(fn($h) => ['etape' => $h['etape'], 'libelle' => ETAPES[$h['etape']][0] ?? $h['etape'], 'note' => (string)$h['note'], 'le' => $h['le']], historique((int)$r['id'])),
    'avis_email' => (bool)(int)$r['avis_email'],
    'messages' => messages((int)$r['id'], 'client'),
  ];
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
  if (isset($_POST['data'])) {  /* envoi avec fichiers (multipart) : les champs sont dans « data » */
    $d = json_decode((string)$_POST['data'], true);
    return is_array($d) ? $d : [];
  }
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

/* Envoi d'un e-mail texte (UTF-8). En test, la configuration « mail_log » écrit l'e-mail dans un fichier.
   Si un mot de passe SMTP est configuré (smtp_pass), l'e-mail part par le serveur d'envoi IONOS authentifié (meilleure délivrabilité) ;
   sinon par la fonction mail() de l'hébergement. Chaque envoi est noté dans /prive/mail.log, sans donnée personnelle. */
function envoyer_mail(string $to, string $sujet, string $corps): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $c = cfg();
  $from = $c['mail_from'] ?? 'suivi@gouyerian-tech.fr';
  $reply = $c['mail_reply'] ?? 'contact@gouyerian-tech.fr';
  /* Message automatique : expéditeur « Service Client GOUYERIAN TECH » (nom réglable : mail_nom) et rappel « ne pas répondre » en bas de chaque e-mail */
  $corps = rtrim($corps) . "\n\n—\nCet e-mail est envoyé automatiquement, merci de ne pas y répondre.\nPour nous joindre : 06 05 79 48 94 ou contact@gouyerian-tech.fr\n";
  if (!empty($c['mail_log'])) {
    file_put_contents($c['mail_log'], json_encode(['to' => $to, 'sujet' => $sujet, 'corps' => $corps], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return true;
  }
  /* Encodage fait à la main : mb_encode_mimeheader() coupait le nom au milieu des parenthèses et l'hébergement refusait l'e-mail */
  $enc = fn(string $t) => preg_match('/[^\x20-\x7e]/', $t) ? '=?UTF-8?B?' . base64_encode($t) . '?=' : $t;
  $nom = '"' . str_replace(['"', '\\', "\r", "\n"], '', (string)($c['mail_nom'] ?? 'Service Client GOUYERIAN TECH')) . '"';
  if (preg_match('/[^\x20-\x7e]/', $nom)) $nom = $enc(trim($nom, '"'));
  $sujet_enc = $enc($sujet);
  $domaine = substr(strrchr($from, '@') ?: '@gouyerian-tech.fr', 1);
  $entetes = [
    "From: $nom <$from>",
    "Reply-To: $reply",
    'Date: ' . date('r'),
    'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domaine>",
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: Gouyerian Tech suivi',
  ];
  $corps_crlf = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $corps);
  $err = '';
  if (!empty($c['smtp_pass'])) {
    $methode = 'smtp';
    $ok = smtp_envoyer($c, $from, $to, array_merge(["To: <$to>", "Subject: $sujet_enc"], $entetes), $corps_crlf, $err);
  } else {
    $methode = 'mail()';
    error_clear_last();
    $ok = mail($to, $sujet_enc, $corps_crlf, implode("\r\n", $entetes), '-f' . $from);
    if (!$ok) $err = (error_get_last()['message'] ?? 'mail() a renvoyé false');
  }
  journal_mail($to, $sujet, $methode, $ok, $err);
  return $ok;
}

/* Journal des envois : date, domaine du destinataire, type d'e-mail, méthode, résultat. Ni adresse complète ni contenu. */
function journal_mail(string $to, string $sujet, string $methode, bool $ok, string $err): void {
  $type = preg_match('/code de connexion/i', $sujet) ? 'code' : (preg_match('/^Gouyerian Tech :/', $sujet) ? 'atelier' : (preg_match('/message/i', $sujet) ? 'message' : (preg_match('/^Test/', $sujet) ? 'test' : 'suivi')));
  $ligne = date('Y-m-d H:i:s') . ' | @' . substr(strrchr($to, '@') ?: '@?', 1) . " | $type | $methode | " . ($ok ? 'OK' : 'ECHEC ' . mb_substr(preg_replace('/\s+/', ' ', $err), 0, 200)) . "\n";
  $f = prive_dir() . '/mail.log';
  $old = is_file($f) ? array_slice(file($f) ?: [], -300) : [];
  @file_put_contents($f, implode('', $old) . $ligne, LOCK_EX);
  @chmod($f, 0600);
}

/* Envoi SMTP authentifié (SSL 465 ou STARTTLS 587), sans bibliothèque externe */
function smtp_envoyer(array $c, string $from, string $to, array $entetes, string $corps, string &$err): bool {
  $hote = $c['smtp_host'] ?? 'smtp.ionos.fr';
  $port = (int)($c['smtp_port'] ?? 465);
  $user = $c['smtp_user'] ?? $from;
  $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "$hote:$port", $no, $msg, 20);
  if (!$fp) { $err = "connexion $hote:$port impossible ($msg)"; return false; }
  stream_set_timeout($fp, 20);
  $lire = function () use ($fp): string { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
  $cmd = function (string $ligne, array $attendu) use ($fp, $lire, &$err): bool {
    if ($ligne !== '') fwrite($fp, $ligne . "\r\n");
    $r = $lire();
    if (!in_array((int)substr($r, 0, 3), $attendu, true)) { $err = trim(preg_replace('/^AUTH.*$/m', 'AUTH …', $ligne) ?: '') . ' → ' . trim($r); return false; }
    return true;
  };
  $ok = $cmd('', [220]) && $cmd('EHLO gouyerian-tech.fr', [250]);
  if ($ok && $port !== 465) {
    $ok = $cmd('STARTTLS', [220]) && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd('EHLO gouyerian-tech.fr', [250]);
  }
  $ok = $ok && $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode($user), [334]) && $cmd(base64_encode((string)$c['smtp_pass']), [235])
    && $cmd("MAIL FROM:<$from>", [250]) && $cmd("RCPT TO:<$to>", [250, 251]) && $cmd('DATA', [354]);
  if ($ok) {
    $data = implode("\r\n", $entetes) . "\r\n\r\n" . preg_replace('/^\./m', '..', $corps);
    $ok = $cmd($data . "\r\n.", [250]);
  }
  @fwrite($fp, "QUIT\r\n");
  fclose($fp);
  return $ok;
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
    . ($etape === 'devis' ? "\n\nAcceptez ou refusez le devis en un clic : " . lien_suivi($r) : '')
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
  foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) supprimer_reparation((int)$id);
}

/* Efface une réparation avec son historique, ses messages et leurs pièces jointes */
function supprimer_reparation(int $id): void {
  $pdo = db();
  $s = $pdo->prepare('SELECT pj FROM gt_messages WHERE reparation_id = ?');
  $s->execute([$id]);
  foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $pj) {
    foreach (json_decode((string)$pj, true) ?: [] as $f) { $p = prive_dir() . '/pj/' . basename($f['fichier'] ?? ''); if (is_file($p)) @unlink($p); }
  }
  foreach (['gt_messages', 'gt_historique'] as $t) $pdo->prepare("DELETE FROM $t WHERE reparation_id = ?")->execute([$id]);
  $pdo->prepare('DELETE FROM gt_reparations WHERE id = ?')->execute([$id]);
}

/* E-mail au client quand l'atelier lui écrit (dès qu'une adresse e-mail est connue) */
function mail_message(array $r, string $texte, int $nb_pj = 0): bool {
  if ($r['email'] === '') return false;
  $site = rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/');
  $extrait = mb_substr($texte, 0, 400) . (mb_strlen($texte) > 400 ? '…' : '');
  $corps = 'Bonjour ' . prenom($r['nom']) . ",\n\n"
    . "L’atelier Gouyerian Tech vous a envoyé un message au sujet de votre {$r['appareil']} (réparation {$r['numero']}) :\n\n"
    . ($extrait !== '' ? "« $extrait »\n\n" : '')
    . ($nb_pj ? ($nb_pj > 1 ? "$nb_pj fichiers sont joints" : 'Un fichier est joint') . " au message, à ouvrir sur votre page de suivi.\n\n" : '')
    . "Lire le message et répondre : " . lien_suivi($r) . "\n(ou depuis votre espace client : $site/mon-espace/)\n"
    . "Merci de répondre depuis cette page et non à cet e-mail."
    . "\n\nNuméro de suivi : {$r['numero']} · code : {$r['code']}"
    . "\n\nGouyerian Tech\n1 route de Segonzac, 16130 Saint-Fort-sur-le-Né\n06 05 79 48 94 · $site\n";
  return envoyer_mail($r['email'], "Nouveau message de l’atelier · réparation {$r['numero']}", $corps);
}

function nettoyer_demandes(): void {
  $pdo = db();
  $lim = date('Y-m-d H:i:s', strtotime('-' . CONSERVATION_MOIS . ' months'));
  $s = $pdo->prepare('SELECT id, pj FROM gt_demandes WHERE maj_le < ?');
  $s->execute([$lim]);
  foreach ($s->fetchAll() as $d) {
    foreach (json_decode((string)$d['pj'], true) ?: [] as $f) { $p = prive_dir() . '/pj/' . basename($f['fichier'] ?? ''); if (is_file($p)) @unlink($p); }
    $pdo->prepare('DELETE FROM gt_demandes WHERE id = ?')->execute([$d['id']]);
  }
}

const STATUTS_DEMANDE = ['nouvelle' => 'Reçue', 'en_cours' => 'En cours de traitement', 'traitee' => 'Traitée', 'archivee' => 'Traitée'];

function historique(int $id): array {
  $s = db()->prepare('SELECT etape, note, le, mail FROM gt_historique WHERE reparation_id = ? ORDER BY le, id');
  $s->execute([$id]);
  return $s->fetchAll();
}
