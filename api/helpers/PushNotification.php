<?php
// ============================================================
// OneSignal Push Notification Helper
// ============================================================
class PushNotification {

    const APP_ID  = '36b01031-83d9-4f66-bad8-3c32478f9fb2';
    // API key defined in config.php as ONESIGNAL_API_KEY // REST API Key

    /**
     * Send push to specific user by their External ID (our user ID)
     */
    public static function send(int $userId, string $title, string $message, string $url = '/'): void {
        $payload = [
            'app_id'             => self::APP_ID,
            'target_channel'     => 'push',
            'include_aliases'    => ['external_id' => [strval($userId)]],
            'headings'           => ['en' => $title],
            'contents'           => ['en' => $message],
            'url'                => 'https://nuevaexpress.com' . $url,
            'small_icon'         => 'ic_stat_onesignal_default',
            'large_icon'         => 'https://nuevaexpress.com/assets/img/icon-192.png',
        ];

        $ch = curl_init('https://api.onesignal.com/notifications');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Key ' . ONESIGNAL_API_KEY,
                'accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);
        // Log result
        error_log('OneSignal push to user ' . $userId . ': ' . ($error ?: $response));
    }

    /**
     * Send to multiple users
     */
    public static function sendToMany(array $userIds, string $title, string $message, string $url = '/'): void {
        if (empty($userIds)) return;
        $ids = array_map('strval', $userIds);
        $payload = [
            'app_id'          => self::APP_ID,
            'target_channel'  => 'push',
            'include_aliases' => ['external_id' => $ids],
            'headings'        => ['en' => $title],
            'contents'        => ['en' => $message],
            'url'             => 'https://nuevaexpress.com' . $url,
            'large_icon'      => 'https://nuevaexpress.com/assets/img/icon-192.png',
        ];

        $ch = curl_init('https://api.onesignal.com/notifications');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Key ' . ONESIGNAL_API_KEY,
                'accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        error_log('OneSignal sendToMany — ids: ' . implode(',', $ids));
        error_log('OneSignal sendToMany — HTTP ' . $httpCode . ' — ' . ($error ?: $response));
    }
}
