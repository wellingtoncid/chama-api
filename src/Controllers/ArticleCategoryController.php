<?php
namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Repositories\ArticleCategoryRepository;

class ArticleCategoryController {
    private $categoryRepo;

    public function __construct($db) {
        $this->categoryRepo = new ArticleCategoryRepository($db);
    }

    /**
     * GET /api/article-categories - Get all categories
     */
    public function getAll($data) {
        $categories = $this->categoryRepo->getAll();

        return Response::json([
            'success' => true,
            'data' => ['categories' => $categories]
        ]);
    }

    /**
     * GET /api/article-categories/active - Get active categories
     */
    public function getActive($data) {
        $categories = $this->categoryRepo->getActive();

        return Response::json([
            'success' => true,
            'data' => ['categories' => $categories]
        ]);
    }

    /**
     * GET /api/admin/article-categories/:id - Get single category (admin)
     */
    public function get($data) {
        Auth::requireRole('admin');
        
        $id = (int)$data['id'];
        $category = $this->categoryRepo->findById($id);

        if (!$category) {
            return Response::json(['success' => false, 'message' => 'Categoria não encontrada'], 404);
        }

        return Response::json([
            'success' => true,
            'data' => ['category' => $category]
        ]);
    }

    /**
     * POST /api/admin/article-categories - Create category (admin)
     */
    public function create($data) {
        Auth::requireRole('admin');

        $required = ['name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Response::json(['success' => false, 'message' => "{$field} é obrigatório"], 400);
            }
        }

        // Generate slug
        $slug = $this->generateSlug($data['name']);
        if ($this->categoryRepo->slugExists($slug)) {
            return Response::json(['success' => false, 'message' => 'Já existe uma categoria com este nome'], 400);
        }

        $categoryId = $this->categoryRepo->create([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? '#1f4ead'
        ]);

        return Response::json([
            'success' => true,
            'message' => 'Categoria criada com sucesso!',
            'data' => ['category_id' => $categoryId]
        ], 201);
    }

    /**
     * PUT /api/admin/article-categories/:id - Update category (admin)
     */
    public function update($data) {
        Auth::requireRole('admin');

        $id = (int)$data['id'];
        $category = $this->categoryRepo->findById($id);

        if (!$category) {
            return Response::json(['success' => false, 'message' => 'Categoria não encontrada'], 404);
        }

        $updateData = [];
        
        if (!empty($data['name'])) {
            $updateData['name'] = $data['name'];
            // Update slug if name changed
            $newSlug = $this->generateSlug($data['name']);
            if (!$this->categoryRepo->slugExists($newSlug, $id)) {
                $updateData['slug'] = $newSlug;
            }
        }
        
        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }
        
        if (isset($data['color'])) {
            $updateData['color'] = $data['color'];
        }

        $this->categoryRepo->update($id, $updateData);

        return Response::json([
            'success' => true,
            'message' => 'Categoria atualizada com sucesso!'
        ]);
    }

    /**
     * DELETE /api/admin/article-categories/:id - Delete category (admin)
     */
    public function delete($data) {
        Auth::requireRole('admin');

        $id = (int)$data['id'];
        $category = $this->categoryRepo->findById($id);

        if (!$category) {
            return Response::json(['success' => false, 'message' => 'Categoria não encontrada'], 404);
        }

        $this->categoryRepo->delete($id);

        return Response::json([
            'success' => true,
            'message' => 'Categoria deletada com sucesso!'
        ]);
    }

    /**
     * Helper: Generate slug
     */
    private function generateSlug($name) {
        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $name);
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return $slug;
    }
}