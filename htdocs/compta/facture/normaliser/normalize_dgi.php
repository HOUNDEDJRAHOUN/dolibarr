<?php
/**
 * Process script for e-MECeF invoice normalization in Dolibarr
 *
 * Ce script gère la communication avec l'API e-MECeF pour normaliser une facture Dolibarr.
 * Il reçoit les paramètres chiffrés depuis l'interface principale.
 */

require_once '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once(__DIR__ . '/vendor/autoload.php');

// Récupération directe des paramètres GET
$token = isset($_GET['token']) ? $_GET['token'] : '';
$i = isset($_GET['i']) ? $_GET['i'] : '';
$callback = isset($_GET['callback']) ? $_GET['callback'] : '';

// Tentative de récupération du token depuis QUERY_STRING
parse_str($_SERVER['QUERY_STRING'], $query_params);

// Utiliser le token depuis query_params si disponible
$token_to_decrypt = isset($query_params['token']) ? $query_params['token'] : $token;

$method = 'AES-128-CTR';
$decryption_key = 'tds@store!22';
$options = 0;
$decryption_iv = "TDSSTORE@DGI2022";

// Décryptage avec le token urldecodé
$decryption = openssl_decrypt(urldecode($token_to_decrypt), $method, $decryption_key, $options, $decryption_iv);

if ($decryption == "FACTURE@TDSSTORE@DGI") {
    $jwt_token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1bmlxdWVfbmFtZSI6IjMyMDE3MTAxNDM3MTh8VFMwMTAwMDMzMyIsInJvbGUiOiJUYXhwYXllciIsIm5iZiI6MTc0NzczNzk2NywiZXhwIjoyNTgxMzY5MjAwLCJpYXQiOjE3NDc3Mzc5NjcsImlzcyI6ImltcG90cy5iaiIsImF1ZCI6ImltcG90cy5iaiJ9.JGIbRP9W_eaZj0_l0FGjox0MMTMZ5MJsQrip5BZd1N8';
    $jwt_parts = explode('.', $jwt_token);
    $payload = json_decode(base64_decode($jwt_parts[1]), true);
    $config = Swagger\Client\Configuration::getDefaultConfiguration()->setApiKey('Authorization', $jwt_token);
    try {
        $apiInvoiceInstance = new Swagger\Client\Api\SfeInvoiceApi(new GuzzleHttp\Client(['verify' => false]), $config);
        $apiInfoInstance = new Swagger\Client\Api\SfeInfoApi(new GuzzleHttp\Client(['verify' => false]), $config);
        try {
            $apiInfoInstance->apiInfoStatusGet();
        } catch (Exception $e) {
            die('Erreur connexion API e-MECeF : ' . $e->getMessage());
        }
        $apiInfoInstance->apiInfoInvoiceTypesGet();
        $apiInfoInstance->apiInfoTaxGroupsGet();
        $apiInfoInstance->apiInfoPaymentTypesGet();
        $apiInvoiceInstance->apiInvoiceGet();
    } catch (Exception $e) {
        die('Erreur connexion API e-MECeF : ' . $e->getMessage());
    }
    $t_invoice = openssl_decrypt($i, $method, $decryption_key, $options, $decryption_iv);
    $parts = explode(" ", $t_invoice);
    if (count($parts) < 2) {
        die('Erreur de normalisation : ID de facture invalide.');
    }
    $invoice_id = $parts[1];
    $invoice = new Facture($db);
    $result = $invoice->fetch($invoice_id);
    if ($result <= 0) {
        die('Erreur de normalisation : facture introuvable.');
    }
    $invoice->fetch_thirdparty();
    $body = new \Swagger\Client\Model\InvoiceRequestDataDto();
    $ifu_client = '';
    if (!empty($invoice->thirdparty->array_options['options_ifu'])) {
        $ifu_client = $invoice->thirdparty->array_options['options_ifu'];
    } elseif (!empty($invoice->thirdparty->idprof1)) {
        $ifu_client = $invoice->thirdparty->idprof1;
    }
    if ($ifu_client) {
        $clientDto = new \Swagger\Client\Model\ClientDto();
        $clientDto->setIfu($ifu_client);
        $clientDto->setName($invoice->thirdparty->name);
        $clientDto->setContact($invoice->thirdparty->phone . ',' . $invoice->thirdparty->email);
        $clientDto->setAddress($invoice->thirdparty->address . ', ' . $invoice->thirdparty->zip . ' ' . $invoice->thirdparty->town);
        $body->setClient($clientDto);
    } else {
        die('Erreur : Le client n\'a pas de numéro IFU configuré (ni dans l\'extrafield IFU, ni dans le champ idprof1).');
    }
    $operatorDto = new \Swagger\Client\Model\OperatorDto();
    $operatorDto->setName($user->firstname . ' ' . $user->lastname);
    $body->setOperator($operatorDto);
    $body->setType(\Swagger\Client\Model\InvoiceTypeEnum::FV);
    $items = array();
    foreach ($invoice->lines as $line) {
        $item = new \Swagger\Client\Model\ItemDto();
        $item->setName($line->desc ?: $line->product_label);
        $priceWithTax = round($line->subprice * (1 + ($line->tva_tx/100)), 2) * 100;
        $item->setPrice((int)$priceWithTax);
        $item->setQuantity($line->qty);
        $taxGroup = 'B';
        if ($line->fk_product > 0) {
            $product = new Product($db);
            $product->fetch($line->fk_product);
            $product->fetch_optionals();
            if (!empty($product->array_options['options_tax_group'])) {
                $taxGroup = $product->array_options['options_tax_group'];
            } elseif ($line->tva_tx == 0) {
                $taxGroup = 'A';
            }
        }
        $item->setTaxGroup($taxGroup);
        $items[] = $item;
    }
    $body->setItems($items);
    $payments = array();
    $paymentMethod = \Swagger\Client\Model\PaymentTypeEnum::ESPECES;
    $sql = "SELECT p.fk_paiement, c.code FROM ".MAIN_DB_PREFIX."paiement_facture pf";
    $sql.= " JOIN ".MAIN_DB_PREFIX."paiement p ON p.rowid = pf.fk_paiement";
    $sql.= " JOIN ".MAIN_DB_PREFIX."c_paiement c ON c.id = p.fk_paiement";
    $sql.= " WHERE pf.fk_facture = " . $invoice->id;
    $sql.= " ORDER BY p.datep DESC LIMIT 1";
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $obj = $db->fetch_object($resql);
        switch ($obj->code) {
            case 'CHQ': $paymentMethod = \Swagger\Client\Model\PaymentTypeEnum::CHEQUES; break;
            case 'VIR': $paymentMethod = \Swagger\Client\Model\PaymentTypeEnum::VIREMENT; break;
            case 'CB': $paymentMethod = \Swagger\Client\Model\PaymentTypeEnum::CARTEBANCAIRE; break;
            case 'LIQ': $paymentMethod = \Swagger\Client\Model\PaymentTypeEnum::ESPECES; break;
            default: $paymentMethod = \Swagger\Client\Model\PaymentTypeEnum::AUTRE; break;
        }
    }
    $paymentInfo = new \Swagger\Client\Model\PaymentDto();
    $paymentInfo->setName($paymentMethod);
    $paymentInfo->setAmount((int)($invoice->total_ttc * 100));
    $payments[] = $paymentInfo;
    $body->setPayment($payments);
    $body->setIfu($conf->global->MAIN_INFO_TVAINTRA);
    try {
        $invoiceResponseDto = $apiInvoiceInstance->apiInvoicePost($body);
        file_put_contents(__DIR__.'/debug_mecef_data.txt', "Réponse API Invoice Post :\n".print_r($invoiceResponseDto, true)."\n", FILE_APPEND);
        
        if (!empty($invoiceResponseDto['uid'])) {
            $uid = $invoiceResponseDto['uid'];
            try {
                $invoiceDetailsDto = $apiInvoiceInstance->apiInvoiceUidGet($uid);
                file_put_contents(__DIR__.'/debug_mecef_data.txt', "Réponse API Invoice Get :\n".print_r($invoiceDetailsDto, true)."\n", FILE_APPEND);
                
                $securityElementsDto = $apiInvoiceInstance->apiInvoiceUidConfirmPut($uid);
                file_put_contents(__DIR__.'/debug_mecef_data.txt', "Réponse API Invoice Confirm :\n".print_r($securityElementsDto, true)."\n", FILE_APPEND);
                
                if ($securityElementsDto) {
                    // Récupération directe des données de l'objet
                    $data = [
                        'date_time' => $securityElementsDto->getDateTime(),
                        'qr_code' => $securityElementsDto->getQrCode(),
                        'code_me_ce_fdgi' => $securityElementsDto->getCodeMeCeFDgi(),
                        'counters' => $securityElementsDto->getCounters(),
                        'nim' => $securityElementsDto->getNim()
                    ];
                    
                    $normalized_data = [
                        'dateTime'      => $data['date_time'],
                        'qrCode'        => $data['qr_code'],
                        'codeMECeFDGI'  => $data['code_me_ce_fdgi'],
                        'counters'      => $data['counters'],
                        'nim'           => $data['nim']
                    ];

                    // Log des données reçues
                    file_put_contents(__DIR__.'/debug_mecef_data.txt', "Données reçues :\n".print_r($data, true)."\n", FILE_APPEND);
                    file_put_contents(__DIR__.'/debug_mecef_data.txt', "Données normalisées :\n".print_r($normalized_data, true)."\n", FILE_APPEND);

                    if (!isset($invoice->array_options)) {
                        $invoice->fetch_optionals();
                    }

                    // Log des options actuelles
                    file_put_contents(__DIR__.'/debug_mecef_data.txt', "Options actuelles :\n".print_r($invoice->array_options, true)."\n", FILE_APPEND);

                    // Vérifier et créer les extrafields si nécessaire
                    $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."facture_extrafields LIKE 'options_normalized_data'";
                    $resql = $db->query($sql);
                    if ($db->num_rows($resql) == 0) {
                        // Créer les extrafields
                        $sql = "ALTER TABLE ".MAIN_DB_PREFIX."facture_extrafields";
                        $sql .= " ADD COLUMN options_normalized_data TEXT,";
                        $sql .= " ADD COLUMN options_is_normalized TINYINT(1) DEFAULT 0,";
                        $sql .= " ADD COLUMN options_normalize_date DATETIME,";
                        $sql .= " ADD COLUMN options_code_mecef_dgi VARCHAR(50),";
                        $sql .= " ADD COLUMN options_nim VARCHAR(50),";
                        $sql .= " ADD COLUMN options_qr_code TEXT,";
                        $sql .= " ADD COLUMN options_counters VARCHAR(50),";
                        $sql .= " ADD COLUMN options_date_time VARCHAR(50)";
                        
                        $resql = $db->query($sql);
                        if (!$resql) {
                            file_put_contents(__DIR__.'/debug_mecef_error.txt', "Erreur création extrafields : ".$db->lasterror()."\n", FILE_APPEND);
                            die('Erreur lors de la création des extrafields : ' . $db->lasterror());
                        }
                    }

                    $invoice->array_options['options_normalized_data'] = json_encode($normalized_data);
                    $invoice->array_options['options_is_normalized'] = 1;
                    $invoice->array_options['options_normalize_date'] = dol_now();
                    $invoice->array_options['options_code_mecef_dgi'] = $data['code_me_ce_fdgi'];
                    $invoice->array_options['options_nim'] = $data['nim'];
                    $invoice->array_options['options_qr_code'] = $data['qr_code'];
                    $invoice->array_options['options_counters'] = $data['counters'];
                    $invoice->array_options['options_date_time'] = $data['date_time'];

                    // Log des options après mise à jour
                    file_put_contents(__DIR__.'/debug_mecef_data.txt', "Options après mise à jour :\n".print_r($invoice->array_options, true)."\n", FILE_APPEND);

                    // Mise à jour des extrafields
                    $result = $invoice->insertExtraFields();
                    if ($result < 0) {
                        file_put_contents(__DIR__.'/debug_mecef_error.txt', "Erreur lors de la mise à jour des données :\n".$invoice->error."\n", FILE_APPEND);
                        file_put_contents(__DIR__.'/debug_mecef_error.txt', "SQL : ".$invoice->lastquery."\n", FILE_APPEND);
                        
                        // Tentative de mise à jour directe
                        $sql = "UPDATE ".MAIN_DB_PREFIX."facture_extrafields SET";
                        $sql .= " options_normalized_data = '".$db->escape(json_encode($normalized_data))."'";
                        $sql .= ", options_is_normalized = 1";
                        $sql .= ", options_normalize_date = '".$db->escape(dol_now())."'";
                        $sql .= ", options_code_mecef_dgi = '".$db->escape($data['code_me_ce_fdgi'])."'";
                        $sql .= ", options_nim = '".$db->escape($data['nim'])."'";
                        $sql .= ", options_qr_code = '".$db->escape($data['qr_code'])."'";
                        $sql .= ", options_counters = '".$db->escape($data['counters'])."'";
                        $sql .= ", options_date_time = '".$db->escape($data['date_time'])."'";
                        $sql .= " WHERE fk_object = ".$invoice->id;
                        
                        $resql = $db->query($sql);
                        if (!$resql) {
                            file_put_contents(__DIR__.'/debug_mecef_error.txt', "Erreur SQL directe : ".$db->lasterror()."\n", FILE_APPEND);
                            die('Erreur lors de la mise à jour des données de normalisation : ' . $db->lasterror());
                        }
                    }

                    $note = "Facture normalisée e-MECeF le " . dol_print_date(dol_now(), 'dayhourtext') . "\n";
                    $note .= "Code MECeF/DGI: " . ($data['code_me_ce_fdgi'] ?? '') . "\n";
                    $note .= "NIM: " . ($data['nim'] ?? '') . "\n";
                    $note .= "Compteurs: " . ($data['counters'] ?? '') . "\n";
                    $note .= "QR Code: " . ($data['qr_code'] ?? '');
                    $result = $invoice->update_note($note, '_public');
                    if ($result < 0) {
                        die('Erreur lors de la mise à jour de la note : ' . $invoice->error);
                    }
                    if (!headers_sent()) {
                        header('Location: ' . $callback);
                        exit;
                    } else {
                        echo '<script>window.location.href = '.json_encode($callback).';</script>';
                        exit;
                    }
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                die('Erreur lors de la normalisation : ' . htmlspecialchars($error_message));
            }
        } else {
            $errorCode = isset($invoiceResponseDto['errorCode']) ? $invoiceResponseDto['errorCode'] : 'Unknown';
            $errorDesc = isset($invoiceResponseDto['errorDesc']) ? $invoiceResponseDto['errorDesc'] : 'Unknown error';
            die('Erreur création facture : ' . $errorCode . ' - ' . $errorDesc);
        }
    } catch (Exception $e) {
        die('Erreur détaillée création facture : ' . $e->getMessage());
    }
} else {
    header("HTTP/1.0 401 Unauthorized");
    exit("Unauthorized access");
}