<?php

namespace Bot;

use Config\AppConfig;

class InlineQueryHandler
{
    private Database $db;
    private $botToken;
    private $botLink;

    public function __construct()
    {
        $config = AppConfig::getConfig();
        $this->db = new Database();
        $this->botToken = $config['bot']['token'];
        $this->botLink = $config['bot']['bot_link'];
    }

    public function handleInlineQuery(array $inlineQuery): void
    {
        $query = $inlineQuery['query'];
        $offset = $inlineQuery['offset'] ?? 0;
        $limit = 10;
        $start = (int)$offset;

        $results = $this->db->searchInlineContent($query, $start, $limit);
        $inlineResults = [];

        foreach ($results as $result) {
            $uniqueToken = $result['unique_token'] ?? null;
            if (!$uniqueToken) {
                continue;
            }
            $inlineResults[] = $this->generateInlineResult($result, $uniqueToken);
        }

        $nextOffset = count($results) < $limit ? "" : (string)($start + $limit);

        $this->sendRequest([
            'inline_query_id' => $inlineQuery['id'],
            'results' => json_encode($inlineResults),
            'cache_time' => 0,
            'next_offset' => $nextOffset
        ]);
    }

    private function generateMessageText($result): string
    {
        $message = "📝 " . ($result['type'] === 'folder' ? "Folder: " . ($result['name'] ?? 'Unnamed Folder') : "File: " . ($result['file_name'] ?? 'Unnamed')) . "\n";

        if ($result['type'] === 'folder' && !empty($result['description'])) {
            $message .= $result['description'] . "\n";
        }

        if (!empty($result['caption']) && $result['type'] !== 'folder') {
            $message .= $result['caption'] . "\n";
        }

        if (!empty($result['price']) && $result['price'] != 0) {
            $message .= "💰 Price: " . $result['price'] . "\n";
        }

        if (!empty($result['stars']) && $result['stars'] != 0) {
            $message .= "⭐ Stars: " . $result['stars'] . "\n";
        }

        return $message;
    }

    private function generateInlineResult($result, $uniqueToken): array
    {
        $encodedToken = urlencode($uniqueToken);
        $baseUrl = $this->botLink;

        switch ($result['type']) {
            case 'photo':
                return [
                    'type' => 'photo',
                    'id' => 'photo_' . $result['id'],
                    'photo_file_id' => $result['file_id'],
                    'thumb_url' => $result['file_id'],
                    'caption' => $this->generateMessageText($result),
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => 'View Photo', 'url' => $baseUrl . $encodedToken . "chanel"]]
                        ]
                    ]
                ];
            case 'text':
                return [
                    'type' => 'article',
                    'id' => 'text_' . $result['id'],
                    'title' => $result['file_name'] ?? 'Text Content',
                    'description' => $result['caption'] ?? 'Ram Ai',
                    'input_message_content' => [
                        'message_text' => "📝 Text Content: " . $result['file_name'] . "\n\n📌 Description: " . ($result['caption'] ?? '')
                    ],
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => 'View Details', 'url' => $baseUrl . $encodedToken]]
                        ]
                    ]
                ];

            case 'file':
                return [
                    'type' => 'article',
                    'id' => 'file_' . $result['id'],
                    'title' => $result['file_name'] ?? 'Unnamed File',
                    'description' => $result['caption'] ?? 'Ram Ai',
                    'input_message_content' => [
                        'message_text' => $this->generateMessageText($result)
                    ],
                    'thumb_url' => 'https://rammehraz.com/Rambot/Rammehraz/assets/images/baf09e6a160d7d7b9917759c23d34dfb.jpg',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => 'Get File', 'url' => $baseUrl . $encodedToken . "chanel"]]
                        ]
                    ]
                ];

            case 'folder':
                return [
                    'type' => 'article',
                    'id' => 'folder_' . $result['id'],
                    'title' => $result['name'] ?? 'Unnamed Folder',
                    'description' => $result['description'] ?? 'Ram Ai',
                    'input_message_content' => [
                        'message_text' => $this->generateMessageText($result)
                    ],
                    'thumb_url' => 'https://rammehraz.com/Rambot/Rammehraz/assets/images/baf09e6a160d7d7b9917759c23d34dfb.jpg',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => 'View Folder', 'url' => $baseUrl . $encodedToken]]
                        ]
                    ]
                ];


            case 'video':
                return [
                    'type' => 'video',
                    'id' => 'video_' . $result['id'],
                    'video_file_id' => $result['file_id'],
                    'title' => $result['file_name'] ?? 'Unnamed Video',
                    'description' => $result['caption'] ?? 'Ram Ai',
                    'caption' => $this->generateMessageText($result),
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => 'Play Video', 'url' => $baseUrl . $encodedToken . "chanel"]]
                        ]
                    ]
                ];


            default:
                return [];
        }
    }

    private function sendRequest(array $data): void
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/" . "answerInlineQuery";
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data)
            ]
        ];
        $context = stream_context_create($options);
        file_get_contents($url, false, $context);
    }
}
