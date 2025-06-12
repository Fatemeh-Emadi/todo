<?php

namespace Bot;

use Config\AppConfig;
use DateTime;
use DateTimeZone;
use Payment\ZarinpalPaymentHandler;
use function Config\gregorian_to_jalali;
use function Config\jdate_words;
use function Config\tr_num;

require_once __DIR__ . "/../config/jdf.php";


class BotHandler
{

    private $chatId;
    private $text;
    private $messageId;
    private $message;
    public $db;
    private $fileHandler;
    private $zarinpalPaymentHandler;


    public function __construct($chatId, $text, $messageId, $message)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->messageId = $messageId;
        $this->message = $message;
        $this->db = new Database();
        $this->fileHandler = new FileHandler();
        $config = AppConfig::getConfig();
        $this->botToken = $config['bot']['token'];
        $this->botLink = $config['bot']['bot_link'];


        $this->zarinpalPaymentHandler = new ZarinpalPaymentHandler();
    }

    public function deleteMessageWithDelay(): void
    {
        $this->sendRequest("deleteMessage", [
            "chat_id" => $this->chatId,
            "message_id" => $this->messageId
        ]);
    }

    public function handleSuccessfulPayment($update): void
    {
        $userLanguage = $this->db->getUserLanguage($this->chatId);

        if (isset($update['message']['successful_payment'])) {
            $chatId = $update['message']['chat']['id'];
            $payload = $update['message']['successful_payment']['invoice_payload'];
            $successfulPayment = $update['message']['successful_payment'];


        }
    }


    public function handlePreCheckoutQuery($update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $query_id = $update['pre_checkout_query']['id'];

            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - Received pre_checkout_query: " . print_r($update, true) . "\n", FILE_APPEND);

            $url = "https://api.telegram.org/bot" . $this->botToken . "/answerPreCheckoutQuery";
            $post_fields = [
                'pre_checkout_query_id' => $query_id,
                'ok' => true,
                'error_message' => ""
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_fields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            curl_close($ch);
            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - answerPreCheckoutQuery Response: " . print_r(json_decode($response, true), true) . "\n", FILE_APPEND);
        }
    }


   public function handleCallbackQuery($callbackQuery): void
    {
        $callbackData = $callbackQuery["data"] ?? null;
        $chatId = $callbackQuery["message"]["chat"]["id"] ?? null;
        $callbackQueryId = $callbackQuery["id"] ?? null;
        $messageId = $callbackQuery["message"]["message_id"] ?? null;
        $currentKeyboard = $callbackQuery["message"]["reply_markup"]["inline_keyboard"] ?? [];
        $userLanguage = $this->db->getUserLanguage($this->chatId);

        $user = $this->message['from'] ?? $callbackQuery['from'] ?? null;

        if ($user !== null) {
            $this->db->saveUser($user);
        } else {
            error_log("❌ Cannot save user: 'from' is missing in both message and callbackQuery.");
        }

        if (!$callbackData || !$chatId || !$callbackQueryId || !$messageId) {
            error_log("Callback query missing required data.");
            return;
        }

        // Handle task-related callback queries
        switch ($callbackData) {
            case 'create_task':
                $this->db->updateUserState($this->chatId, 'awaiting_task_text');
                $this->sendRequest("sendMessage", [
                    "chat_id" => $chatId,
                    "text" => $userLanguage === 'fa' ? "لطفاً متن وظیفه را وارد کنید:" : "Please enter the task text:",
                    "reply_markup" => json_encode(["force_reply" => true])
                ]);
                break;
            case 'list_tasks':
                $this->showTaskList($chatId, $userLanguage);
                break;
            default:
                error_log("Unknown callback data: $callbackData");
        }

        // Answer the callback query to remove the loading state
        $this->sendRequest("answerCallbackQuery", [
            "callback_query_id" => $callbackQueryId
        ]);
    }

    public function handleRequest(): void
    {
        if (isset($this->message["from"])) {
            $this->db->saveUser($this->message["from"]);
        } else {
            error_log("BotHandler::handleRequest: 'from' field missing for non-start message. Update type might not be a user message.");
        }

        $state = $this->db->getUserState($this->chatId) ?? 'default';
        $userLanguage = $this->db->getUserLanguage($this->chatId);

        // Handle task input
        if ($state === 'awaiting_task_text' && isset($this->message["text"])) {
            $this->createTask($this->message["text"]);
            $this->db->updateUserState($this->chatId, 'default');
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $userLanguage === 'fa' ? "وظیفه با موفقیت ثبت شد!" : "Task created successfully!",
                "reply_markup" => $this->getMainKeyboard($userLanguage)
            ]);
        } else {
            // Show main menu with buttons
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $userLanguage === 'fa' ? "لطفاً یک گزینه را انتخاب کنید:" : "Please choose an option:",
                "reply_markup" => $this->getMainKeyboard($userLanguage)
            ]);
        }
    }

    private function getMainKeyboard($language): string
    {
        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => $language === 'fa' ? "ایجاد وظیفه" : "Create Task", "callback_data" => "create_task"],
                    ["text" => $language === 'fa' ? "لیست وظایف" : "List of Tasks", "callback_data" => "list_tasks"]
                ]
            ]
        ];
        return json_encode($keyboard);
    }

    private function createTask($taskText): void
    {
        $this->db->insertTask($this->chatId, $taskText, $this->jdate('Y/m/d H:i:s'));
    }

    private function showTaskList($chatId, $language): void
    {
        $tasks = $this->db->getTasksByChatId($chatId);
        if (empty($tasks)) {
            $this->sendRequest("sendMessage", [
                "chat_id" => $chatId,
                "text" => $language === 'fa' ? "هیچ وظیفه‌ای یافت نشد." : "No tasks found.",
                "reply_markup" => $this->getMainKeyboard($language)
            ]);
            return;
        }

        $taskList = $language === 'fa' ? "لیست وظایف شما:\n" : "Your task list:\n";
        foreach ($tasks as $index => $task) {
            $taskList .= ($index + 1) . ". " . $task['task_text'] . " (" . $task['created_at'] . ")\n";
        }

        $this->sendRequest("sendMessage", [
            "chat_id" => $chatId,
            "text" => $taskList,
            "reply_markup" => $this->getMainKeyboard($language)
        ]);
    }


    public function sendRequest($method, $data)
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/$method";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        $this->logTelegramRequest($method, $data, $response, $httpCode, $curlError);

        if ($curlError) {
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            $errorResponse = json_decode($response, true);
            $errorMessage = $errorResponse['description'] ?? 'Unknown error';
            return false;
        }
    }

    private function logTelegramRequest($method, $data, $response, $httpCode, $curlError = null): void
    {
        $logData = [
            'time' => date("Y-m-d H:i:s"),
            'method' => $method,
            'request_data' => $data,
            'response' => $response,
            'http_code' => $httpCode,
            'curl_error' => $curlError
        ];

        $logMessage = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    }


    public function jdate($format, $timestamp = '', $none = '', $time_zone = 'Asia/Tehran', $tr_num = 'fa')
    {

        $T_sec = 0;/* <= رفع خطاي زمان سرور ، با اعداد '+' و '-' بر حسب ثانيه */

        if ($time_zone != 'local') date_default_timezone_set(($time_zone === '') ? 'Asia/Tehran' : $time_zone);
        $ts = $T_sec + (($timestamp === '') ? time() : tr_num($timestamp));
        $date = explode('_', date('H_i_j_n_O_P_s_w_Y', $ts));
        list($j_y, $j_m, $j_d) = gregorian_to_jalali($date[8], $date[3], $date[2]);
        $doy = ($j_m < 7) ? (($j_m - 1) * 31) + $j_d - 1 : (($j_m - 7) * 30) + $j_d + 185;
        $kab = (((($j_y % 33) % 4) - 1) == ((int)(($j_y % 33) * 0.05))) ? 1 : 0;
        $sl = strlen($format);
        $out = '';
        for ($i = 0; $i < $sl; $i++) {
            $sub = substr($format, $i, 1);
            if ($sub == '\\') {
                $out .= substr($format, ++$i, 1);
                continue;
            }
            switch ($sub) {

                case'E':
                case'R':
                case'x':
                case'X':
                    $out .= 'http://jdf.scr.ir';
                    break;

                case'B':
                case'e':
                case'g':
                case'G':
                case'h':
                case'I':
                case'T':
                case'u':
                case'Z':
                    $out .= date($sub, $ts);
                    break;

                case'a':
                    $out .= ($date[0] < 12) ? 'ق.ظ' : 'ب.ظ';
                    break;

                case'A':
                    $out .= ($date[0] < 12) ? 'قبل از ظهر' : 'بعد از ظهر';
                    break;

                case'b':
                    $out .= (int)($j_m / 3.1) + 1;
                    break;

                case'c':
                    $out .= $j_y . '/' . $j_m . '/' . $j_d . ' ،' . $date[0] . ':' . $date[1] . ':' . $date[6] . ' ' . $date[5];
                    break;

                case'C':
                    $out .= (int)(($j_y + 99) / 100);
                    break;

                case'd':
                    $out .= ($j_d < 10) ? '0' . $j_d : $j_d;
                    break;

                case'D':
                    $out .= jdate_words(array('kh' => $date[7]), ' ');
                    break;

                case'f':
                    $out .= jdate_words(array('ff' => $j_m), ' ');
                    break;

                case'F':
                    $out .= jdate_words(array('mm' => $j_m), ' ');
                    break;

                case'H':
                    $out .= $date[0];
                    break;

                case'i':
                    $out .= $date[1];
                    break;

                case'j':
                    $out .= $j_d;
                    break;

                case'J':
                    $out .= jdate_words(array('rr' => $j_d), ' ');
                    break;

                case'k';
                    $out .= tr_num(100 - (int)($doy / ($kab + 365) * 1000) / 10, $tr_num);
                    break;

                case'K':
                    $out .= tr_num((int)($doy / ($kab + 365) * 1000) / 10, $tr_num);
                    break;

                case'l':
                    $out .= jdate_words(array('rh' => $date[7]), ' ');
                    break;

                case'L':
                    $out .= $kab;
                    break;

                case'm':
                    $out .= ($j_m > 9) ? $j_m : '0' . $j_m;
                    break;

                case'M':
                    $out .= jdate_words(array('km' => $j_m), ' ');
                    break;

                case'n':
                    $out .= $j_m;
                    break;

                case'N':
                    $out .= $date[7] + 1;
                    break;

                case'o':
                    $jdw = ($date[7] == 6) ? 0 : $date[7] + 1;
                    $dny = 364 + $kab - $doy;
                    $out .= ($jdw > ($doy + 3) and $doy < 3) ? $j_y - 1 : (((3 - $dny) > $jdw and $dny < 3) ? $j_y + 1 : $j_y);
                    break;

                case'O':
                    $out .= $date[4];
                    break;

                case'p':
                    $out .= jdate_words(array('mb' => $j_m), ' ');
                    break;

                case'P':
                    $out .= $date[5];
                    break;

                case'q':
                    $out .= jdate_words(array('sh' => $j_y), ' ');
                    break;

                case'Q':
                    $out .= $kab + 364 - $doy;
                    break;

                case'r':
                    $key = jdate_words(array('rh' => $date[7], 'mm' => $j_m));
                    $out .= $date[0] . ':' . $date[1] . ':' . $date[6] . ' ' . $date[4] . ' ' . $key['rh'] . '، ' . $j_d . ' ' . $key['mm'] . ' ' . $j_y;
                    break;

                case's':
                    $out .= $date[6];
                    break;

                case'S':
                    $out .= 'ام';
                    break;

                case't':
                    $out .= ($j_m != 12) ? (31 - (int)($j_m / 6.5)) : ($kab + 29);
                    break;

                case'U':
                    $out .= $ts;
                    break;

                case'v':
                    $out .= jdate_words(array('ss' => ($j_y % 100)), ' ');
                    break;

                case'V':
                    $out .= jdate_words(array('ss' => $j_y), ' ');
                    break;

                case'w':
                    $out .= ($date[7] == 6) ? 0 : $date[7] + 1;
                    break;

                case'W':
                    $avs = (($date[7] == 6) ? 0 : $date[7] + 1) - ($doy % 7);
                    if ($avs < 0) $avs += 7;
                    $num = (int)(($doy + $avs) / 7);
                    if ($avs < 4) {
                        $num++;
                    } elseif ($num < 1) {
                        $num = ($avs == 4 or $avs == ((((($j_y % 33) % 4) - 2) == ((int)(($j_y % 33) * 0.05))) ? 5 : 4)) ? 53 : 52;
                    }
                    $aks = $avs + $kab;
                    if ($aks == 7) $aks = 0;
                    $out .= (($kab + 363 - $doy) < $aks and $aks < 3) ? '01' : (($num < 10) ? '0' . $num : $num);
                    break;

                case'y':
                    $out .= substr($j_y, 2, 2);
                    break;

                case'Y':
                    $out .= $j_y;
                    break;

                case'z':
                    $out .= $doy;
                    break;

                default:
                    $out .= $sub;
            }
        }
        return ($tr_num != 'en') ? tr_num($out, 'fa', '.') : $out;
    }

}
