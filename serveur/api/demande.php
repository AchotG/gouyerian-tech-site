<?php
/* Gouyerian Tech : réception des demandes du site (rendez-vous, devis, commandes, pros, espace client).
   Elles arrivent dans la page de gestion de l'atelier ; un e-mail court prévient l'atelier (réglable).
   Les pièces jointes (photos, vidéos, PDF) sont rangées hors du site, dans /prive/pj. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
if (($_SERVER['HTTP_X_GT'] ?? '') !== '1') fail('Demande refusée.', 403);
if (!https_ok()) fail('Ouvrez la page en https://', 403);

const PJ_MAX = 6;
const PJ_TAILLE = 30 * 1024 * 1024;   // par fichier
const PJ_TOTAL = 80 * 1024 * 1024;
const PJ_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic' => 'heic', 'image/heif' => 'heif',
  'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/3gpp' => '3gp', 'application/pdf' => 'pdf'];

$raw = $_POST['data'] ?? file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) fail('Demande illisible.');
if (!empty($data['_honey']) || !empty($data['_gotcha'])) out(['ok' => true]);  // robot : on fait comme si

limite('demande:' . ip(), 15, 60);

/* Champs : on garde au plus 40 champs texte, sans les réglages internes de FormSubmit */
$sujet = txt($data['_subject'] ?? 'Demande', 250);
$details = [];
foreach ($data as $k => $v) {
  if (!is_string($k) || $k === '' || $k[0] === '_') continue;
  if (count($details) >= 40) break;
  $details[txt($k, 60)] = txt(is_scalar($v) ? (string)$v : '', 3000);
}
$nom = txt($details['Nom'] ?? '', 120);
$tel = txt($details['Téléphone'] ?? '', 40);
$mail = strtolower(txt($details['E-mail'] ?? '', 190));
if ($mail === '-' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';
if ($nom === '' && $tel === '' && $mail === '') fail('Indiquez au moins votre nom et votre téléphone.');

$type = match (true) {
  str_starts_with($sujet, 'Commande') => 'commande',
  str_starts_with($sujet, 'Devis pro') => 'pro',
  str_contains($sujet, 'espace client') => 'devis',
  str_starts_with($sujet, 'Demande') => 'rdv',
  default => 'autre',
};

/* Client connecté à son espace : la demande lui est rattachée */
$client = null;
$j = $_COOKIE['gtcli'] ?? '';
if (preg_match('/^[a-f0-9]{64}$/', $j)) {
  $s = db()->prepare('SELECT c.id, c.email FROM gt_csessions s JOIN gt_clients c ON c.id = s.client_id WHERE s.jeton = ? AND s.expire > ?');
  $s->execute([hash('sha256', $j), now()]);
  $client = $s->fetch() ?: null;
  if ($client && $mail === '') $mail = $client['email'];
}

/* Pièces jointes */
$pj = [];
if (!empty($_FILES['f']) && is_array($_FILES['f']['name'])) {
  $dir = prive_dir() . '/pj';
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail('Pièces jointes impossibles à enregistrer.', 500);
  $total = 0;
  $fi = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
  foreach ($_FILES['f']['name'] as $i => $name) {
    if (count($pj) >= PJ_MAX) break;
    if (($_FILES['f']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) fail('Un fichier n’a pas pu être envoyé (trop lourd ?).', 413);
    $tmp = $_FILES['f']['tmp_name'][$i];
    $size = (int)$_FILES['f']['size'][$i];
    $total += $size;
    if ($size > PJ_TAILLE || $total > PJ_TOTAL) fail('Fichiers trop lourds.', 413);
    $mime = $fi ? (string)$fi->file($tmp) : (string)($_FILES['f']['type'][$i] ?? '');
    if (!isset(PJ_TYPES[$mime])) fail('Type de fichier refusé : photos, vidéos ou PDF seulement.', 415);
    $fichier = date('Ym') . '-' . bin2hex(random_bytes(12)) . '.' . PJ_TYPES[$mime];
    if (!move_uploaded_file($tmp, "$dir/$fichier") && !rename($tmp, "$dir/$fichier")) fail('Pièce jointe impossible à enregistrer.', 500);
    @chmod("$dir/$fichier", 0600);
    $pj[] = ['nom' => txt(basename((string)$name), 120), 'type' => $mime, 'taille' => $size, 'fichier' => $fichier];
  }
}

/* Référence lisible : D-AAMM-NNN */
$pdo = db();
$mois = date('ym');
$s = $pdo->prepare('SELECT COUNT(*) FROM gt_demandes WHERE ref LIKE ?');
$s->execute(["D-$mois-%"]);
$n = (int)$s->fetchColumn() + 1;
$ref = '';
for ($k = 0; $k < 20 && $ref === ''; $k++, $n++) {
  $try = sprintf('D-%s-%03d', $mois, $n);
  try {
    $pdo->prepare('INSERT INTO gt_demandes (ref, type, statut, client_id, nom, telephone, email, sujet, details, pj, note_interne, ip, cree_le, maj_le)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$try, $type, 'nouvelle', $client['id'] ?? null, $nom, $tel, $mail, $sujet,
      json_encode($details, JSON_UNESCAPED_UNICODE), json_encode($pj, JSON_UNESCAPED_UNICODE), '', ip(), now(), now()]);
    $ref = $try;
  } catch (PDOException $e) {
    if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'Duplicate')) throw $e;
  }
}
if ($ref === '') fail('Demande non enregistrée, réessayez.', 500);

$libs = ['rdv' => 'demande de rendez-vous', 'devis' => 'demande de devis', 'commande' => 'commande', 'pro' => 'demande de devis pro', 'autre' => 'demande'];
notifier_atelier("nouvelle {$libs[$type]} ($ref)",
  'Nouvelle ' . $libs[$type] . " $ref : " . ($nom ?: 'sans nom') . ($tel ? " · $tel" : '') . "\n$sujet" . ($pj ? "\n" . count($pj) . ' pièce(s) jointe(s)' : ''));

out(['ok' => true, 'ref' => $ref]);
