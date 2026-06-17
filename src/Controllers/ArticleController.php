<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\ArticleAuthorRequestRepository;
use App\Repositories\ArticleRepository;
use App\Repositories\UserRepository;

class ArticleController
{
    private $articleRepo;
    private $userRepo;

    public function __construct($db)
    {
        $this->articleRepo = new ArticleRepository($db);
        $this->userRepo = new UserRepository($db);
    }

    /**
     * GET /api/articles/home - Homepage data (most_read, latest, colunistas)
     */
    public function home()
    {
        $mostRead = $this->articleRepo->getMostRead(5);
        $latest = $this->articleRepo->getLatest(5);
        $colunistas = $this->articleRepo->getColunistas(5);

        return Response::json([
            'success' => true,
            'data' => [
                'most_read' => $mostRead,
                'latest' => $latest,
                'colunistas' => $colunistas,
            ],
        ]);
    }

    /**
     * GET /api/articles - List published articles
     */
    public function index($data)
    {
        $filters = [
            'category_id' => $data['category_id'] ?? null,
            'category_slug' => $data['category_slug'] ?? null,
            'featured' => isset($data['featured']),
            'is_paid' => isset($data['paid']),
            'order' => $data['order'] ?? null,
            'limit' => min((int)($data['limit'] ?? 20), 50),
            'offset' => (int)($data['offset'] ?? 0),
        ];

        $articles = $this->articleRepo->getAll($filters);
        $total = $this->articleRepo->count(['status' => 'published']);

        return Response::json([
            'success' => true,
            'data' => [
                'articles' => $articles,
                'total' => (int)$total,
                'limit' => $filters['limit'],
                'offset' => $filters['offset'],
            ],
        ]);
    }

    /**
     * GET /api/articles/by-id/:id - Get article by ID (for editing, author or admin only)
     */
    public function showById($data)
    {
        $user = Auth::requireAuth();
        $id = (int)($data['id'] ?? 0);

        if ($id <= 0) {
            return Response::json(['success' => false, 'message' => 'ID inválido'], 400);
        }

        $article = $this->articleRepo->findById($id);

        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        if ($article['author_id'] != $user['id'] && !Auth::hasRole('admin')) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Don't expose sensitive fields
        unset($article['rejection_reason'], $article['rejection_count']);

        return Response::json([
            'success' => true,
            'data' => ['article' => $article],
        ]);
    }

    /**
     * GET /api/articles/:slug - Show article by slug (public)
     */
    public function show($data)
    {
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

        // Get popular articles
        $popular = $this->articleRepo->getAll(['order' => 'popular', 'limit' => 5]);

        // Get author's other articles
        $authorArticles = $this->articleRepo->getPublishedByAuthor($article['author_id'], 5);
        $authorArticles = array_values(array_filter($authorArticles, fn($a) => $a['id'] != $article['id']));

        return Response::json([
            'success' => true,
            'data' => [
                'article' => $article,
                'related' => $related,
                'popular' => $popular,
                'author_articles' => $authorArticles,
            ],
        ]);
    }

    /**
     * POST /api/articles - Create new article (author approved)
     */
    public function store($data)
    {
        $user = Auth::requireAuth();

        // Check if user is approved author
        $authorRequestRepo = new ArticleAuthorRequestRepository($this->articleRepo->getDb());
        $isApprovedAuthor = $authorRequestRepo->isApprovedAuthor($user['id']);

        if (!$isApprovedAuthor) {
            return Response::json([
                'success' => false,
                'message' => 'Você precisa ser um autor aprovado para submeter artigos. <a href="/artigos/ser-autor" class="text-blue-600 underline">Solicitar acesso</a>',
            ], 403);
        }

        // Check plagiarism strikes
        $stmt = $this->articleRepo->getDb()->prepare("SELECT plagiarism_strikes FROM users WHERE id = :id");
        $stmt->execute([':id' => $user['id']]);
        $plagiarismStrikes = (int)$stmt->fetchColumn();
        if ($plagiarismStrikes >= 3) {
            return Response::json([
                'success' => false,
                'message' => 'Seu acesso de publicação foi bloqueado devido a reincidências de plágio. Entre em contato com o suporte.',
            ], 403);
        }

        $required = ['title', 'content'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Response::json(['success' => false, 'message' => "{$field} é obrigatório"], 400);
            }
        }

        // is_ai_generated must be explicitly provided (true or false)
        if (!isset($data['is_ai_generated'])) {
            return Response::json(['success' => false, 'message' => 'Informe se o artigo foi gerado com auxílio de inteligência artificial'], 400);
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
        $hasHighSeverity = array_filter($bannedWords, fn ($w) => $w['severity'] === 'high');

        if (!empty($hasHighSeverity)) {
            return Response::json([
                'success' => false,
                'message' => 'Seu artigo contém palavras não permitidas e não pode ser submetido',
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

        // Check if it's a paid article (publieditorial) — only set after payment
        $isPaid = false;
        $paidPlan = null;
        $paidUntil = null;

        $articleId = $this->articleRepo->create([
            'title' => $data['title'],
            'slug' => $slug,
            'excerpt' => $data['excerpt'] ?? null,
            'content' => $data['content'],
            'image_url' => $data['image_url'] ?? null,
            'author_id' => $user['id'],
            'category_id' => $data['category_id'] ?? null,
            'tags' => $data['tags'] ?? null,
            'featured' => false,
            'is_paid' => $isPaid,
            'is_ai_generated' => !empty($data['is_ai_generated']),
            'paid_plan' => $paidPlan,
            'paid_until' => $paidUntil,
            'paid_banner_image' => $data['paid_banner_image'] ?? null,
            'paid_banner_url' => $data['paid_banner_url'] ?? null,
            'status' => 'pending',
        ]);

        return Response::json([
            'success' => true,
            'message' => 'Artigo submetido com sucesso! Aguarde a aprovação da equipe.',
            'data' => ['article_id' => $articleId],
        ], 201);
    }

    /**
     * PUT /api/articles/:id - Update article
     */
    public function update($data)
    {
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
                'message' => 'Limite de reenvios atingido. Entre em contato com o suporte.',
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
            $hasHighSeverity = array_filter($bannedWords, fn ($w) => $w['severity'] === 'high');

            if (!empty($hasHighSeverity)) {
                return Response::json([
                    'success' => false,
                    'message' => 'Seu artigo contém palavras não permitidas',
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
            'image_url' => $data['image_url'] ?? $article['image_url'],
            'category_id' => $data['category_id'] ?? $article['category_id'],
            'tags' => $data['tags'] ?? null,
            'is_ai_generated' => isset($data['is_ai_generated']) ? ($data['is_ai_generated'] ? 1 : 0) : $article['is_ai_generated'],
        ];

        // Featured (admin only)
        if (isset($data['featured']) && Auth::hasRole('admin')) {
            $updateData['featured'] = $data['featured'] ? 1 : 0;
            $updateData['featured_at'] = $data['featured'] ? date('Y-m-d H:i:s') : null;
        }

        // Paid article (publieditorial)
        $isPaid = !empty($data['is_paid']);
        $paidPlan = $data['paid_plan'] ?? $article['paid_plan'];
        $updateData['is_paid'] = $isPaid;
        $updateData['paid_plan'] = $isPaid ? $paidPlan : null;

        if ($isPaid && $paidPlan) {
            $duration = ($paidPlan === 'premium') ? 60 : 30;
            if (empty($article['paid_until']) || $article['paid_until'] < date('Y-m-d H:i:s')) {
                $updateData['paid_until'] = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
            }
        } else {
            $updateData['paid_until'] = null;
        }

        $updateData['paid_banner_image'] = $data['paid_banner_image'] ?? $article['paid_banner_image'];
        $updateData['paid_banner_url'] = $data['paid_banner_url'] ?? $article['paid_banner_url'];

        // If not admin, any edit resets to pending for re-approval
        if (!Auth::hasRole('admin') && $article['status'] !== 'draft') {
            $updateData['status'] = 'pending';
        }

        $result = $this->articleRepo->update($id, $updateData);
        if (!$result) {
            error_log('ArticleController::update - falhou ao atualizar artigo ID: ' . $id . ' data: ' . json_encode($updateData));
            return Response::json([
                'success' => false,
                'message' => 'Erro ao salvar alterações no banco',
            ], 500);
        }

        return Response::json([
            'success' => true,
            'message' => 'Artigo atualizado com sucesso!',
        ]);
    }

    /**
     * PUT /api/articles/:id/approve - Admin approves article
     */
    public function approve($data)
    {
        Auth::requireRole('admin');

        $id = (int)$data['id'];

        $article = $this->articleRepo->findById($id);
        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        $this->articleRepo->publish($id);

        return Response::json([
            'success' => true,
            'message' => 'Artigo publicado com sucesso!',
        ]);
    }

    /**
     * PUT /api/articles/:id/reject - Admin rejects article
     */
    public function reject($data)
    {
        Auth::requireRole('admin');

        $id = (int)$data['id'];
        $reason = $data['reason'] ?? 'Artigo não atender aos critérios de publicação';
        $rejectionType = $data['rejection_type'] ?? 'other';

        $article = $this->articleRepo->findById($id);
        if (!$article) {
            return Response::json(['success' => false, 'message' => 'Artigo não encontrado'], 404);
        }

        $this->articleRepo->reject($id, $reason);

        // If plagiarism, increment strikes on author
        if ($rejectionType === 'plagiarism') {
            $stmt = $this->articleRepo->getDb()->prepare("UPDATE users SET plagiarism_strikes = plagiarism_strikes + 1 WHERE id = :id");
            $stmt->execute([':id' => $article['author_id']]);
        }

        return Response::json([
            'success' => true,
            'message' => 'Artigo rejeitado',
        ]);
    }

    /**
     * DELETE /api/articles/:id - Delete article
     */
    public function destroy($data)
    {
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
            'message' => 'Artigo deletado',
        ]);
    }

    /**
     * GET /api/articles/:id/stats - Get article stats
     */
    public function stats($data)
    {
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
                    : 0,
            ],
        ]);
    }

    /**
     * GET /api/articles/admin/all - Get all articles for admin
     */
    public function getAllAdmin($data)
    {
        Auth::requireRole('admin');

        $filters = [
            'status' => $data['status'] ?? null,
            'is_paid' => $data['is_paid'] ?? null,
            'limit' => min((int)($data['limit'] ?? 50), 100),
            'offset' => (int)($data['offset'] ?? 0),
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
                    'rejected' => (int)$stats['rejected'],
                    'paid_pending' => (int)$stats['paid_pending'],
                ],
            ],
        ]);
    }

    /**
     * GET /api/articles/admin/pending - Get pending articles for admin
     */
    public function getPending($data)
    {
        Auth::requireRole('admin');

        $limit = min((int)($data['limit'] ?? 20), 50);
        $offset = (int)($data['offset'] ?? 0);

        $articles = $this->articleRepo->getPending($limit, $offset);
        $total = $this->articleRepo->count(['status' => 'pending']);

        return Response::json([
            'success' => true,
            'data' => [
                'articles' => $articles,
                'total' => (int)$total,
            ],
        ]);
    }

    /**
     * GET /api/articles/me - Get my articles
     */
    public function myArticles($data)
    {
        $user = Auth::requireAuth();

        $status = $data['status'] ?? null;
        $articles = $this->articleRepo->getByAuthor($user['id'], $status);

        return Response::json([
            'success' => true,
            'data' => ['articles' => $articles],
        ]);
    }

    /**
     * GET /api/articles/user/:userId - Get published articles by user (public)
     */
    public function byAuthor($data)
    {
        $userId = (int)($data['userId'] ?? 0);
        if (!$userId) {
            return Response::json(['success' => false, 'message' => 'ID do usuário é obrigatório'], 400);
        }

        $limit = min((int)($data['limit'] ?? 10), 50);
        $offset = (int)($data['offset'] ?? 0);

        $articles = $this->articleRepo->getPublishedByAuthor($userId, $limit, $offset);
        $total = $this->articleRepo->countPublishedByAuthor($userId);

        return Response::json([
            'success' => true,
            'data' => [
                'articles' => $articles,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * POST /api/articles/upload-image - Upload article featured image
     */
    public function uploadImage()
    {
        $user = Auth::requireAuth();

        if (empty($_FILES['image'])) {
            return Response::json(['success' => false, 'message' => 'Nenhuma imagem enviada'], 400);
        }

        $file = $_FILES['image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo excede o limite máximo do servidor (2MB)',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o limite máximo do formulário',
                UPLOAD_ERR_PARTIAL => 'Upload foi parcialmente enviado',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
                UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente no servidor',
                UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever arquivo no disco',
            ];
            $msg = $messages[$file['error']] ?? 'Erro desconhecido no upload';
            return Response::json(['success' => false, 'message' => $msg], 400);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowedExts)) {
            return Response::json(['success' => false, 'message' => 'Formato não permitido. Use JPG, PNG ou WebP.'], 400);
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            return Response::json(['success' => false, 'message' => 'Imagem deve ter no máximo 5MB'], 400);
        }

        $targetDir = __DIR__ . '/../../public/uploads/articles/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'article_' . $user['id'] . '_' . time() . '_' . uniqid() . '.' . $ext;

        if (move_uploaded_file($file['tmp_name'], $targetDir . $fileName)) {
            return Response::json([
                'success' => true,
                'data' => ['url' => '/uploads/articles/' . $fileName],
            ]);
        }

        $error = error_get_last();
        error_log('Erro ao salvar imagem: ' . ($error['message'] ?? 'move_uploaded_file failed'));
        return Response::json(['success' => false, 'message' => 'Erro ao salvar imagem'], 500);
    }

    /**
     * Helper: Generate slug from title
     */
    private function generateSlug($title)
    {
        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $title);
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return $slug;
    }
}
