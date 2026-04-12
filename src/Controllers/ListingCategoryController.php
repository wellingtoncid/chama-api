<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ListingCategoryRepository;

class ListingCategoryController {
    private $repository;

    public function __construct($db) {
        $this->repository = new ListingCategoryRepository($db);
    }

    public function getAll($data) {
        $activeOnly = !isset($data['include_inactive']) || $data['include_inactive'] != 'true';
        $categories = $this->repository->findAll($activeOnly);
        return Response::json(["success" => true, "data" => $categories]);
    }

    public function get($data, $loggedUser, $id = null) {
        $categoryId = $id ?? ($data['id'] ?? null);
        
        if (!$categoryId) {
            return Response::json(["success" => false, "message" => "ID não informado"], 400);
        }

        $category = $this->repository->findById($categoryId);
        
        if (!$category) {
            return Response::json(["success" => false, "message" => "Categoria não encontrada"], 404);
        }

        return Response::json(["success" => true, "data" => $category]);
    }

    public function create($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(["success" => false, "message" => "Acesso restrito"], 403);
        }

        if (empty($data['name'])) {
            return Response::json(["success" => false, "message" => "Nome é obrigatório"], 400);
        }

        // Verificar se slug já existe
        $slug = $data['slug'] ?? null;
        if ($slug) {
            $existing = $this->repository->findBySlug($slug);
            if ($existing) {
                return Response::json(["success" => false, "message" => "Slug já existe"], 409);
            }
        }

        try {
            $id = $this->repository->create($data);
            return Response::json([
                "success" => true,
                "id" => $id,
                "message" => "Categoria criada com sucesso"
            ], 201);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao criar categoria"], 500);
        }
    }

    public function update($data, $loggedUser, $id = null) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(["success" => false, "message" => "Acesso restrito"], 403);
        }

        $categoryId = $id ?? ($data['id'] ?? null);
        
        if (!$categoryId) {
            return Response::json(["success" => false, "message" => "ID não informado"], 400);
        }

        $existing = $this->repository->findById($categoryId);
        if (!$existing) {
            return Response::json(["success" => false, "message" => "Categoria não encontrada"], 404);
        }

        // Verificar slug único se estiver sendo alterado
        if (!empty($data['slug']) && $data['slug'] !== $existing['slug']) {
            $slugExists = $this->repository->findBySlug($data['slug']);
            if ($slugExists) {
                return Response::json(["success" => false, "message" => "Slug já existe"], 409);
            }
        }

        try {
            $this->repository->update($categoryId, $data);
            return Response::json(["success" => true, "message" => "Categoria atualizada"]);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao atualizar"], 500);
        }
    }

    public function delete($data, $loggedUser, $id = null) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(["success" => false, "message" => "Acesso restrito"], 403);
        }

        $categoryId = $id ?? ($data['id'] ?? null);
        
        if (!$categoryId) {
            return Response::json(["success" => false, "message" => "ID não informado"], 400);
        }

        $existing = $this->repository->findById($categoryId);
        if (!$existing) {
            return Response::json(["success" => false, "message" => "Categoria não encontrada"], 404);
        }

        try {
            $this->repository->delete($categoryId);
            return Response::json(["success" => true, "message" => "Categoria excluída"]);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao excluir"], 500);
        }
    }

    public function toggleActive($data, $loggedUser, $id = null) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(["success" => false, "message" => "Acesso restrito"], 403);
        }

        $categoryId = $id ?? ($data['id'] ?? null);
        
        if (!$categoryId) {
            return Response::json(["success" => false, "message" => "ID não informado"], 400);
        }

        try {
            $this->repository->toggleActive($categoryId);
            return Response::json(["success" => true, "message" => "Status alterado"]);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao alterar status"], 500);
        }
    }
}
