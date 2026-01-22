<?php
class NotificationController {
    private $db;
    // Configure aqui o seu número de Admin (com código do país e DDD)
    private $adminWhatsApp = "5547992717125"; 

    public function __construct($db) { 
        $this->db = $db; 
    }

    public function handle($endpoint, $data, $loggedUser) {
        if (!$loggedUser) return ["success" => false, "message" => "Não autorizado"];
        $userId = $loggedUser['id'];

        switch ($endpoint) {
            case 'list-notifications':
                $stmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
                $stmt->execute([$userId]);
                return $stmt->fetchAll();

            case 'mark-as-read':
                $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
                return ["success" => $stmt->execute([$userId])];

            case 'unread-count':
                $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$userId]);
                return $stmt->fetch();

            default:
                return ["error" => "Endpoint inválido"];
        }
    }

    /**
     * Notificação interna do sistema (Banco de Dados)
     */
    public function notify($userId, $title, $message) {
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        return $stmt->execute([$userId, $title, $message]);
    }

    /**
     * NOVO: Notifica o Admin sobre um novo Lead de Anúncio via WhatsApp Externo
     */
    public function notifyNewLead($leadData) {
        $empresa = $leadData['title'] ?? 'Não informada';
        $contato = $leadData['contact_info'] ?? 'Sem número';
        $msgLead = $leadData['description'] ?? 'Sem mensagem';

        $textoZap = "🔔 *NOVO LEAD NO PORTAL*\n\n";
        $textoZap .= "*Empresa:* {$empresa}\n";
        $textoZap .= "*WhatsApp:* {$contato}\n";
        $textoZap .= "*Interesse:* {$msgLead}\n\n";
        $textoZap .= "👉 _Acesse o painel admin para gerenciar._";

        // 1. Opcional: Salva uma notificação para o usuário Admin no Banco (id 1 geralmente)
        $this->notify(1, "Novo Lead: $empresa", "Interesse em anúncio recebido.");

        // 2. Dispara o WhatsApp real via sua API/Gateway
        return $this->sendExternalWhatsApp($this->adminWhatsApp, $textoZap);
    }

    public function sendFreeAlert($message) {
        $botToken = "8235245222:AAE_BXQHBo_3CfzgcZUV8rmqM71hgvGScqc"; 
        $chatId = "8231475214"; 

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage?chat_id={$chatId}&parse_mode=HTML&text=" . urlencode($message);

        // Envio assíncrono básico para não travar o carregamento do usuário
        @file_get_contents($url);
        return true;
    }

    /**
     * NOVO: Integração com Gateway de WhatsApp
     * Substitua o conteúdo abaixo pela chamada da sua API (ex: Evolution API, Z-API, Evolution, etc)
     */
    private function sendExternalWhatsApp($to, $message) {
        // Exemplo Genérico de Integração via CURL
        // Se você já tem uma função de disparo em outro lugar, pode chamá-la aqui.
        try {
            /* $url = "SUA_API_AQUI";
            $data = ["number" => $to, "message" => $message];
            // ... lógica de CURL ...
            */
            return true; 
        } catch (Exception $e) {
            error_log("Erro ao enviar WhatsApp: " . $e->getMessage());
            return false;
        }
    }

}