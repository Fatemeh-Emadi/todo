<?php

namespace Bot;

use Exception;
use mysqli;
use Config\AppConfig;

class Database
{
    private $mysqli;

    public function __construct()
    {
        $config = AppConfig::getConfig();
        $this->botLink = $config['bot']['bot_link'];
        $dbConfig = $config['database'];


        $this->mysqli = new mysqli(
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database']
        );

        if ($this->mysqli->connect_errno) {
            error_log("❌ Database Connection Failed: " . $this->mysqli->connect_error);
            exit();
        }

        $this->mysqli->set_charset("utf8mb4");
    }
    public function getAllUsers()
    {
        $query = "SELECT * FROM users";

        $stmt = $this->mysqli->prepare($query);

        if (!$stmt) {
            error_log("❌ Failed to prepare statement in getAllUsers: " . $this->mysqli->error);
            return [];
        }

        if (!$stmt->execute()) {
            error_log("❌ Failed to execute statement in getAllUsers: " . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);

        $stmt->close();
        return $users;
    }

    public function getAdmins()
    {
        $stmt = $this->mysqli->prepare("SELECT id, chat_id, username FROM users WHERE is_admin = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $admins = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $admins;
    }

    public function getUsernameByChatId($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `username` FROM `users` WHERE `chat_id` = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result['username'] ?? 'Unknown';
    }

    public function setUserLanguage($chatId, $language)
    {
        $stmt = $this->mysqli->prepare("UPDATE `users` SET `language` = ? WHERE `chat_id` = ?");
        $stmt->bind_param("si", $language, $chatId);
        return $stmt->execute();
    }
    public function getUserByUsername($username)
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getUserLanguage($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `language` FROM `users` WHERE `chat_id` = ? LIMIT 1");
        $stmt->bind_param('s', $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result['language'] ?? 'fa';
    }
    public function getUserInfo($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `username`, `first_name`, `last_name` FROM `users` WHERE `chat_id` = ?");
        if (!$stmt) {
            error_log("Failed to prepare statement: " . $this->mysqli->error);
            return null;
        }

        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();

        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            error_log("User not found for chat_id: {$chatId}");
            return null;
        }

        return $user;
    }


    public function saveUser($user, $entryToken = null)
    {
        $excludedUsers = [193551966];
        if (in_array($user['id'], $excludedUsers)) {
            return false;
        }

        $stmt = $this->mysqli->prepare("SELECT id, referral_code, language FROM users WHERE chat_id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();

        $username = $user['username'] ?? '';
        $firstName = $user['first_name'] ?? '';
        $lastName = $user['last_name'] ?? '';
        $language = $user['language_code'] ?? 'en';

    }


    public function getUserByChatIdOrUsername($identifier)
    {
        if (is_numeric($identifier)) {
            $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE chat_id = ?");
            $stmt->bind_param("i", $identifier);
        } else {
            $username = ltrim($identifier, '@');
            $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user;
    }

    public function getUserFullName($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `first_name`, `last_name` FROM `users` WHERE `chat_id` = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''));
    }
    public function getUsersBatch($limit = 20, $offset = 0)
    {
        $query = "SELECT id, chat_id, username, first_name, last_name, join_date, last_activity, status, language, is_admin, entry_token 
              FROM users 
              ORDER BY id ASC 
              LIMIT ? OFFSET ?";

        $stmt = $this->mysqli->prepare($query);

        if (!$stmt) {
            error_log("❌ Prepare failed: " . $this->mysqli->error);
            return [];
        }

        $stmt->bind_param("ii", $limit, $offset);

        if (!$stmt->execute()) {
            error_log("❌ Execute failed: " . $stmt->error);
            return [];
        }

        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function updateUserStatus($chatId, $status)
    {
        $query = "UPDATE users SET status = ? WHERE chat_id = ?";
        $stmt = $this->mysqli->prepare($query);

        if (!$stmt) {
            error_log("Database Error: " . $this->mysqli->error);
            return false;
        }

        $stmt->bind_param("si", $status, $chatId);

        if (!$stmt->execute()) {
            error_log("Error updating status for User ID: $chatId");
            $stmt->close();
            return false;
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        return $affectedRows > 0;
    }


    public function isAdmin($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT is_admin FROM users WHERE chat_id = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user && $user['is_admin'] == 1;
    }

    public function getUserByUserId($userId)
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE chat_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }


     public function insertTask($chatId, $taskText, $createdAt)
    {
        $stmt = $this->mysqli->prepare("INSERT INTO tasks (telegram_id, task_text, created_at) VALUES (?, ?, ?)");
        if (!$stmt) {
            error_log("❌ Failed to prepare statement in insertTask: " . $this->mysqli->error);
            return false;
        }

        $stmt->bind_param("iss", $chatId, $taskText, $createdAt);
        if (!$stmt->execute()) {
            error_log("❌ Failed to execute statement in insertTask: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        return $affectedRows > 0;
    }

    public function getTasksByChatId($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT task_text, created_at FROM tasks WHERE telegram_id = ? ORDER BY created_at DESC");
        if (!$stmt) {
            error_log("❌ Failed to prepare statement in getTasksByChatId: " . $this->mysqli->error);
            return [];
        }

        $stmt->bind_param("i", $chatId);
        if (!$stmt->execute()) {
            error_log("❌ Failed to execute statement in getTasksByChatId: " . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $tasks = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $tasks;
    }
     public function getUserState($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT task_input_status FROM users WHERE chat_id = ?");
        if (!$stmt) {
            error_log("❌ Failed to prepare statement in getUserState: " . $this->mysqli->error);
            return 'default';
        }

        $stmt->bind_param("i", $chatId);
        if (!$stmt->execute()) {
            error_log("❌ Failed to execute statement in getUserState: " . $stmt->error);
            $stmt->close();
            return 'default';
        }

        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result['task_input_status'] ?? 'default';
    }

    public function updateUserState($chatId, $state)
    {
        $stmt = $this->mysqli->prepare("UPDATE users SET task_input_status = ? WHERE chat_id = ?");
        if (!$stmt) {
            error_log("❌ Failed to prepare statement in updateUserState: " . $this->mysqli->error);
            return false;
        }

        $stmt->bind_param("si", $state, $chatId);
        if (!$stmt->execute()) {
            error_log("❌ Failed to execute statement in updateUserState: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        return $affectedRows > 0;
    }
    

}

?>




