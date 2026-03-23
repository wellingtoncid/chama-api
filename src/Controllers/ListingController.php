<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ListingRepository;

class ListingController {
    private $repository;

    public function __construct($db) {
        $this->repository = new ListingRepository($db);
    }

    public function create($data) {
        // Validação básica
        if (empty($data['title']) || empty($data['user_id'])) {
            return Response::json(["success" => false, "message" => "Dados insuficientes"], 400);
        }

        $data['slug'] = $this->generateSlug($data['title']);
        $data['main_image'] = $data['images'][0] ?? null;
        
        $listingId = $this->repository->save($data);
        
        if (!$listingId) {
            return Response::json(["success" => false, "message" => "Erro ao salvar anúncio"], 500);
        }
        
        if (!empty($data['images'])) {
            foreach ($data['images'] as $index => $url) {
                $this->repository->addImage($listingId, $url, $index);
            }
        }

        return Response::json(["success" => true, "id" => $listingId, "slug" => $data['slug']]);
    }

    public function getAll($data) {
        $page = $data['page'] ?? 1;
        $filters = [
            'category' => $data['category'] ?? null,
            'search' => $data['search'] ?? null
        ];

        $listings = $this->repository->findActiveWithFilters($filters, $page);
        
        // OTIMIZAÇÃO: Busca imagens de todos os anúncios em uma única query
        if (!empty($listings['items'])) {
            $ids = array_column($listings['items'], 'id');
            $allImages = $this->repository->getImagesForList($ids);
            
            foreach ($listings['items'] as &$item) {
                $item['images'] = $allImages[$item['id']] ?? [];
            }
        }
        
        return Response::json(["success" => true, "data" => $listings]);
    }

    public function getMyListings($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Unauthorized"], 401);
        }
        
        $userId = $loggedUser['id'];
        $listings = $this->repository->findByUser($userId);
        
        return Response::json(["success" => true, "data" => $listings]);
    }

    public function getDetail($data) {
        $id = $data['id'] ?? 0;
        $item = $this->repository->findById($id);
        
        if (!$item) {
            return Response::json(["success" => false, "message" => "Anúncio não encontrado"], 404);
        }
        
        $item['gallery'] = $this->repository->getImages($id);
        return Response::json(["success" => true, "data" => $item]);
    }

    private function generateSlug($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        return strtolower($text) . '-' . rand(100, 999);
    }

    // ==================== ADMIN METHODS ====================

    public function adminList($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'SUPPORT'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $filters = [
            'status' => $data['status'] ?? null,
            'category' => $data['category'] ?? null,
            'search' => $data['search'] ?? null
        ];

        $listings = $this->repository->findAll($filters);

        if (!empty($listings)) {
            $ids = array_column($listings, 'id');
            $allImages = $this->repository->getImagesForList($ids);
            
            foreach ($listings as &$item) {
                $item['images'] = $allImages[$item['id']] ?? [];
            }
        }

        return Response::json([
            'success' => true,
            'data' => $listings
        ]);
    }

    public function adminGet($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'SUPPORT'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $item = $this->repository->findByIdAdmin($id);
        if (!$item) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        $item['gallery'] = $this->repository->getImages($id);

        return Response::json([
            'success' => true,
            'data' => $item
        ]);
    }

    public function adminCreate($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        if (empty($data['title']) || empty($data['user_id'])) {
            return Response::json(['success' => false, 'message' => 'Dados insuficientes'], 400);
        }

        $data['slug'] = $this->generateSlug($data['title']);
        $data['main_image'] = $data['images'][0] ?? null;
        
        $listingId = $this->repository->save($data);
        
        if (!$listingId) {
            return Response::json(['success' => false, 'message' => 'Erro ao salvar anúncio'], 500);
        }
        
        if (!empty($data['images'])) {
            foreach ($data['images'] as $index => $url) {
                $this->repository->addImage($listingId, $url, $index);
            }
        }

        return Response::json([
            'success' => true, 
            'id' => $listingId, 
            'slug' => $data['slug'],
            'message' => 'Anúncio criado com sucesso'
        ], 201);
    }

    public function adminUpdate($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $existing = $this->repository->findByIdAdmin($id);
        if (!$existing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        try {
            $this->repository->update($id, $data);
            return Response::json(['success' => true, 'message' => 'Anúncio atualizado']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar'], 500);
        }
    }

    public function adminDelete($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $existing = $this->repository->findByIdAdmin($id);
        if (!$existing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        try {
            $this->repository->delete($id);
            return Response::json(['success' => true, 'message' => 'Anúncio excluído']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }
}