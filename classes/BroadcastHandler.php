<?php

namespace Bot;

use Config\AppConfig;

class BroadcastHandler
{
    private $db;
    private $apiUrl;
    private $adminChatId;
    private $adminMessageFile = __DIR__ . '/admin_messages.json';

    public function __construct()
    {
        $this->db = new Database();
        $config = AppConfig::getConfig();
        $this->apiUrl = "https://api.telegram.org/bot" . $config['bot']['token'] . "/";
        $this->adminChatId = "7285637709";
    }


    private function sendMessageToUser($chatId, $message, $buttonText, $buttonLink, $broadcastId): bool
    {
        if ($this->db->isMessageSent($chatId, $broadcastId) || $this->db->isMessageFailed($chatId, $broadcastId)) {
            return false;
        }

        $keyboard = [
            "inline_keyboard" => [
                [["text" => $buttonText, "url" => $buttonLink]]
            ]
        ];

        $params = [
            "chat_id" => $chatId,
            "text" => $message,
            "parse_mode" => "HTML",
            "reply_markup" => json_encode($keyboard)
        ];

        $response = $this->sendRequest("sendMessage", $params);

        if (isset($response['ok']) && $response['ok'] && isset($response['result']['message_id'])) {
            $messageId = $response['result']['message_id'];
            return $this->db->markMessageSent($chatId, $messageId, $broadcastId);
        } else {
            $errorCode = $response['error_code'] ?? 0;
            return $this->db->markMessageFailed($chatId, $errorCode, $broadcastId);
        }
    }


    public function broadcastMessage($batchSize = 20, $pauseAfter = 200, $pauseTime = 20): void
    {
        $totalUsers = $this->db->getTotalUsers();
        $totalAttempts = 0;
        $startTime = time();
        $lastAdminUpdate = time();
        $messagesSentInCurrentSecond = 0;
        $lastMessageTime = microtime(true);

        $data = $this->db->lastBroadcast();

        if (!$data) {
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->adminChatId,
                "text" => "❌ خطا: هیچ پیام همگانی برای ارسال پیدا نشد!",
                "parse_mode" => "HTML"
            ]);
            return;
        }

        $broadcastId = $data['id'];
        $message = $data['message_text'];
        $buttonText = $data['button_text'];
        $buttonLink = $data['button_link'];

        $offset = 0;
        while ($totalAttempts < $totalUsers) {
            $users = $this->db->getUsersBatch($batchSize, $offset);
            if (empty($users)) break;

            foreach ($users as $user) {
                $chatId = $user['chat_id'];

                if ($messagesSentInCurrentSecond >= 20) {
                    while (microtime(true) - $lastMessageTime < 1) {
                        usleep(100000);
                    }
                    $messagesSentInCurrentSecond = 0;
                    $lastMessageTime = microtime(true);
                }

                $this->sendMessageToUser($chatId, $message, $buttonText, $buttonLink, $broadcastId);
                $totalAttempts++;
                $messagesSentInCurrentSecond++;

                if (time() - $lastAdminUpdate >= 2) {
                    $elapsedTime = time() - $startTime;
                    $averageTimePerUser = $elapsedTime / max(1, $totalAttempts);
                    $remainingUsers = $totalUsers - $totalAttempts;
                    $estimatedTimeRemaining = round($remainingUsers * $averageTimePerUser / 60, 2);

                    $this->sendAdminNotification($totalAttempts, $totalUsers, $pauseTime, $estimatedTimeRemaining, $broadcastId);
                    $lastAdminUpdate = time();
                }

                if ($totalAttempts % $pauseAfter === 0) {
                    $this->sendRequest("sendMessage", [
                        "chat_id" => $this->adminChatId,
                        "text" => "⏳ ارسال ۲۰۰ پیام انجام شد، مکث برای ۲۰ ثانیه...",
                        "parse_mode" => "HTML"
                    ]);
                    sleep($pauseTime);
                    $messagesSentInCurrentSecond = 0;
                    $lastMessageTime = microtime(true);
                }

                if ($totalAttempts >= $totalUsers) {
                    $this->sendRequest("sendMessage", [
                        "chat_id" => $this->adminChatId,
                        "text" => "✅ ارسال پیام همگانی به پایان رسید.",
                        "parse_mode" => "HTML"
                    ]);
                    return;
                }
            }
            $offset += $batchSize;
        }
    }


    private function saveAdminMessages($adminMessages): void
    {
        file_put_contents($this->adminMessageFile, json_encode($adminMessages, JSON_PRETTY_PRINT));
    }

    private function loadAdminMessages()
    {
        if (file_exists($this->adminMessageFile)) {
            return json_decode(file_get_contents($this->adminMessageFile), true);
        }
        return [];
    }


    private function sendAdminNotification($totalAttempts, $totalUsers, $pauseTime, $estimatedTimeRemaining, $broadcastId): void
    {
        $successfulSends = $this->db->getSuccessfulMessageCount($broadcastId);
        $failedSends = $this->db->getFailedMessageCount($broadcastId);
        $remainingUsers = $totalUsers - $totalAttempts;

        $message = "📢 گزارش ارسال پیام‌ها:\n\n" .
            "✅ تعداد کل کاربران: <b>{$totalUsers}</b>\n" .
            "📨 تعداد تلاش‌های ارسال: <b>{$totalAttempts}</b>\n" .
            "📤 پیام‌های ارسال‌شده موفق: <b>{$successfulSends}</b>\n" .
            "🚫 پیام‌های ارسال‌شده ناموفق: <b>{$failedSends}</b>\n" .
            "⏳ باقی‌مانده: <b>{$remainingUsers}</b>\n" .
            "⌛️ زمان تقریبی باقی‌مانده: <b>{$estimatedTimeRemaining} دقیقه</b>\n\n" .
            "⏳ فرآیند ارسال پیام ۲۰ ثانیه متوقف خواهد شد...";

        $admins = $this->db->getAdmins();
        $adminMessages = $this->loadAdminMessages();

        foreach ($admins as $admin) {
            $chatId = $admin['chat_id'];
            $params = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ];

            if (isset($adminMessages[$chatId])) {
                $params['message_id'] = $adminMessages[$chatId];
                $response = $this->sendRequest("editMessageText", $params);

                if (!isset($response['ok']) || !$response['ok']) {
                    $response = $this->sendRequest("sendMessage", $params);
                    if (isset($response['result']['message_id'])) {
                        $adminMessages[$chatId] = $response['result']['message_id'];
                    }
                }
            } else {
                $response = $this->sendRequest("sendMessage", $params);
                if (isset($response['result']['message_id'])) {
                    $adminMessages[$chatId] = $response['result']['message_id'];
                }
            }
        }

        $this->saveAdminMessages($adminMessages);
    }


    private function sendRequest($method, $params = [])
    {
        $url = "{$this->apiUrl}{$method}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log("❌ خطای cURL در متد {$method}: " . curl_error($ch));
        }
        curl_close($ch);

        return json_decode($response, true);
    }
}
