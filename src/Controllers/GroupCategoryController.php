<?php
namespace App\Controllers;

use PDO;
use App\Core\Response;
use App\Core\Auth;
use App\Repositories\GroupCategoryRepository;

class GroupCategoryController
{
    private $db;
    private $repository;

    public function __construct($db)
    {
        $this->db = $db;
        $this->repository = new GroupCategoryRepository($db);
    }

    public function index()
    {
        $categories = $this->repository->findAll(true);
        
        return Response::json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function getAll()
    {
        $categories = $this->repository->findAll(false);
        
        return Response::json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function getActive()
    {
        $categories = $this->repository->findAll(true);
        
        return Response::json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function all()
    {
        $user = Auth::requireAuth();
        
        if (!in_array(strtoupper($user['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }
        
        $categories = $this->repository->findAll(false);
        
        return Response::json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function show($params)
    {
        $id = (int)($params['id'] ?? 0);
        
        if (!$id) {
            return Response::json(['success' => false, 'message' => 'ID não fornecido'], 400);
        }
        
        $category = $this->repository->findById($id);
        
        if (!$category) {
            return Response::json(['success' => false, 'message' => 'Categoria não encontrada'], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => $category
        ]);
    }

    public function create($data, $loggedUser)
    {
        $user = Auth::requireAuth();
        
        if (!in_array(strtoupper($user['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }
        
        if (empty($data['name'])) {
            return Response::json(['success' => false, 'message' => 'Nome é obrigatório'], 400);
        }
        
        try {
            $id = $this->repository->create($data);
            
            return Response::json([
                'success' => true,
                'message' => 'Categoria criada com sucesso',
                'data' => ['id' => $id]
            ], 201);
        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao criar categoria'], 500);
        }
    }

    public function update($params, $data, $loggedUser)
    {
        $user = Auth::requireAuth();
        
        if (!in_array(strtoupper($user['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }
        
        $id = (int)($params['id'] ?? 0);
        
        if (!$id) {
            return Response::json(['success' => false, 'message' => 'ID não fornecido'], 400);
        }
        
        $category = $this->repository->findById($id);
        
        if (!$category) {
            return Response::json(['success' => false, 'message' => 'Categoria não encontrada'], 404);
        }
        
        try {
            $this->repository->update($id, $data);
            
            return Response::json([
                'success' => true,
                'message' => 'Categoria atualizada com sucesso'
            ]);
        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar categoria'], 500);
        }
    }

    public function delete($params, $loggedUser)
    {
        $user = Auth::requireAuth();
        
        if (!in_array(strtoupper($user['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }
        
        $id = (int)($params['id'] ?? 0);
        
        if (!$id) {
            return Response::json(['success' => false, 'message' => 'ID não fornecido'], 400);
        }
        
        $category = $this->repository->findById($id);
        
        if (!$category) {
            return Response::json(['success' => false, 'message' => 'Categoria não encontrada'], 404);
        }
        
        try {
            $this->repository->delete($id);
            
            return Response::json([
                'success' => true,
                'message' => 'Categoria excluída com sucesso'
            ]);
        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao excluir categoria'], 500);
        }
    }

    public function toggle($params, $loggedUser)
    {
        $user = Auth::requireAuth();
        
        if (!in_array(strtoupper($user['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }
        
        $id = (int)($params['id'] ?? 0);
        
        if (!$id) {
            return Response::json(['success' => false, 'message' => 'ID não fornecido'], 400);
        }
        
        try {
            $this->repository->toggleActive($id);
            
            return Response::json([
                'success' => true,
                'message' => 'Status alterado com sucesso'
            ]);
        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao alterar status'], 500);
        }
    }

    public function reorder($data, $loggedUser)
    {
        $user = Auth::requireAuth();
        
        if (!in_array(strtoupper($user['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }
        
        if (empty($data['ids']) || !is_array($data['ids'])) {
            return Response::json(['success' => false, 'message' => 'IDs não fornecidos'], 400);
        }
        
        try {
            $this->repository->reorder($data['ids']);
            
            return Response::json([
                'success' => true,
                'message' => 'Categorias reordenadas com sucesso'
            ]);
        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao reordenar'], 500);
        }
    }
}
