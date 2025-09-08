<?php
$base = getenv('TAGCACHE_URL') ?: 'http://127.0.0.1:8080';

function post($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function get_json($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

echo "Base: $base\n";

$r = post($base.'/put', [
  'key'=>'user:42','value'=>'hello world',
  'tags'=>['users','trial'],'ttl_ms'=>60000
]);
echo "PUT: ".json_encode($r)."\n";

$r = get_json($base.'/get/user:42');
echo "GET: ".json_encode($r)."\n";

$r = get_json($base.'/keys-by-tag?tag=users&limit=100');
echo "BY TAG: ".json_encode($r)."\n";

$r = post($base.'/invalidate-tag', ['tag'=>'trial']);
echo "Invalidate tag: ".json_encode($r)."\n";

$r = get_json($base.'/get/user:42');
echo "GET after invalidate: ".json_encode($r)."\n";

$r = get_json($base.'/stats');
echo "STATS: ".json_encode($r)."\n";
