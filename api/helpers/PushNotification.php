<?php
// ============================================================
// OneSignal Push Notification Helper
// ============================================================
class PushNotification {

    const APP_ID  = '36b01031-83d9-4f66-bad8-3c32478f9fb2';
    const API_KEY = 'os_v2_app_g2ybammd3fhwnowyhqzepd47wkheljnasx5e7pvg4oyciiqkcf6ow425ay6vikf2xt67ehgywm6frltgls4dub72mp4go6x2bfwsgsi'; // REST API Key

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
                'Authorization: Key ' . self::API_KEY,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
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
                'Authorization: Key ' . self::API_KEY,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
