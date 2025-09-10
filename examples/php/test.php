<?php
/**
 * TagCache PHP Example - Basic HTTP API usage
 * 
 * @author Md. Aminul Islam Sarker <aminshamim@gmail.com>
 * @link https://github.com/aminshamim/tagcache
 */

// Base API URL
$base = getenv('TAGCACHE_URL') ?: 'http://127.0.0.1:8080';

// Load credentials either from environment or local credential.txt
$username = getenv('TAGCACHE_USER');
$password = getenv('TAGCACHE_PASS');
if (!$username || !$password) {
  $credFile = __DIR__.'/../../credential.txt';
  if (is_readable($credFile)) {
    $lines = file($credFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (str_starts_with($line, 'username=')) { $username = substr($line, 9); }
      elseif (str_starts_with($line, 'password=')) { $password = substr($line, 9); }
    }
  }
}

$token = null; // bearer token once logged in

function http_do($method, $url, $data = null, $authToken = null, $basicUser = null, $basicPass = null) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  $headers = ['Accept: application/json'];
  if ($data !== null) {
    $json = json_encode($data);
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }
  if ($authToken) {
    $headers[] = 'Authorization: Bearer '.$authToken;
  } elseif ($basicUser && $basicPass) {
    $basic = base64_encode($basicUser.':'.$basicPass);
    $headers[] = 'Authorization: Basic '.$basic;
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('HTTP error: '.$err);
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, json_decode($resp, true)];
}

echo "Base: $base\n";
if ($username && $password) {
  echo "Attempting login with credential user...\n";
  [$code,$body] = http_do('POST', $base.'/auth/login', ['username'=>$username,'password'=>$password], null, $username, $password);
  if ($code === 200 && isset($body['token'])) {
    $token = $body['token'];
    echo "Login OK, token acquired.\n";
  } else {
    echo "Login failed code=$code body=".json_encode($body)."\n";
  }
} else {
  echo "No credentials found; proceeding without auth (endpoints will 401).\n";
}
try {
  // Put
  [, $r] = http_do('POST', $base.'/put', [
    'key'=>'user:42','value'=>'hello world','tags'=>['users','trial'],'ttl_ms'=>60000
  ], $token);
  echo "PUT: ".json_encode($r)."\n";

  [, $r] = http_do('GET', $base.'/get/user:42', null, $token);
  echo "GET: ".json_encode($r)."\n";

  [, $r] = http_do('GET', $base.'/keys-by-tag?tag=users&limit=100', null, $token);
  echo "BY TAG: ".json_encode($r)."\n";

  // [, $r] = http_do('POST', $base.'/invalidate-tag', ['tag'=>'trial'], $token);
  // echo "Invalidate tag: ".json_encode($r)."\n";

  // [, $r] = http_do('GET', $base.'/get/user:42', null, $token);
  // echo "GET after invalidate: ".json_encode($r)."\n";

  // // stats may be public; still try with token
  // [, $r] = http_do('GET', $base.'/stats', null, $token);
  // echo "STATS: ".json_encode($r)."\n";
} catch(Throwable $e) {
  fwrite(STDERR, "Error: ".$e->getMessage()."\n");
  exit(1);
}
