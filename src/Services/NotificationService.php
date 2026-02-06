<?php
namespace App\Services;

use PDO;
use Exception;

class NotificationService {
    private $db;
    private $tgToken;
    private $tgChatId;

    public function __construct($db) {
        $this->db = $db;
        // Carrega tokens das variáveis de ambiente ou usa os padrões fornecidos
        $this->tgToken  = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '8235245222:AAE_BXQHBo_3CfzgcZUV8rmqM71hgvGScqc';
        $this->tgChatId = $_ENV['TELEGRAM_CHAT_ID'] ?? '8231475214';
    }

    /**
     * MÉTODO PRINCIPAL: Orquestra todas as notificações do sistema
     */
    public function send(int $userId, string $title, string $message, string $type = 'system', string $priority = 'medium', string $actionUrl = null, array $metadata = null): bool {
        
        // 1. Persistência no Banco de Dados (Sininho In-App) - CUSTO ZERO
        $dbSuccess = $this->notifyInDatabase($userId, $title, $message, $type, $priority, $actionUrl, $metadata);

        // 2. Alerta para o ADMIN via Telegram (Monitoramento em tempo real) - CUSTO ZERO
        if ($priority === 'high' || $type === 'match') {
            $this->sendTelegramAlert("<b>[NOTIFICAÇÃO]</b>\nTipo: {$type}\nPara User ID: {$userId}\n{$title}\n{$message}");
        }

        // 3. Push Notification (Via OneSignal plano Free, por exemplo) - CUSTO ZERO
        $this->sendPushNotification($userId, $title, $message, $actionUrl);

        return $dbSuccess;
    }

    private function notifyInDatabase($userId, $title, $message, $type, $priority, $actionUrl, $metadata): bool {
        try {
            $sql = "INSERT INTO notifications (user_id, title, message, type, priority, action_url, metadata) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                $userId, 
                $title, 
                $message, 
                $type, 
                $priority, 
                $actionUrl, 
                $metadata ? json_encode($metadata) : null
            ]);
        } catch (Exception $e) {
            error_log("Erro ao salvar notificação: " . $e->getMessage());
            return false;
        }
    }

    private function sendPushNotification($userId, $title, $message, $actionUrl) {
        try {
            // Usamos um bloco try/catch para que, se a coluna não existir, o sistema ignore e siga em frente
            $stmt = $this->db->prepare("SELECT push_token FROM user_profiles WHERE user_id = ? AND push_token IS NOT NULL");
            $stmt->execute([$userId]);
            $token = $stmt->fetchColumn();

            if ($token) {
                // Lógica de envio (OneSignal/Firebase)
                return true;
            }
        } catch (Exception $e) {
            // Logamos o erro mas não travamos a execução do sistema
            error_log("Push Notification ignorada: " . $e->getMessage());
        }
        return false;
    }

    public function getUnreadByUser(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? AND is_read = 0 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar notificações: " . $e->getMessage());
            return [];
        }
    }

    public function getUserNotifications($userId, $limit = 20) {
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getUnreadCount($userId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markAsRead($id, $userId) {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }

    public function markAllRead($userId) {
        $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }

    public function sendTelegramAlert(string $message): bool {
        $url = "https://api.telegram.org/bot{$this->tgToken}/sendMessage";
        $data = [
            'chat_id'    => $this->tgChatId,
            'parse_mode' => 'HTML',
            'text'       => $message
        ];
        return $this->executeRequest($url, $data, 'POST');
    }

    private function executeRequest(string $url, array $params, string $method = 'GET', array $headers = []): bool {
        $ch = curl_init();
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $headers[] = 'Content-Type: application/json';
        } else {
            $url .= '?' . http_build_query($params);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode >= 200 && $httpCode < 300;
    }

     /**
     * Remove notificações lidas e antigas para evitar inchaço do banco
     */
    public function cleanOldNotifications(int $days = 30): int {
        $stmt = $this->db->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    public function notifyCompanyQuote(int $companyId, string $quoteData) {
        // 1. Procura o telegram_chat_id da empresa no banco
        $stmt = $this->db->prepare("SELECT telegram_chat_id FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch();

        if ($company && $company['telegram_chat_id']) {
            $message = "📦 <b>Nova Cotação!</b>\n\n" . $quoteData;
            
            // Envia para o telegram da empresa (que depois pode disparar o Zap)
            $url = "https://api.telegram.org/bot{$this->tgToken}/sendMessage";
            $data = [
                'chat_id'    => $company['telegram_chat_id'],
                'parse_mode' => 'HTML',
                'text'       => $message
            ];
            return $this->executeRequest($url, $data, 'POST');
        }
        return false;
    }
}