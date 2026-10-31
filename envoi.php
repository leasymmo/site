<?php
// Envoi des formulaires du site Carré d'Hôtes — hébergé chez OVH, aucun service externe.
//
// Reçoit les envois du formulaire de contact et du formulaire « Recevoir mon
// estimation » (simulateur), puis expédie un courriel :
//   - estimation -> contact@carredhotes.fr
//   - contact    -> anthony@carredhotes.fr si le sujet commence par
//                   « Je suis voyageur », sinon contact@carredhotes.fr
// Le visiteur répondra au courriel : l'adresse Reply-To est la sienne.

header('Content-Type: application/json; charset=utf-8');

function repondre($ok, $code, $message) {
    http_response_code($code);
    echo json_encode(array('ok' => $ok, 'message' => $message));
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    repondre(false, 405, 'Méthode non autorisée.');
}

// Piège anti-robots : champ invisible du formulaire, il doit rester vide.
// On répond « ok » sans rien envoyer pour ne pas renseigner les robots.
if (!empty($_POST['site_web'])) {
    repondre(true, 200, 'Envoyé.');
}

function champ($nom, $max) {
    $v = isset($_POST[$nom]) ? trim((string) $_POST[$nom]) : '';
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, $max, 'UTF-8');
    } else {
        $v = substr($v, 0, $max);
    }
    return $v;
}

function sans_retour($v) {
    return str_replace(array("\r", "\n"), ' ', $v);
}

// Anti-abus simple : un envoi toutes les 20 secondes par adresse IP.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$fichierCadence = sys_get_temp_dir() . '/carredhotes_envoi_' . md5($ip) . '.txt';
$maintenant = time();
if (is_file($fichierCadence)) {
    $dernier = (int) file_get_contents($fichierCadence);
    if ($maintenant - $dernier < 20) {
        repondre(false, 429, 'Envoi trop rapproché : patientez quelques secondes puis réessayez.');
    }
}

$type = champ('type', 20);
$emailVisiteur = sans_retour(champ('email', 150));
if (!filter_var($emailVisiteur, FILTER_VALIDATE_EMAIL)) {
    repondre(false, 422, 'Adresse électronique invalide.');
}

$nomVisiteur = '';
if ($type === 'estimation') {
    $destinataire = 'contact@carredhotes.fr';
    $sujet = '[Site] Demande de chiffrage détaillé';
    $corps = "Bonjour Carré d'Hôtes,\n\n"
        . "Je souhaite recevoir un chiffrage détaillé pour mon logement.\n\n"
        . 'Adresse électronique : ' . $emailVisiteur . "\n"
        . 'Adresse du logement : ' . champ('adresse_logement', 300) . "\n"
        . 'Zone : ' . champ('zone', 80) . "\n"
        . 'Couchages : ' . champ('couchages', 20) . "\n";
    $surface = champ('surface', 30);
    if ($surface !== '') {
        $corps .= 'Surface du logement : ' . $surface . "\n";
    }
    $corps .= 'Prix moyen par nuit simulé : ' . champ('prix_nuit', 40) . "\n"
        . 'Taux d\'occupation simulé : ' . champ('occupation', 40) . "\n"
        . 'Formule : ' . champ('formule', 60) . "\n"
        . 'Revenu brut annuel estimé : ' . champ('revenu_brut', 40) . "\n"
        . 'Frais de plateforme : ' . champ('frais_plateforme', 60) . "\n"
        . 'Taxe de séjour : ' . champ('taxe_sejour', 40) . "\n"
        . 'Montant net avant commission : ' . champ('montant_net', 40) . "\n"
        . 'Commission Carré d\'Hôtes calculée sur le montant net : ' . champ('commission', 40) . "\n"
        . 'Revenu net annuel estimé : ' . champ('revenu_net', 40) . "\n"
        . 'Valeur des services inclus : ' . champ('valeur_services', 40) . "\n"
        . 'Revenu net de chez net annuel : ' . champ('revenu_net_net', 40) . "\n\n"
        . 'Merci de me recontacter avec une estimation personnalisée.';
} elseif ($type === 'contact') {
    $nomVisiteur = sans_retour(champ('nom', 120));
    $sujetFormulaire = sans_retour(champ('sujet', 160));
    $message = champ('message', 5000);
    if ($nomVisiteur === '' || $sujetFormulaire === '' || $message === '') {
        repondre(false, 422, 'Tous les champs du formulaire sont requis.');
    }
    $destinataire = (strpos($sujetFormulaire, 'Je suis voyageur') === 0)
        ? 'anthony@carredhotes.fr'
        : 'contact@carredhotes.fr';
    $sujet = '[Site] ' . $sujetFormulaire;
    $corps = $message . "\n\n— " . $nomVisiteur . ' (' . $emailVisiteur . ')';
} else {
    repondre(false, 422, 'Type de demande inconnu.');
}

$expediteurNom = '=?UTF-8?B?' . base64_encode('Site Carré d\'Hôtes') . '?=';
$entetes = array(
    'From: ' . $expediteurNom . ' <contact@carredhotes.fr>',
    'Reply-To: ' . sans_retour($emailVisiteur),
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=utf-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: PHP/' . phpversion()
);
$sujetEncode = '=?UTF-8?B?' . base64_encode($sujet) . '?=';

$envoye = mail($destinataire, $sujetEncode, $corps, implode("\r\n", $entetes));
if ($envoye) {
    file_put_contents($fichierCadence, (string) $maintenant);
    repondre(true, 200, 'Envoyé.');
}
repondre(false, 500, 'L\'envoi a échoué côté serveur.');
