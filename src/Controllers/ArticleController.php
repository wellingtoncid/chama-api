<?php
namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Repositories\ArticleRepository;
use App\Repositories\UserRepository;
use App\Repositories\ArticleAuthorRequestRepository;

class ArticleController {
    private $articleRepo;
    private $userRepo;

    public function __construct($db) {
        $this->articleRepo = new ArticleRepository($db);
        $this->userRepo = new UserRepository($db);
    }

    /**
     * GET /api/articles - List published articles
     */
    public function index($data) {
        $filters = [
            'category_id' => $data['category_id'] ?? null,
            'category_slug' => $data['category_slug'] ?? null,
            'featured' => isset($data['featured']),
            'is_paid' => isset($data['paid']),
            'order' => $data['order'] ?? null,
            'limit' => min((int)($data['limit'] ?? 20), 50),
            'offset' => (int)($data['offset'] ?? 0)
        ];

        $articles = $this->articleRepo->getAll($filters);
        $total = $this->articleRepo->count(['status' => 'published']);

        return Response::json([
            'success' => true,
            'data' => [
                'articles' => $articles,
                'total' => (int)$total,
                'limit' => $filters['limit'],
                'offset' => $filters['offset']
            ]
        ]);
    }

    /**
     * GET /api/articles/:slug - Get single article
     */
    public function show($data) {
        $slug = $data['slug'] ?? '';
        
        if (empty($slug)) {
            return Response::json(['success' => false, 'message' => 'Slug é obrigatório'], 400);
        }

        $article = $this->articleRepo->findBySlug($slug);

        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        // Increment views
        $this->articleRepo->incrementViews($article['id']);

        // Get related articles
        $related = [];
        if ($article['category_id']) {
            $related = $this->articleRepo->getRelated($article['id'], $article['category_id']);
        }

        return Response::json([
            'success' => true,
            'data' => [
                'article' => $article,
                'related' => $related
            ]
        ]);
    }

    /**
     * POST /api/articles - Create new article (author approved)
     */
    public function store($data) {
        $user = Auth::requireAuth();
        
        // Check if user is approved author
        $authorRequestRepo = new ArticleAuthorRequestRepository($this->articleRepo->getDb());
        $isApprovedAuthor = $authorRequestRepo->isApprovedAuthor($user['id']);
        
        if (!$isApprovedAuthor) {
            return Response::json([
                'success' => false, 
                'message' => 'Você precisa ser um autor aprovado para submeter artigos. <a href="/artigos/ser-autor" class="text-blue-600 underline">Solicitar acesso</a>'
            ], 403);
        }
        
        $required = ['title', 'content'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Response::json(['success' => false, 'message' => "{$field} é obrigatório"], 400);
            }
        }

        // Validate character limits
        if (strlen($data['content']) < 2000) {
            return Response::json(['success' => false, 'message' => 'Artigo deve ter no mínimo 2.000 caracteres'], 400);
        }

        if (strlen($data['content']) > 50000) {
            return Response::json(['success' => false, 'message' => 'Artigo deve ter no máximo 50.000 caracteres'], 400);
        }

        // Check for banned words
        $bannedWords = $this->articleRepo->checkBannedWords($data['content']);
        $hasHighSeverity = array_filter($bannedWords, fn($w) => $w['severity'] === 'high');
        
        if (!empty($hasHighSeverity)) {
            return Response::json([
                'success' => false, 
                'message' => 'Seu artigo contém palavras não permitidas e não pode ser submetido'
            ], 400);
        }

        // Generate slug
        $slug = $this->generateSlug($data['title']);
        $counter = 1;
        $originalSlug = $slug;
        
        while ($this->articleRepo->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        // Check if it's a paid article (publieditorial)
        $isPaid = !empty($data['is_paid']);
        $paidPlan = $data['paid_plan'] ?? null;
        $paidUntil = null;
        
        if ($isPaid && $paidPlan) {
            $duration = ($paidPlan === 'premium') ? 60 : 30;
            $paidUntil = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
        }

        $articleId = $this->articleRepo->create([
            'title' => $data['title'],
            'slug' => $slug,
            'excerpt' => $data['excerpt'] ?? null,
            'content' => $data['content'],
            'author_id' => $user['id'],
            'category_id' => $data['category_id'] ?? null,
            'featured' => false,
            'is_paid' => $isPaid,
            'paid_plan' => $paidPlan,
            'paid_until' => $paidUntil,
            'paid_banner_image' => $data['paid_banner_image'] ?? null,
            'paid_banner_url' => $data['paid_banner_url'] ?? null,
            'status' => 'pending'
        ]);

        return Response::json([
            'success' => true,
            'message' => 'Artigo submetido com sucesso! Aguarde a aprovação da equipe.',
            'data' => ['article_id' => $articleId]
        ], 201);
    }

    /**
     * PUT /api/articles/:id - Update article
     */
    public function update($data) {
        $user = Auth::requireAuth();
        $id = (int)$data['id'];

        $article = $this->articleRepo->findById($id);
        
        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        // Check ownership (or admin)
        if ($article['author_id'] != $user['id'] && !Auth::hasRole('admin')) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Check rejection limit
        if ($article['rejection_count'] >= 3) {
            return Response::json([
                'success' => false, 
                'message' => 'Limite de reenvios atingido. Entre em contato com o suporte.'
            ], 400);
        }

        // Validate if updating content
        if (!empty($data['content'])) {
            if (strlen($data['content']) < 2000) {
                return Response::json(['success' => false, 'message' => 'Artigo deve ter no mínimo 2.000 caracteres'], 400);
            }
            if (strlen($data['content']) > 50000) {
                return Response::json(['success' => false, 'message' => 'Artigo deve ter no máximo 50.000 caracteres'], 400);
            }

            // Check banned words again
            $bannedWords = $this->articleRepo->checkBannedWords($data['content']);
            $hasHighSeverity = array_filter($bannedWords, fn($w) => $w['severity'] === 'high');
            
            if (!empty($hasHighSeverity)) {
                return Response::json([
                    'success' => false, 
                    'message' => 'Seu artigo contém palavras não permitidas'
                ], 400);
            }
        }

        // Update slug if title changed
        $slug = $article['slug'];
        if (!empty($data['title']) && $data['title'] != $article['title']) {
            $newSlug = $this->generateSlug($data['title']);
            if ($this->articleRepo->slugExists($newSlug, $id)) {
                return Response::json(['success' => false, 'message' => 'Título muito similar a outro artigo existente'], 400);
            }
            $slug = $newSlug;
        }

        $updateData = [
            'title' => $data['title'] ?? $article['title'],
            'slug' => $slug,
            'excerpt' => $data['excerpt'] ?? $article['excerpt'],
            'content' => $data['content'] ?? $article['content'],
            'category_id' => $data['category_id'] ?? $article['category_id']
        ];

        // If was rejected, reset to pending
        if ($article['status'] === 'rejected') {
            $updateData['status'] = 'pending';
        }

        $this->articleRepo->update($id, $updateData);

        return Response::json([
            'success' => true,
            'message' => 'Artigo atualizado com sucesso!'
        ]);
    }

    /**
     * PUT /api/articles/:id/approve - Admin approves article
     */
    public function approve($data) {
        Auth::requireRole('admin');
        
        $id = (int)$data['id'];
        
        $article = $this->articleRepo->findById($id);
        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        $this->articleRepo->publish($id);

        return Response::json([
            'success' => true,
            'message' => 'Artigo publicado com sucesso!'
        ]);
    }

    /**
     * PUT /api/articles/:id/reject - Admin rejects article
     */
    public function reject($data) {
        Auth::requireRole('admin');
        
        $id = (int)$data['id'];
        $reason = $data['reason'] ?? 'Artigo não atender aos critérios de publicação';
        
        $article = $this->articleRepo->findById($id);
        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        $this->articleRepo->reject($id, $reason);

        return Response::json([
            'success' => true,
            'message' => 'Artigo rejeitado'
        ]);
    }

    /**
     * DELETE /api/articles/:id - Delete article
     */
    public function destroy($data) {
        $user = Auth::requireAuth();
        $id = (int)$data['id'];

        $article = $this->articleRepo->findById($id);
        
        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        // Check ownership (or admin)
        if ($article['author_id'] != $user['id'] && !Auth::hasRole('admin')) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $this->articleRepo->delete($id);

        return Response::json([
            'success' => true,
            'message' => 'Artigo deletado'
        ]);
    }

    /**
     * GET /api/articles/:id/stats - Get article stats
     */
    public function stats($data) {
        Auth::requireRole('admin');
        
        $id = (int)$data['id'];
        
        $article = $this->articleRepo->findById($id);
        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        return Response::json([
            'success' => true,
            'data' => [
                'views' => (int)$article['views_count'],
                'clicks' => (int)$article['clicks_count'],
                'ctr' => $article['views_count'] > 0 
                    ? round(($article['clicks_count'] / $article['views_count']) * 100, 2) 
                    : 0
            ]
        ]);
    }

    /**
     * GET /api/articles/admin/all - Get all articles for admin
     */
    public function getAllAdmin($data) {
        Auth::requireRole('admin');
        
        $filters = [
            'status' => $data['status'] ?? null,
            'limit' => min((int)($data['limit'] ?? 50), 100),
            'offset' => (int)($data['offset'] ?? 0)
        ];

        $articles = $this->articleRepo->getAllForAdmin($filters);
        $stats = $this->articleRepo->getStats();

        return Response::json([
            'success' => true,
            'data' => [
                'articles' => $articles,
                'stats' => [
                    'total' => (int)$stats['total'],
                    'pending' => (int)$stats['pending'],
                    'published' => (int)$stats['published'],
                    'rejected' => (int)$stats['rejected']
                ]
            ]
        ]);
    }

    /**
     * GET /api/articles/admin/pending - Get pending articles for admin
     */
    public function getPending($data) {
        Auth::requireRole('admin');
        
        $limit = min((int)($data['limit'] ?? 20), 50);
        $offset = (int)($data['offset'] ?? 0);

        $articles = $this->articleRepo->getPending($limit, $offset);
        $total = $this->articleRepo->count(['status' => 'pending']);

        return Response::json([
            'success' => true,
            'data' => [
                'articles' => $articles,
                'total' => (int)$total
            ]
        ]);
    }

    /**
     * GET /api/articles/me - Get my articles
     */
    public function myArticles($data) {
        $user = Auth::requireAuth();
        
        $status = $data['status'] ?? null;
        $articles = $this->articleRepo->getByAuthor($user['id'], $status);

        return Response::json([
            'success' => true,
            'data' => ['articles' => $articles]
        ]);
    }

    /**
     * Helper: Generate slug from title
     */
    private function generateSlug($title) {
        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $title);
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return $slug;
    }
}