<?php

namespace App\Services;

class OpenWAService
{
    private string $apiUrl;
    private string $apiKey;
    private string $sessionId;

    public function __construct()
    {
        $this->apiUrl = rtrim($_ENV['OPENWA_API_URL'] ?? getenv('OPENWA_API_URL') ?: 'http://127.0.0.1:2785', '/');
        $this->apiKey = $_ENV['OPENWA_API_KEY'] ?? getenv('OPENWA_API_KEY') ?: '';
        $this->sessionId = $_ENV['OPENWA_SESSION_ID'] ?? getenv('OPENWA_SESSION_ID') ?: 'default';
    }

    private function request(string $method, string $path, array $body = []): ?array
    {
        $url = $this->apiUrl . $path;
        $headers = ['Content-Type: application/json'];
        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("OpenWA cURL error: {$error}");
            return null;
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && $data !== null) {
            return $data;
        }

        error_log("OpenWA API error [{$httpCode}]: " . substr($response, 0, 500));
        return null;
    }

    public function sendMessage(string $groupId, string $message): bool
    {
        $result = $this->request('POST', "/api/sessions/{$this->sessionId}/messages/send-text", [
            'chatId' => $groupId,
            'text' => $message,
        ]);
        return $result !== null;
    }

    public function sendWithDelay(string $groupId, string $message, int $delayMs = 4000): bool
    {
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
        return $this->sendMessage($groupId, $message);
    }

    public function listGroups(): ?array
    {
        return $this->request('GET', "/api/sessions/{$this->sessionId}/groups");
    }

    public function getSessions(): ?array
    {
        return $this->request('GET', '/api/sessions');
    }

    public function sessionStatus(): ?array
    {
        return $this->request('GET', "/api/sessions/{$this->sessionId}");
    }

    public function createSession(): ?array
    {
        return $this->request('POST', '/api/sessions/add', [
            'sessionId' => $this->sessionId,
        ]);
    }

    public function getQrCode(): ?array
    {
        return $this->request('GET', "/api/sessions/{$this->sessionId}/qr");
    }

    public function isConnected(): bool
    {
        $status = $this->sessionStatus();
        $s = $status['status'] ?? '';
        return $s === 'connected' || $s === 'ready';
    }

    public function health(): ?array
    {
        return $this->request('GET', '/api/health/ready');
    }
}
