<?php
// FCM debug/diagnostic endpoint.
// Returns details about service account loading, key parsing, JWT signing, time drift, and optional test validate send.
// Auth required (Bearer token) to prevent public exposure of key internals.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../bootstrap.php';
require_method('GET');
api_require_auth();

global $conn, $authUser;

$saPath = __DIR__ . '/../../config/firebase_service_account.json';
$raw = null; $sa = null; $errors = [];
if (!is_file($saPath)) {
  $errors[] = 'service_account_file_missing';
} else {
  $raw = file_get_contents($saPath);
  $sa = json_decode($raw, true);
  if (!is_array($sa)) { $errors[] = 'service_account_json_parse_failed'; }
}

$now = time();
$serverTimeIso = gmdate('Y-m-d\TH:i:s\Z', $now);
// Simple time drift metric vs client provided ?client_time param (milliseconds) if passed.
$clientTimeMs = isset($_GET['client_time']) ? (int)$_GET['client_time'] : null; // epoch ms
$driftSeconds = null;
if ($clientTimeMs) { $driftSeconds = abs(($clientTimeMs/1000) - $now); }

$privateKey = isset($sa['private_key']) ? (string)$sa['private_key'] : '';
$privateKeyNorm = preg_replace("~\r\n?~", "\n", trim($privateKey));
$keyResource = $privateKeyNorm ? @openssl_pkey_get_private($privateKeyNorm) : null;
$keyParsedOk = (bool)$keyResource;
$keyDetails = $keyResource ? @openssl_pkey_get_details($keyResource) : null;
$keyType = $keyDetails['type'] ?? null; // Usually OPENSSL_KEYTYPE_RSA
$keyBits = $keyDetails['bits'] ?? null;

// Build JWT header + claim (without signature) for inspection
$header = ['alg' => 'RS256', 'typ' => 'JWT'];
$iat = $now; $exp = $iat + 3600;
$claim = [
  'iss' => $sa['client_email'] ?? '',
  'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
  'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
  'iat' => $iat,
  'exp' => $exp,
];
function dbg_b64url($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
$jwtHeader = dbg_b64url(json_encode($header));
$jwtClaim  = dbg_b64url(json_encode($claim));
$dataToSign = $jwtHeader . '.' . $jwtClaim;
$signature = '';
$signOk = false;
if ($keyResource) {
  $signOk = openssl_sign($dataToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
  if (!$signOk) { $errors[] = 'openssl_sign_failed'; }
}
$signatureB64 = $signature ? dbg_b64url($signature) : null;
$jwtSample = $signOk ? ($dataToSign . '.' . $signatureB64) : null;

// Verify (self) using public key details
$verifyOk = false;
if ($signOk && $keyDetails && !empty($keyDetails['key'])) {
  $pubRes = @openssl_pkey_get_public($keyDetails['key']);
  if ($pubRes) {
    $verifyOk = openssl_verify($dataToSign, $signature, $pubRes, OPENSSL_ALGO_SHA256) === 1;
  }
}
if ($signOk && !$verifyOk) { $errors[] = 'openssl_verify_failed'; }

// Attempt to fetch access token (optional) if ?with_token=1
$accessToken = null; $tokenHttp = null; $tokenFailure = null;
if (isset($_GET['with_token']) && $_GET['with_token']=='1' && $signOk) {
  // Mirror logic from fcm_get_access_token but inline to show raw response
  $postFields = http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion' => $jwtSample,
  ]);
  $tokenUri = $claim['aud'];
  $ch = curl_init($tokenUri);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_TIMEOUT => 15,
  ]);
  $resp = curl_exec($ch); $tokenHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($resp === false) { $tokenFailure = 'curl_error:' . $err; }
  else {
    $json = json_decode($resp, true);
    if ($tokenHttp===200 && isset($json['access_token'])) { $accessToken = $json['access_token']; }
    else { $tokenFailure = 'http=' . $tokenHttp . ' body=' . $resp; }
  }
}

// If ?validate_send=1 and we have token for this user, perform validate_only send
$validateSend = null;
if (isset($_GET['validate_send']) && $_GET['validate_send']=='1') {
  require_once __DIR__ . '/../lib/fcm.php';
  $tokens = fcm_get_user_tokens($conn, (int)$authUser['id']);
  if (!empty($tokens)) {
    $validateSend = fcm_send_to_tokens($tokens, 'FCM Debug Validate', 'Dry-run', ['debug'=>'1'], true);
  } else {
    $validateSend = ['ok'=>false,'error'=>'no_tokens_for_user'];
  }
}

api_response(true, [
  'service_account_present' => is_file($saPath),
  'project_id' => $sa['project_id'] ?? null,
  'client_email' => $sa['client_email'] ?? null,
  'key_parsed_ok' => $keyParsedOk,
  'key_bits' => $keyBits,
  'jwt_header_segment' => $jwtHeader,
  'jwt_claim_segment' => $jwtClaim,
  'jwt_signature_segment' => $signatureB64,
  'jwt_sample_truncated' => $jwtSample ? substr($jwtSample,0,80).'...' : null,
  'sign_ok' => $signOk,
  'verify_ok' => $verifyOk,
  'server_time_utc' => $serverTimeIso,
  'client_time_drift_seconds' => $driftSeconds,
  'access_token_http' => $tokenHttp,
  'access_token_present' => $accessToken ? true : false,
  'access_token_truncated' => $accessToken ? substr($accessToken,0,25).'...' : null,
  'token_failure' => $tokenFailure,
  'validate_send' => $validateSend,
  'errors' => $errors,
]);
?>
