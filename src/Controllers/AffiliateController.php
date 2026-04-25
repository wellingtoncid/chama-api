<?php
namespace App\Controllers;

use PDO;
use App\Core\Response;
use App\Services\MercadoLivreScraperService;

class AffiliateController
{
    private $db;
    private $scraper;

    public function __construct($db)
    {
        $this->db = $db;
        $this->scraper = new MercadoLivreScraperService();
    }

    public function scrapeProduct($data)
    {
        if (empty($data['url'])) {
            return Response::json([
                'success' => false,
                'message' => 'URL do produto é obrigatória.'
            ], 400);
        }

        $result = $this->scraper->scrapeProduct($data['url']);
        
        if (!$result['success']) {
            return Response::json($result, 400);
        }

        return Response::json([
            'success' => true,
            'data' => $result['data']
        ]);
    }

    public function generateAffiliateUrl($data)
    {
        if (empty($data['url'])) {
            return Response::json([
                'success' => false,
                'message' => 'URL do produto é obrigatória.'
            ], 400);
        }

        $affiliateUrl = $this->scraper->generateAffiliateUrl($data['url']);

        return Response::json([
            'success' => true,
            'data' => [
                'original_url' => $data['url'],
                'affiliate_url' => $affiliateUrl
            ]
        ]);
    }

    public function redirect($params)
    {
        $listingId = (int)($params['id'] ?? 0);

        if (!$listingId) {
            return Response::json([
                'success' => false,
                'message' => 'ID do anúncio não encontrado.'
            ], 400);
        }

        $stmt = $this->db->prepare("
            SELECT l.*, u.has_affiliate_access 
            FROM listings l 
            JOIN users u ON l.user_id = u.id 
            WHERE l.id = ? AND l.is_affiliate = 1 AND l.status = 'active'
        ");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing || empty($listing['external_url'])) {
            return Response::json([
                'success' => false,
                'message' => 'Anúncio não encontrado ou link não disponível.'
            ], 404);
        }

        $affiliateUrl = $this->scraper->generateAffiliateUrl($listing['external_url']);

        $updateStmt = $this->db->prepare("
            UPDATE listings SET clicks_count = clicks_count + 1 WHERE id = ?
        ");
        $updateStmt->execute([$listingId]);

        return Response::json([
            'success' => true,
            'redirect_url' => $affiliateUrl
        ]);
    }

    public function submitInterest($data, $loggedUser)
    {
        if (!$loggedUser) {
            return Response::json([
                'success' => false,
                'message' => 'Você precisa estar logado.'
            ], 401);
        }

        $userId = (int)$loggedUser['id'];

        $stmt = $this->db->prepare("SELECT has_affiliate_access FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['has_affiliate_access']) {
            return Response::json([
                'success' => false,
                'message' => 'Você já possui acesso ao recurso de afiliados.'
            ], 400);
        }

        $checkStmt = $this->db->prepare("SELECT id FROM affiliate_interests WHERE user_id = ? AND status = 'pending'");
        $checkStmt->execute([$userId]);
        if ($checkStmt->fetch()) {
            return Response::json([
                'success' => false,
                'message' => 'Você já possui uma solicitação pendente.'
            ], 400);
        }

        $sql = "INSERT INTO affiliate_interests (user_id, intended_use, willing_to_pay) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        $intendedUse = trim($data['intended_use'] ?? '');
        $willingToPay = (float)($data['willing_to_pay'] ?? 0);

        $stmt->execute([$userId, $intendedUse, $willingToPay]);

        return Response::json([
            'success' => true,
            'message' => 'Sua solicitação foi enviada com sucesso! Nossa equipe entrará em contato.',
            'data' => [
                'id' => $this->db->lastInsertId()
            ]
        ], 201);
    }

    public function getMyInterest($loggedUser)
    {
        if (!$loggedUser) {
            return Response::json([
                'success' => false,
                'message' => 'Você precisa estar logado.'
            ], 401);
        }

        $userId = (int)$loggedUser['id'];

        $stmt = $this->db->prepare("
            SELECT * FROM affiliate_interests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $interest = $stmt->fetch(PDO::FETCH_ASSOC);

        return Response::json([
            'success' => true,
            'data' => $interest
        ]);
    }

    public function hasAffiliateAccess($loggedUser)
    {
        if (!$loggedUser) {
            return Response::json([
                'success' => false,
                'message' => 'Você precisa estar logado.'
            ], 401);
        }

        $userId = (int)$loggedUser['id'];

        $stmt = $this->db->prepare("SELECT has_affiliate_access FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return Response::json([
            'success' => true,
            'data' => [
                'has_access' => (bool)($user['has_affiliate_access'] ?? false)
            ]
        ]);
    }

    public function checkAccess($loggedUser)
    {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json([
                'success' => true,
                'has_access' => false,
                'message' => 'Usuário não logado'
            ]);
        }

        $userId = (int)$loggedUser['id'];

        $stmt = $this->db->prepare("SELECT has_affiliate_access FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return Response::json([
            'success' => true,
            'has_access' => (bool)($user['has_affiliate_access'] ?? false)
        ]);
    }
}
