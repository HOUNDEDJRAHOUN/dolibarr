<?php

// Log de débogage
error_log("=== Début hook_normalization.php ===");
error_log("Action: " . (isset($action) ? $action : 'non définie'));
error_log("Facture existe: " . (isset($object) ? 'oui' : 'non'));

// Si on est en mode création/édition, on ne fait rien
if (isset($action) && ($action == 'create' || $action == 'edit')) {
    error_log("Mode création/édition détecté - sortie");
    return;
}

// Vérification que nous avons bien une facture
if (isset($object) && is_object($object) && method_exists($object, 'getLibStatut')) {
    error_log("Facture valide détectée");
    error_log("Statut facture: " . $object->statut);
    error_log("Code MECEF DGI: " . (isset($object->array_options['options_code_mecef_dgi']) ? $object->array_options['options_code_mecef_dgi'] : 'non défini'));
    
    if ($object->thirdparty) {
        error_log("Pays client: " . $object->thirdparty->country_code);
    } else {
        error_log("Pas de tiers associé à la facture");
    }

    // Afficher le message d'erreur si le token est expiré
    if (isset($_GET['error']) && $_GET['error'] == 'token_expired') {
        echo '<div class="error">Le jeton de sécurité a expiré. Veuillez ré-essayer la normalisation.</div>';
    }

    // Vérifier si la facture est validée et pas encore normalisée
    if ($object->statut == Facture::STATUS_VALIDATED && empty($object->array_options['options_code_mecef_dgi'])) {
        error_log("Facture validée et non normalisée");
        // Vérifier si le pays du client est Bénin
        if ($object->thirdparty && $object->thirdparty->country_code == 'BJ') {
            error_log("Client du Bénin détecté - affichage du bouton");
            // Générer le token et l'ID chiffré
            $data = 'FACTURE@TDSSTORE@DGI';
            $method = 'AES-128-CTR';
            $encryption_key = 'tds@store!22';
            $options = 0;
            $encryption_iv = "TDSSTORE@DGI2022";
            
            $token = openssl_encrypt($data, $method, $encryption_key, $options, $encryption_iv);
            $id_enc = openssl_encrypt('STORE '.$object->id, $method, $encryption_key, $options, $encryption_iv);
            $callback = DOL_URL_ROOT.'/compta/facture/card.php?id='.$object->id;
            $token_encoded = urlencode($token);
            $id_encoded = urlencode($id_enc);
            $callback_encoded = urlencode($callback);
            $url = DOL_URL_ROOT.'/compta/facture/normaliser/normalize_dgi.php?token='.$token_encoded.'&i='.$id_encoded.'&callback='.$callback_encoded;
            
            echo "<div class='tabsAction' style='margin:20px 0;'>";
            echo "<a href='".$url."' class='butAction'><i class='fa fa-qrcode'></i> Normaliser via DGI</a>";
            echo "</div>";
        } else {
            error_log("Client n'est pas du Bénin");
        }
    } else if (!empty($object->array_options['options_code_mecef_dgi'])) {
        // Afficher les informations de normalisation
        echo "<div class='tabsAction' style='margin:20px 0;'>";
        echo "<span class='butActionRefused classfortooltip' title='Facture déjà normalisée'>Déjà normalisée DGI</span>";
        
        // Récupérer les données de normalisation
        $normalized_data = [];
        if (!empty($object->array_options['options_normalized_data'])) {
            $normalized_data = json_decode($object->array_options['options_normalized_data'], true);
        }
        
        echo "<div class='info' style='margin:10px 0;'>";
        echo "<table class='nobordernopadding' width='100%'>";
        echo "<tr>";
        echo "<td width='150'><strong>Code MECeF/DGI :</strong></td>";
        echo "<td>" . $object->array_options['options_code_mecef_dgi'] . "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td><strong>NIM :</strong></td>";
        echo "<td>" . $object->array_options['options_nim'] . "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td><strong>Date/Heure :</strong></td>";
        echo "<td>" . $object->array_options['options_normalize_date'] . "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td><strong>Compteurs :</strong></td>";
        echo "<td>" . $object->array_options['options_counters'] . "</td>";
        echo "</tr>";
        echo "</table>";
        
        // Afficher le QR code si disponible
        if (!empty($object->array_options['options_qr_code'])) {
            echo "<div style='margin-top:10px;'>";
            echo "<img src='https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($object->array_options['options_qr_code']) . "' alt='QR Code e-MECeF' />";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
    } else {
        error_log("Facture non validée ou déjà normalisée");
    }
} else {
    error_log("Facture invalide ou non définie");
}

error_log("=== Fin hook_normalization.php ===");