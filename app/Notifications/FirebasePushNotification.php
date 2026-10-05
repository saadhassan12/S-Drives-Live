<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use GuzzleHttp\Client;
use Google_Client;

class FirebasePushNotification extends Notification
{
    private $title;
    private $body;
    private $deviceToken;
    private $data;

    public function __construct($title, $body, $deviceToken, array $data = [])
    {
        $this->title = $title;
        $this->body = $body;
        $this->deviceToken = $deviceToken;
        $this->data = $data;
    }

    public function via($notifiable)
    {
        return [];
    }
public function toFirebase()
{
    $accessToken = $this->getAccessToken();
    if (!$accessToken) {
        return ['error' => 'Access token not generated'];
    }

    // ✅ Unique ID banaya (device + title + body + current second)
    $uniqueId = md5($this->deviceToken.$this->title.$this->body.now()->format('YmdHis'));

    // ✅ Agar same notification 1 sec ke andar already bheja gaya to block kar do
    if (cache()->has("fcm_sent_".$uniqueId)) {
        return [
            'message' => 'Duplicate blocked',
            'reason'  => 'Same notification was already sent within 1 second',
        ];
    }

    // ✅ Cache me 1 sec ke liye store karna
    cache()->put("fcm_sent_".$uniqueId, true, 30);

    $client = new Client();
    $url = "https://fcm.googleapis.com/v1/projects/" . env('FCM_PROJECT_ID') . "/messages:send";

    $payload = [
        'message' => [
            'token' => $this->deviceToken,
             'notification' => [ 
                'title' => $this->title,
                'body'  => $this->body,
            ],
           
            'apns' => [
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'notification.wav',
                        'content-available' => 1,
                    ],
                ],
            ],

            'android' => [
            'priority' => 'high',
                    'notification' => [
                        'sound' => 'custom_sound',
                        'channel_id' => 'high_importance_channel_custom',
                    ],
            ],
        ],
    ];

    if (!empty($this->data)) {
        $payload['message']['data'] = array_map('strval', $this->data);
    }

    try {
        $response = $client->post($url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return [
            'message' => 'Notification sent!',
            'firebase_request' => $payload,
            'firebase_response' => json_decode($response->getBody(), true),
        ];
    } catch (\Exception $e) {
        return [
            'error' => 'Notification failed',
            'details' => $e->getMessage(),
        ];
    }
}






    private function getAccessToken()
    {
        $serviceAccountPath = base_path(env('FCM_SERVICE_ACCOUNT_PATH'));

        if (!file_exists($serviceAccountPath)) {
            dd("Firebase credentials file not found at: " . $serviceAccountPath);
        }

        $client = new Google_Client();
        $client->setAuthConfig($serviceAccountPath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $token = $client->fetchAccessTokenWithAssertion();

        return $token['access_token'] ?? null;
    }
}
