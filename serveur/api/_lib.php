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

const SCHEMA_VERSION = 8;

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
  ajouter_colonne($pdo, 'gt_messages', 'demande_id', 'INT NOT NULL DEFAULT 0');  // v6 : conversation d'une demande (reparation_id = 0)
  ajouter_colonne($pdo, 'gt_messages', 'client_id', 'INT NOT NULL DEFAULT 0');   // v6 : conversation générale d'un client (sans réparation ni demande)
  /* v8 : paiements en ligne SumUp (une demande de paiement = un lien /paiement/?t=…) */
  $pdo->exec("CREATE TABLE IF NOT EXISTS gt_paiements (
    id $id,
    jeton VARCHAR(40) NOT NULL UNIQUE,
    reparation_id INT NOT NULL DEFAULT 0,
    demande_id INT NOT NULL DEFAULT 0,
    client_id INT NOT NULL DEFAULT 0,
    nom VARCHAR(120) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    libelle VARCHAR(200) NOT NULL DEFAULT '',
    montant DECIMAL(10,2) NOT NULL,
    statut VARCHAR(12) NOT NULL DEFAULT 'attente',
    checkout_id VARCHAR(64) NOT NULL DEFAULT '',
    transaction_code VARCHAR(40) NOT NULL DEFAULT '',
    cree_le DATETIME NOT NULL,
    maj_le DATETIME NOT NULL,
    paye_le DATETIME NULL
  )$opt");
  /* v7 : les conversations des demandes devenues réparations rejoignent la conversation de la réparation */
  foreach ($pdo->query('SELECT id, reparation_id FROM gt_demandes WHERE reparation_id IS NOT NULL AND reparation_id > 0')->fetchAll() as $x) {
    $pdo->prepare('UPDATE gt_messages SET reparation_id = ?, demande_id = 0 WHERE reparation_id = 0 AND demande_id = ?')->execute([(int)$x['reparation_id'], (int)$x['id']]);
  }
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
  return mail_pro($c['mail_reply'] ?? 'contact@gouyerian-tech.fr', "Gouyerian Tech : $titre", [
    'atelier' => true, 'pre' => $ligne, 'etiquette' => 'À traiter', 'titre' => ucfirst($titre),
    'paras' => [$ligne], 'bouton' => ['Ouvrir la gestion', "$site/gestion/"],
    'note' => 'Réglage de ces e-mails (à chaque demande, regroupés, jamais) : onglet Demandes de la gestion.']);
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
/* Conversations : une par réparation ['r', id], par demande ['d', id] ou générale par client ['c', id] */
function fil_where(array $fil): array {
  [$t, $id] = $fil;
  if ($t === 'r') return ['reparation_id = ?', [$id]];
  if ($t === 'd') return ['reparation_id = 0 AND demande_id = ?', [$id]];
  return ['reparation_id = 0 AND demande_id = 0 AND client_id = ?', [$id]];
}

function fil_cols(array $fil): array {
  return ['reparation_id' => $fil[0] === 'r' ? $fil[1] : 0, 'demande_id' => $fil[0] === 'd' ? $fil[1] : 0, 'client_id' => $fil[0] === 'c' ? $fil[1] : (int)($fil[2] ?? 0)];
}

function ajouter_message(array $fil, string $auteur, string $texte, array $pj, bool $mail = false): void {
  $c = fil_cols($fil);
  db()->prepare('INSERT INTO gt_messages (reparation_id, demande_id, client_id, auteur, texte, pj, le, lu, mail) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
    ->execute([$c['reparation_id'], $c['demande_id'], $c['client_id'], $auteur, $texte, json_encode($pj, JSON_UNESCAPED_UNICODE), now(), $mail ? 1 : 0]);
}

function marquer_lus(array $fil, string $auteur): void {
  [$w, $p] = fil_where($fil);
  db()->prepare("UPDATE gt_messages SET lu = 1, lu_le = ? WHERE $w AND auteur = ? AND lu = 0")->execute(array_merge([now()], $p, [$auteur]));
}

/* Une demande devient une réparation : sa conversation continue dans celle de la réparation (même historique) */
function fusionner_demande(int $did, int $rid): void {
  db()->prepare('UPDATE gt_messages SET reparation_id = ?, demande_id = 0 WHERE reparation_id = 0 AND demande_id = ?')->execute([$rid, $did]);
  db()->prepare('UPDATE gt_paiements SET reparation_id = ? WHERE reparation_id = 0 AND demande_id = ?')->execute([$rid, $did]);
}

/* ---------- Paiement en ligne SumUp (page de paiement hébergée par SumUp) ---------- */
const STATUTS_PAIEMENT = ['attente' => 'En attente', 'paye' => 'Payé', 'annule' => 'Annulé'];
function sumup_actif(): bool { $c = cfg(); return !empty($c['sumup_key']) && !empty($c['sumup_merchant']); }
function sumup(string $methode, string $chemin, ?array $corps = null): array {
  $c = cfg();
  $url = rtrim($c['sumup_api'] ?? 'https://api.sumup.com', '/') . $chemin;
  $ctx = stream_context_create(['http' => ['method' => $methode, 'ignore_errors' => true, 'timeout' => 20,
    'header' => "Authorization: Bearer {$c['sumup_key']}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
    'content' => $corps !== null ? json_encode($corps, JSON_UNESCAPED_UNICODE) : '']]);
  $rep = @file_get_contents($url, false, $ctx);
  $code = 0;
  foreach ($http_response_header ?? [] as $hd) if (preg_match('#^HTTP/\S+\s+(\d+)#', $hd, $m)) $code = (int)$m[1];
  $j = json_decode((string)$rep, true);
  return [$code, is_array($j) ? $j : []];
}
function paiement_par_jeton(string $jeton): ?array {
  if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) return null;
  $s = db()->prepare('SELECT * FROM gt_paiements WHERE jeton = ?');
  $s->execute([$jeton]);
  return $s->fetch() ?: null;
}
function lien_paiement(array $p): string { return rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/') . '/paiement/?t=' . $p['jeton']; }
function vue_paiement(array $p): array {
  return ['jeton' => $p['jeton'], 'libelle' => $p['libelle'], 'montant' => (float)$p['montant'], 'statut' => $p['statut'],
    'statut_libelle' => STATUTS_PAIEMENT[$p['statut']] ?? $p['statut'], 'cree_le' => $p['cree_le'], 'paye_le' => $p['paye_le'], 'lien' => lien_paiement($p),
    'transaction' => $p['transaction_code']];
}
function paiements_de(string $col, int $id): array {
  if (!in_array($col, ['reparation_id', 'demande_id', 'client_id'], true)) return [];
  $s = db()->prepare("SELECT * FROM gt_paiements WHERE $col = ? AND statut <> 'annule' ORDER BY id DESC");
  $s->execute([$id]);
  return array_map('vue_paiement', $s->fetchAll());
}
/* Relit l'état chez SumUp ; quand le paiement est fait : statut « payé », e-mails au client et à l'atelier, message dans la conversation */
function paiement_verifier(array $p): array {
  if ($p['statut'] !== 'attente' || $p['checkout_id'] === '' || !sumup_actif()) return $p;
  [$code, $co] = sumup('GET', '/v0.1/checkouts/' . rawurlencode($p['checkout_id']));
  if ($code !== 200 || ($co['status'] ?? '') !== 'PAID') return $p;
  $tx = '';
  foreach ($co['transactions'] ?? [] as $t) if (($t['status'] ?? '') === 'SUCCESSFUL') $tx = (string)($t['transaction_code'] ?? '');
  $pdo = db();
  $u = $pdo->prepare("UPDATE gt_paiements SET statut = 'paye', paye_le = ?, maj_le = ?, transaction_code = ? WHERE id = ? AND statut = 'attente'");
  $u->execute([now(), now(), $tx, $p['id']]);
  if ($u->rowCount() !== 1) return paiement_par_jeton($p['jeton']) ?? $p;
  $p = paiement_par_jeton($p['jeton']);
  $m = number_format((float)$p['montant'], 2, ',', ' ') . ' €';
  $fil = (int)$p['reparation_id'] ? ['r', (int)$p['reparation_id']] : ((int)$p['demande_id'] ? ['d', (int)$p['demande_id'], (int)$p['client_id']] : ((int)$p['client_id'] ? ['c', (int)$p['client_id']] : null));
  if ($fil) ajouter_message($fil, 'atelier', "✅ Paiement reçu : $m ({$p['libelle']}). Merci !", []);
  if ($p['email'] !== '') mail_pro($p['email'], "Paiement reçu : $m", ['pre' => "Nous avons bien reçu votre paiement de $m.", 'etiquette' => 'Paiement en ligne', 'titre' => 'Paiement reçu, merci !',
    'paras' => [($p['nom'] !== '' ? 'Bonjour ' . prenom($p['nom']) : 'Bonjour') . ',', "Nous avons bien reçu votre paiement par carte de $m pour : {$p['libelle']}."],
    'infos' => array_values(array_filter([['Montant', $m], ['Date', date('d/m/Y à H:i')], $tx !== '' ? ['Transaction SumUp', $tx] : null])),
    'note' => 'Gardez cet e-mail comme justificatif. La facture vous est remise avec votre appareil.']);
  notifier_atelier("paiement reçu $m", "Paiement en ligne reçu : $m de " . ($p['nom'] ?: $p['email']) . " ({$p['libelle']}).");
  return $p;
}

function messages(int $rid, string $pour): array { return messages_fil(['r', $rid], $pour); }

function messages_fil(array $fil, string $pour): array {
  [$w, $p] = fil_where($fil);
  $s = db()->prepare("SELECT * FROM gt_messages WHERE $w ORDER BY le, id");
  $s->execute($p);
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
/* Envoie un fichier joint au navigateur, avec prise en charge des plages (Range) :
   sans elles, Safari et l'iPhone refusent de lire les vidéos. ?dl=1 force le téléchargement. */
/* Clé secrète du serveur pour signer les liens temporaires (créée une fois dans le dossier privé) */
function jeton_secret(): string {
  $f = prive_dir() . '/cle-liens';
  if (!is_file($f)) { @file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0600); }
  return (string)file_get_contents($f);
}

function envoyer_fichier(string $path, string $type, string $nom): void {
  $taille = filesize($path);
  $debut = 0; $fin = $taille - 1;
  if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $debut = max(0, $taille - (int)$m[2]); }
    else { $debut = (int)$m[1]; if ($m[2] !== '') $fin = min($fin, (int)$m[2]); }
    if ($debut > $fin || $debut >= $taille) { http_response_code(416); header("Content-Range: bytes */$taille"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $debut-$fin/$taille");
  }
  header('Content-Type: ' . $type);
  header('Accept-Ranges: bytes');
  header('Content-Length: ' . ($fin - $debut + 1));
  header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^\w.\- ]/u', '_', $nom) . '"');
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('X-Content-Type-Options: nosniff');
  $fp = fopen($path, 'rb');
  fseek($fp, $debut);
  $reste = $fin - $debut + 1;
  while ($reste > 0 && !feof($fp)) { $b = fread($fp, (int)min(262144, $reste)); echo $b; $reste -= strlen($b); flush(); }
  fclose($fp);
  exit;
}

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
    'paiements' => paiements_de('reparation_id', (int)$r['id']),
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
function envoyer_mail(string $to, string $sujet, string $corps, string $html = '', array $images = []): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $c = cfg();
  $from = $c['mail_from'] ?? 'suivi@gouyerian-tech.fr';
  $reply = $c['mail_reply'] ?? 'contact@gouyerian-tech.fr';
  /* Message automatique : expéditeur « Service Client GOUYERIAN TECH » (nom réglable : mail_nom) et rappel « ne pas répondre » en bas de chaque e-mail */
  $corps = rtrim($corps) . "\n\n—\nCet e-mail est envoyé automatiquement, merci de ne pas y répondre.\nPour nous joindre : 06 05 79 48 94 ou contact@gouyerian-tech.fr\n";
  if (!empty($c['mail_log'])) {
    file_put_contents($c['mail_log'], json_encode(['to' => $to, 'sujet' => $sujet, 'corps' => $corps, 'html' => $html !== ''], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    if ($html !== '' && !empty($c['mail_html_dir'])) @file_put_contents($c['mail_html_dir'] . '/' . date('His') . '-' . substr(md5($sujet . microtime()), 0, 6) . '.html', $html);
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
    'X-Mailer: Gouyerian Tech',
  ];
  if ($html === '') {
    array_push($entetes, 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit');
    $corps_crlf = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $corps);
  } else {
    /* Version mise en forme (HTML) + version texte pour les messageries qui n'affichent pas le HTML */
    $b = 'gt-' . bin2hex(random_bytes(10));
    $alt = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($corps))
      . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";
    if (!$images) {
      $entetes[] = "Content-Type: multipart/alternative; boundary=\"$b\"";
      $corps_crlf = $alt;
    } else {
      /* Images intégrées à l'e-mail (en-tête) : affichées même par les messageries qui bloquent les images distantes (webmail IONOS…) */
      $rb = 'gtr-' . bin2hex(random_bytes(10));
      $entetes[] = "Content-Type: multipart/related; boundary=\"$rb\"; type=\"multipart/alternative\"";
      $corps_crlf = "--$rb\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $alt;
      foreach ($images as $cid => [$chemin, $type]) {
        $nomf = basename($chemin);
        $corps_crlf .= "--$rb\r\nContent-Type: $type; name=\"$nomf\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\nContent-Disposition: inline; filename=\"$nomf\"\r\n\r\n"
          . chunk_split(base64_encode((string)file_get_contents($chemin)));
      }
      $corps_crlf .= "--$rb--\r\n";
    }
  }
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

/* ---------- E-mails mis en forme (charte Gouyerian Tech) ----------
   mail_pro() construit la version HTML (tableaux et styles en ligne, lisibles dans Gmail, Outlook, Apple Mail) et la version texte.
   $m : pre (aperçu dans la boîte), etiquette, titre, paras[], citation, code, bouton [libellé, lien], lien2 [libellé, lien],
        infos [[clé, valeur]…], etapes [libellés…] + etape_i (étape en cours), note, stop (lien de désinscription), atelier (e-mail interne) */
function mail_pro(string $to, string $sujet, array $m): bool {
  $site = rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/');
  $h = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
  $acc = '#2563eb'; $navy = '#0b1426'; $mut = '#5b6578'; $line = '#e3e9f2';
  $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
  /* --- texte --- */
  $t = [];
  if (!empty($m['titre'])) $t[] = $m['titre'] . "\n" . str_repeat('—', min(40, mb_strlen($m['titre'])));
  foreach ($m['paras'] ?? [] as $p) $t[] = $p;
  if (!empty($m['citation'])) $t[] = '« ' . $m['citation'] . ' »';
  if (!empty($m['code'])) $t[] = 'Votre code : ' . $m['code'];
  if (!empty($m['etapes'])) $t[] = 'Avancée : ' . implode(' › ', array_map(fn($e, $i) => $i === ($m['etape_i'] ?? -1) ? "[$e]" : $e, $m['etapes'], array_keys($m['etapes'])));
  if (!empty($m['infos'])) $t[] = implode("\n", array_map(fn($x) => $x[0] . ' : ' . $x[1], $m['infos']));
  if (!empty($m['bouton'])) $t[] = $m['bouton'][0] . ' : ' . $m['bouton'][1];
  if (!empty($m['lien2'])) $t[] = $m['lien2'][0] . ' : ' . $m['lien2'][1];
  if (!empty($m['note'])) $t[] = $m['note'];
  $t[] = "Service Client GOUYERIAN TECH\n1 route de Segonzac, 16130 Saint-Fort-sur-le-Né\n06 05 79 48 94 · contact@gouyerian-tech.fr · $site";
  if (!empty($m['stop'])) $t[] = 'Ne plus recevoir les e-mails de suivi : ' . $m['stop'];
  $texte = implode("\n\n", $t);
  /* --- HTML --- */
  $btn = fn($b, $prim = true) => '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 2px"><tr><td style="border-radius:12px;background:' . ($prim ? "$acc;background-image:linear-gradient(135deg,#2563eb,#06b6d4)" : '#ffffff;border:1px solid ' . $line) . '">'
    . '<a href="' . $h($b[1]) . '" style="display:inline-block;padding:14px 26px;' . $font . ';font-size:15px;font-weight:700;color:' . ($prim ? '#ffffff' : $acc) . ';text-decoration:none;border-radius:12px">' . $h($b[0]) . ' &rarr;</a></td></tr></table>';
  $c = '';
  if (!empty($m['etiquette'])) $c .= '<p style="margin:0 0 10px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:' . $acc . ';font-weight:700">' . $h($m['etiquette']) . '</p>';
  if (!empty($m['titre'])) $c .= '<h1 style="margin:0 0 16px;' . $font . ';font-size:24px;line-height:1.25;color:' . $navy . ';font-weight:800">' . $h($m['titre']) . '</h1>';
  if (!empty($m['etapes'])) {
    $n = count($m['etapes']); $ci = (int)($m['etape_i'] ?? -1); $cells = '';
    foreach ($m['etapes'] as $i => $e) {
      $on = $i <= $ci;
      $cells .= '<td style="padding:0 2px"><div style="height:6px;border-radius:3px;background:' . ($on ? "$acc;background-image:linear-gradient(90deg,#2563eb,#06b6d4)" : $line) . '"></div></td>';
    }
    $suite = $m['etapes'][$ci + 1] ?? null;
    $c .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 6px;table-layout:fixed"><tr>' . $cells . '</tr></table>'
      . '<p style="margin:0 0 20px;' . $font . ';font-size:13px;color:' . $mut . '">Étape <b style="color:' . $navy . '">' . ($ci + 1) . ' sur ' . $n . '</b> · <b style="color:' . $acc . '">' . $h($m['etapes'][$ci] ?? '') . '</b>' . ($suite ? ' · ensuite : ' . $h($suite) : '') . '</p>';
  }
  foreach ($m['paras'] ?? [] as $p) $c .= '<p style="margin:0 0 14px;' . $font . ';font-size:16px;line-height:1.6;color:#253047">' . nl2br($h($p)) . '</p>';
  if (!empty($m['citation'])) $c .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 18px"><tr><td style="background:#f2f6fd;border-left:4px solid ' . $acc . ';border-radius:4px 12px 12px 4px;padding:14px 18px;' . $font . ';font-size:16px;line-height:1.55;color:' . $navy . '">' . nl2br($h($m['citation'])) . '</td></tr></table>';
  if (!empty($m['code'])) $c .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 18px"><tr><td align="center" style="background:#f2f6fd;border:1px dashed #b9c9e6;border-radius:14px;padding:18px"><div style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:34px;letter-spacing:10px;font-weight:800;color:' . $navy . '">' . $h($m['code']) . '</div><div style="margin-top:6px;' . $font . ';font-size:12px;color:' . $mut . '">Valable 15 minutes</div></td></tr></table>';
  if (!empty($m['bouton'])) $c .= $btn($m['bouton']);
  if (!empty($m['lien2'])) $c .= '<p style="margin:12px 0 0;' . $font . ';font-size:14px"><a href="' . $h($m['lien2'][1]) . '" style="color:' . $acc . ';font-weight:600;text-decoration:none">' . $h($m['lien2'][0]) . ' &rsaquo;</a></p>';
  if (!empty($m['infos'])) {
    $rows = '';
    foreach (array_values($m['infos']) as $k => $x) {
      $bt = $k ? 'border-top:1px solid ' . $line . ';' : '';
      $rows .= '<tr><td width="42%" style="padding:10px 14px;' . $font . ';font-size:13px;color:' . $mut . ';' . $bt . '">' . $h($x[0]) . '</td>'
        . '<td style="padding:10px 14px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:14px;color:' . $navy . ';font-weight:700;' . $bt . '">' . $h($x[1]) . '</td></tr>';
    }
    $c .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 4px;border:1px solid ' . $line . ';border-radius:12px;border-collapse:separate">' . $rows . '</table>';
  }
  if (!empty($m['note'])) $c .= '<p style="margin:18px 0 0;' . $font . ';font-size:13px;line-height:1.5;color:' . $mut . '">' . nl2br($h($m['note'])) . '</p>';
  $pre = $h($m['pre'] ?? ($m['paras'][0] ?? ''));
  $bandeau = !empty($m['atelier']) ? 'Espace atelier' : 'Service Client';
  /* En-tête : image jointe à l'e-mail (cid) si le fichier est sur le serveur, sinon image du site */
  $f_entete = !empty($m['atelier']) ? 'mail-entete-atelier.png' : 'mail-entete.png';
  $chemin_entete = dirname(__DIR__) . '/assets/' . $f_entete;
  $images = is_file($chemin_entete) ? ['entete@gouyerian-tech.fr' => [$chemin_entete, 'image/png']] : [];
  $src_entete = $images ? 'cid:entete@gouyerian-tech.fr' : "$site/assets/$f_entete?v=2";
  $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . $h($sujet) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#eef2f8;-webkit-text-size-adjust:100%">'
    . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $pre . '</div>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef2f8"><tr><td align="center" style="padding:28px 12px">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px">'
    /* en-tête */
    . '<tr><td bgcolor="#0b1426" style="background:' . $navy . ';background-image:linear-gradient(135deg,#0b1426 0%,#13254a 60%,#1d3f7a 100%);border-radius:18px 18px 0 0;padding:22px 26px">'
    /* Logo et nom en image : le mode sombre de Gmail sur téléphone assombrissait le texte blanc de l'en-tête */
    . '<a href="' . $h($site) . '" style="text-decoration:none"><img src="' . $h($src_entete) . '" width="320" height="48" alt="GOUYERIAN TECH · ' . $bandeau . '" style="display:block;border:0;width:320px;max-width:100%;height:auto;' . $font . ';font-size:17px;font-weight:800;color:#ffffff"></a></td></tr>'
    . '<tr><td style="height:4px;background:#2563eb;background-image:linear-gradient(90deg,#2563eb,#06b6d4);font-size:0;line-height:0">&nbsp;</td></tr>'
    /* contenu */
    . '<tr><td style="background:#ffffff;padding:32px 28px 28px;border-left:1px solid ' . $line . ';border-right:1px solid ' . $line . '">' . $c . '</td></tr>'
    /* pied */
    . '<tr><td style="background:#f7f9fc;border:1px solid ' . $line . ';border-top:0;border-radius:0 0 18px 18px;padding:20px 28px;' . $font . ';font-size:12px;line-height:1.6;color:' . $mut . '">'
    . '<b style="color:' . $navy . '">Service Client GOUYERIAN TECH</b><br>1 route de Segonzac, 16130 Saint-Fort-sur-le-Né<br>'
    . '<a href="tel:+33605794894" style="color:' . $acc . ';text-decoration:none;font-weight:600">06 05 79 48 94</a> · <a href="mailto:contact@gouyerian-tech.fr" style="color:' . $acc . ';text-decoration:none">contact@gouyerian-tech.fr</a> · <a href="' . $h($site) . '" style="color:' . $acc . ';text-decoration:none">gouyerian-tech.fr</a>'
    . '<div style="margin-top:12px;padding-top:12px;border-top:1px solid ' . $line . '">E-mail automatique : merci de ne pas y répondre.' . (!empty($m['stop']) ? ' <a href="' . $h($m['stop']) . '" style="color:' . $mut . '">Ne plus recevoir les e-mails de suivi</a>.' : '') . '</div>'
    . '</td></tr></table></td></tr></table></body></html>';
  return envoyer_mail($to, $sujet, $texte, $html, $images);
}

/* Étapes affichées dans les e-mails (la pièce seulement si elle a servi) */
function etapes_mail(array $r, string $etape): array {
  $p = ['recu', 'diagnostic', 'devis', 'reparation', 'prete', 'rendu'];
  $s = db()->prepare("SELECT COUNT(*) FROM gt_historique WHERE reparation_id = ? AND etape = 'piece'");
  $s->execute([$r['id']]);
  if ($etape === 'piece' || (int)$s->fetchColumn()) array_splice($p, 3, 0, ['piece']);
  $court = ['recu' => 'Reçu', 'diagnostic' => 'Diagnostic', 'devis' => 'Devis', 'piece' => 'Pièce', 'reparation' => 'Réparation', 'prete' => 'Prêt', 'rendu' => 'Rendu'];
  return [array_map(fn($e) => $court[$e], $p), (int)array_search($etape, $p, true)];
}

function infos_suivi(array $r): array {
  return [['Numéro de suivi', $r['numero']], ['Code', $r['code']], ['Appareil', $r['appareil']]];
}

function lien_stop(array $r): string {
  return rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/') . '/api/suivi.php?stop=' . $r['stop_token'];
}

/* Ancien pied texte, gardé pour compatibilité */
function pied_mail(array $r): string {
  return "\n\nSuivre votre réparation : " . lien_suivi($r) . "\nNuméro de suivi : {$r['numero']} · code : {$r['code']}\n";
}

function mail_etape(array $r, string $etape, string $note, bool $force = false): bool {
  if ((!(int)$r['avis_email'] && !$force) || $r['email'] === '') return false;
  [$lib, $phrase] = ETAPES[$etape];
  $m = ['pre' => "$lib · $phrase", 'etiquette' => "Réparation {$r['numero']}", 'titre' => $lib,
    'paras' => ['Bonjour ' . prenom($r['nom']) . ',', "Votre {$r['appareil']} : " . mb_strtolower(mb_substr($phrase, 0, 1)) . mb_substr($phrase, 1)],
    'citation' => $note !== '' ? $note : null, 'infos' => infos_suivi($r), 'stop' => (int)$r['avis_email'] ? lien_stop($r) : null];
  if ($etape !== 'annule') [$m['etapes'], $m['etape_i']] = etapes_mail($r, $etape);
  if ($note !== '') $m['paras'][] = 'Message de l’atelier :';
  if ($etape === 'devis') { $m['bouton'] = ['Voir et accepter le devis', lien_suivi($r)]; $m['note'] = 'La réparation commence dès votre accord. Une question ? Écrivez-nous depuis la page de suivi ou appelez le 06 05 79 48 94.'; }
  elseif ($etape === 'prete') { $m['bouton'] = ['Voir ma réparation', lien_suivi($r)]; $m['note'] = 'Appelez le 06 05 79 48 94 pour convenir de l’heure de passage ou d’une livraison.'; }
  elseif ($etape === 'rendu') { $m['bouton'] = ['Laisser un avis Google', 'https://search.google.com/local/writereview?placeid=ChIJY1Q5ZwBhAEgRGG3fYcH4qpk']; $m['lien2'] = ['Voir ma garantie dans mon espace', rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/') . '/mon-espace/']; }
  else $m['bouton'] = ['Suivre ma réparation', lien_suivi($r)];
  return mail_pro($r['email'], "Votre réparation {$r['numero']} : $lib", $m);
}

function mail_bienvenue(array $r): bool {
  if (!(int)$r['avis_email'] || $r['email'] === '') return false;
  [$et, $ei] = etapes_mail($r, 'recu');
  return mail_pro($r['email'], "Suivi de votre réparation {$r['numero']}", [
    'pre' => "Votre {$r['appareil']} est bien enregistré à l’atelier.", 'etiquette' => "Réparation {$r['numero']}", 'titre' => 'Votre appareil est entre de bonnes mains',
    'paras' => ['Bonjour ' . prenom($r['nom']) . ',', "Votre {$r['appareil']} est enregistré à l’atelier Gouyerian Tech. Vous recevrez un e-mail à chaque étape, et vous pouvez suivre l’avancée ou écrire à l’atelier à tout moment."],
    'etapes' => $et, 'etape_i' => $ei, 'bouton' => ['Suivre ma réparation', lien_suivi($r)],
    'lien2' => ['Créer mon espace client (sans mot de passe)', rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/') . '/mon-espace/'],
    'infos' => infos_suivi($r), 'note' => 'Gardez cet e-mail : le numéro et le code permettent de suivre la réparation depuis n’importe quel appareil.', 'stop' => lien_stop($r)]);
}

/* RGPD : efface les réparations terminées depuis plus de CONSERVATION_MOIS mois. */
function nettoyer(): void {
  $pdo = db();
  $lim = date('Y-m-d H:i:s', strtotime('-' . CONSERVATION_MOIS . ' months'));
  $ids = $pdo->prepare('SELECT id FROM gt_reparations WHERE clos_le IS NOT NULL AND clos_le < ?');
  $ids->execute([$lim]);
  foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) supprimer_reparation((int)$id);
}

/* Conversations : effacées avec leurs pièces jointes 3 mois après la fin
   (réparation rendue ou annulée, demande traitée, ou 3 mois sans message pour la conversation générale) */
const CONSERVATION_MESSAGES_MOIS = 3;
function effacer_messages(string $where, array $p): int {
  $pdo = db();
  $s = $pdo->prepare("SELECT id, pj FROM gt_messages WHERE $where");
  $s->execute($p);
  $n = 0;
  foreach ($s->fetchAll() as $m) {
    foreach (json_decode((string)$m['pj'], true) ?: [] as $f) { $f = prive_dir() . '/pj/' . basename($f['fichier'] ?? ''); if (is_file($f)) @unlink($f); }
    $pdo->prepare('DELETE FROM gt_messages WHERE id = ?')->execute([$m['id']]);
    $n++;
  }
  return $n;
}
function nettoyer_messages(): void {
  $pdo = db();
  $lim = date('Y-m-d H:i:s', strtotime('-' . CONSERVATION_MESSAGES_MOIS . ' months'));
  $r = $pdo->prepare("SELECT id FROM gt_reparations WHERE etape IN ('rendu','annule') AND COALESCE(clos_le, maj_le) < ?");
  $r->execute([$lim]);
  foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $id) effacer_messages('reparation_id = ?', [(int)$id]);
  $d = $pdo->prepare("SELECT id FROM gt_demandes WHERE statut IN ('traitee','archivee') AND maj_le < ?");
  $d->execute([$lim]);
  foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $id) effacer_messages('reparation_id = 0 AND demande_id = ?', [(int)$id]);
  $c = $pdo->prepare("SELECT client_id FROM gt_messages WHERE reparation_id = 0 AND demande_id = 0 AND client_id > 0 GROUP BY client_id HAVING MAX(le) < ?");
  $c->execute([$lim]);
  foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $id) effacer_messages('reparation_id = 0 AND demande_id = 0 AND client_id = ?', [(int)$id]);
}
/* Entretien automatique, une fois par jour, au premier passage sur le site */
function entretien(): void {
  try {
    if (meta('entretien', '') === date('Y-m-d')) return;
    meta_set('entretien', date('Y-m-d'));
    nettoyer(); nettoyer_demandes(); nettoyer_messages();
  } catch (Throwable $e) { /* jamais bloquant */ }
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

/* E-mail au client pour un message hors réparation (demande ou conversation générale de son espace) */
function mail_message_espace(string $email, string $nom, string $texte, int $nb_pj, string $contexte): bool {
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
  $site = rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/');
  $extrait = mb_substr($texte, 0, 600) . (mb_strlen($texte) > 600 ? '…' : '');
  return mail_pro($email, 'Nouveau message de l’atelier Gouyerian Tech', [
    'pre' => $extrait !== '' ? $extrait : 'L’atelier vous a envoyé un fichier.', 'etiquette' => $contexte, 'titre' => 'Vous avez un nouveau message',
    'paras' => [($nom !== '' ? 'Bonjour ' . prenom($nom) : 'Bonjour') . ',', 'L’atelier vous a écrit :'],
    'citation' => $extrait !== '' ? $extrait : null,
    'note' => ($nb_pj ? ($nb_pj > 1 ? "$nb_pj fichiers sont joints" : 'Un fichier est joint') . ' au message. ' : '') . 'Pour lire et répondre, connectez-vous à votre espace client (un code par e-mail, sans mot de passe) : les réponses à cet e-mail ne sont pas lues.',
    'bouton' => ['Lire et répondre dans mon espace', "$site/mon-espace/#messages"]]);
}

/* E-mail au client quand l'atelier lui écrit (dès qu'une adresse e-mail est connue) */
function mail_message(array $r, string $texte, int $nb_pj = 0): bool {
  if ($r['email'] === '') return false;
  $extrait = mb_substr($texte, 0, 600) . (mb_strlen($texte) > 600 ? '…' : '');
  return mail_pro($r['email'], "Nouveau message de l’atelier · réparation {$r['numero']}", [
    'pre' => $extrait !== '' ? $extrait : 'L’atelier vous a envoyé un fichier.', 'etiquette' => "Réparation {$r['numero']} · {$r['appareil']}", 'titre' => 'Vous avez un nouveau message',
    'paras' => ['Bonjour ' . prenom($r['nom']) . ',', 'L’atelier vous a écrit au sujet de votre ' . $r['appareil'] . ' :'],
    'citation' => $extrait !== '' ? $extrait : null,
    'note' => ($nb_pj ? ($nb_pj > 1 ? "$nb_pj fichiers sont joints" : 'Un fichier est joint') . ' au message, à ouvrir sur votre page de suivi. ' : '') . 'Pour répondre, utilisez la messagerie de votre suivi : les réponses à cet e-mail ne sont pas lues.',
    'bouton' => ['Lire et répondre', lien_suivi($r)],
    'lien2' => ['Ouvrir mon espace client', rtrim(cfg()['site'] ?? 'https://gouyerian-tech.fr', '/') . '/mon-espace/'],
    'infos' => infos_suivi($r)]);
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
