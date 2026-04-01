<?php

function request($method, $url, $data = null, $token = null, $internalToken = null) {
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\nAccept: application/json\r\n",
            'method'  => $method,
            'ignore_errors' => true
        ]
    ];
    if ($data) {
        $options['http']['content'] = json_encode($data);
    }
    if ($token) {
        $options['http']['header'] .= "Authorization: Bearer " . $token . "\r\n";
    }
    if ($internalToken) {
        $options['http']['header'] .= "X-Internal-Token: " . $internalToken . "\r\n";
    }

    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return json_decode($result, true) ?? $result;
}

$baseUrl = "http://localhost:8001/api/v1/auth";
$internalUrl = "http://localhost:8001/api/v1/internal/auth";

echo "======================================\n";
echo "1. Testing Registration\n";
$req = request('POST', "$baseUrl/register", [
    'name' => 'Test User',
    'email' => 'test_'.rand().'@bankcore.test',
    'password' => 'Password@123',
    'password_confirmation' => 'Password@123',
    'phone' => '+91-9999999999'
]);
echo json_encode($req, JSON_PRETTY_PRINT) . "\n\n";

echo "======================================\n";
echo "2. Testing Login (Admin User)\n";
$login = request('POST', "$baseUrl/login", [
    'email' => 'admin@bankcore.test',
    'password' => 'Password@123'
]);
echo json_encode($login, JSON_PRETTY_PRINT) . "\n\n";

$accessToken = $login['data']['token'] ?? null;
$refreshToken = $login['data']['refresh_token'] ?? null;

echo "======================================\n";
echo "3. Testing /me Endpoint (Protected)\n";
$me = request('GET', "$baseUrl/me", null, $accessToken);
echo json_encode($me, JSON_PRETTY_PRINT) . "\n\n";

echo "======================================\n";
echo "4. Testing /refresh Endpoint\n";
$refresh = request('POST', "$baseUrl/refresh", ['refresh_token' => $refreshToken]);
echo json_encode($refresh, JSON_PRETTY_PRINT) . "\n\n";

$newAccessToken = $refresh['data']['token'] ?? null;

echo "======================================\n";
echo "5. Testing /internal/auth/verify\n";
$verify = request('GET', "$internalUrl/verify", null, $newAccessToken, "bankcore-internal-secret-2026");
echo json_encode($verify, JSON_PRETTY_PRINT) . "\n\n";

echo "======================================\n";
echo "6. Testing Logout\n";
$logout = request('POST', "$baseUrl/logout", null, $newAccessToken);
echo json_encode($logout, JSON_PRETTY_PRINT) . "\n\n";

echo "======================================\n";
echo "7. Testing /me After Logout (Should fail)\n";
$meFail = request('GET', "$baseUrl/me", null, $newAccessToken);
echo json_encode($meFail, JSON_PRETTY_PRINT) . "\n\n";
