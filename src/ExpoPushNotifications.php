<?php

namespace Relative\LaravelExpoPushNotifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Relative\LaravelExpoPushNotifications\Models\PushToken;

class ExpoPushNotifications
{
    const EXPIRE_FOR_STATUSES = ['DeviceNotRegistered', 'InvalidCredentials'];
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('expo-push-notifications.service_url');
    }

    public function send($notifiable, Notification $notification): void
    {
        $batchId = Uuid::uuid4();
        $message = $notification->toExpoPushNotification($notifiable);
        $message->to = $notifiable->pushTokens()->active()->get()->pluck('token')->toArray();

        $tickets = $this->dispatch($message);
        collect($message->to)->each(function ($token, $index) use ($message, $tickets, $notifiable, $batchId) {
            if (isset($tickets[$index])) {
                $this->createPushNotificationRecord($tickets[$index], $token, $batchId, $message, $notifiable);
            }
        });
    }

    public function dispatch($pushNotification): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Accept-encoding' => 'gzip, deflate',
            'Content-Type' => 'application/json'
        ])->post("{$this->baseUrl}/send", (array)$pushNotification);

        return $response->json()['data'];
    }

    /**
     * @param $ticket
     * @param $token
     * @param \Ramsey\Uuid\UuidInterface $batchId
     * @param $notification
     * @param $notifiable
     * @return mixed
     */
    public function createPushNotificationRecord($ticket, $token, \Ramsey\Uuid\UuidInterface $batchId, $notification, $notifiable)
    {
        $pushToken = PushToken::findByReference($token);
        if ($ticket['status'] === 'error' && in_array($ticket['details']['error'], self::EXPIRE_FOR_STATUSES)) {
            try {
                $pushToken->expire();
            } catch (\Exception $exception) {
                Log::error("Unable to find token $token to expire.");
            }
        }
        $pushToken->touch();
        return \Relative\LaravelExpoPushNotifications\Models\PushNotification::create([
            'batch_id' => $batchId->toString(),
            'notification' => (array)$notification,
            'notifiable_id' => $notifiable->id,
            'notifiable_type' => get_class($notifiable),
            'token' => $token,
            'status' => $ticket['status'],
            'ticket' => $ticket['id'] ?? '',
            'error' => $ticket['status'] === 'error' ? $ticket['details']['error'] : null
        ]);
    }
}
