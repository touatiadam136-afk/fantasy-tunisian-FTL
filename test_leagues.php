<?php
$base = 'http://localhost/ftl/backend/API';

function http(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo $method . ' ' . $url . ' => HTTP ' . $code . "\n" . $raw . "\n\n";
    return ['code' => $code, 'json' => json_decode($raw, true) ?: []];
}

$t = time();
$a = http('POST', $base . '/register.php', [
    'email' => "owner{$t}@test.com", 'password' => '12345678', 'team_name' => 'Owner FC',
]);
$b = http('POST', $base . '/register.php', [
    'email' => "friend{$t}@test.com", 'password' => '12345678', 'team_name' => 'Friend FC',
]);
$tokenA = $a['json']['token'] ?? '';
$tokenB = $b['json']['token'] ?? '';
if ($tokenA === '' || $tokenB === '') {
    die("register failed\n");
}

$created = http('POST', $base . '/league_create.php', ['name' => 'Beta Cup'], $tokenA);
$code = $created['json']['league']['code'] ?? '';
$id   = $created['json']['league']['id'] ?? 0;
if ($created['code'] !== 201 || $code === '') {
    die("create failed\n");
}

$join = http('POST', $base . '/league_join.php', ['code' => $code], $tokenB);
if ($join['code'] !== 200) {
    die("join failed\n");
}

$again = http('POST', $base . '/league_join.php', ['code' => $code], $tokenB);
if ($again['code'] !== 409) {
    die("expected 409 on second join\n");
}

http('GET', $base . '/leagues.php', null, $tokenB);
$league = http('GET', $base . '/league.php?id=' . $id, null, $tokenA);
http('GET', $base . '/ranking.php', null, $tokenA);

if (($league['json']['league']['member_count'] ?? 0) !== 2) {
    die("expected 2 members\n");
}

echo "LEAGUES OK code={$code}\n";
