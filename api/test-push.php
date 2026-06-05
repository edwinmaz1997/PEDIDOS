<?php
// Test OneSignal push - DELETE THIS FILE AFTER TESTING
require_once __DIR__ . '/helpers/PushNotification.php';

$userId = isset($_GET['uid']) ? (int)$_GET['uid'] : 10;
PushNotification::send($userId, '🧪 Test NuevaExpress', 'Si ves esto las notificaciones funcionan!', '/');

// Also test with direct curl and show response
$payload = [
    'app_id'          => '36b01031-83d9-4f66-bad8-3c32478f9fb2',
    'target_channel'  => 'push',
    'include_aliases' => ['external_id' => [strval($userId)]],
    'headings'        => ['en' => '🧪 Test'],
    'contents'        => ['en' => 'Test directo desde PHP'],
    'url'             => 'https://nuevaexpress.com',
];

$ch = curl_init('https://api.onesignal.com/notifications');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Key os_v2_app_g2ybammd3fhwnowyhqzepd47wkheljnasx5e7pvg4oyciiqkcf6ow425ay6vikf2xt67ehgywm6frltgls4dub72mp4go6x2bfwsgsi',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');
echo json_encode([
    'user_id'   => $userId,
    'http_code' => $httpCode,
    'response'  => json_decode($response),
    'curl_error'=> $error
]);
