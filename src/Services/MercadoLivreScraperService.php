<?php
namespace App\Services;

class MercadoLivreScraperService
{
    private const AFFILIATE_TAG = 'lognetzlognetz20230116135329';
    private const ML_BASE_URL = 'https://produto.mercadolivre.com.br';

    public function scrapeProduct(string $mlUrl): array
    {
        $url = $this->normalizeUrl($mlUrl);
        
        if (!$this->isValidMlUrl($url)) {
            return [
                'success' => false,
                'message' => 'URL inválida. Forneça uma URL do Mercado Livre Brasil.'
            ];
        }

        $html = $this->fetchPage($url);
        
        if (!$html) {
            return [
                'success' => false,
                'message' => 'Não foi possível acessar o produto. Verifique se a URL está correta.'
            ];
        }

        $data = $this->parseHtml($html, $url);
        
        if (!$data['title']) {
            return [
                'success' => false,
                'message' => 'Não foi possível extrair os dados do produto.'
            ];
        }

        $data['affiliate_url'] = $this->generateAffiliateUrl($url);
        
        return [
            'success' => true,
            'data' => $data
        ];
    }

    public function generateAffiliateUrl(string $mlUrl): string
    {
        $url = $this->normalizeUrl($mlUrl);
        
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . 'tag=' . self::AFFILIATE_TAG;
    }

    public function getProductId(string $mlUrl): ?string
    {
        $url = $this->normalizeUrl($mlUrl);
        
        if (preg_match('/-(\d+)(?:[\/\?#]|$)/', $url, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        
        if (strpos($url, 'http') !== 0) {
            $url = 'https://' . $url;
        }
        
        return $url;
    }

    private function isValidMlUrl(string $url): bool
    {
        $patterns = [
            'mercadolivre.com.br',
            'mercadolivre.com',
            'mlabs.com.br',
        ];
        
        foreach ($patterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function fetchPage(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                    'Accept-Encoding: gzip, deflate, br',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1',
                ],
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        
        if ($response && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($response, ['UTF-8', 'ISO-8859-1', 'ASCII']);
            if ($encoding && $encoding !== 'UTF-8') {
                $response = mb_convert_encoding($response, 'UTF-8', $encoding);
            }
        }
        
        return $response ?: null;
    }

    private function parseHtml(string $html, string $url): array
    {
        $data = [
            'title' => null,
            'price' => null,
            'original_price' => null,
            'main_image' => null,
            'images' => [],
            'category' => null,
            'condition' => null,
            'seller_name' => null,
            'seller_id' => null,
            'product_id' => $this->getProductId($url),
            'original_url' => $url,
        ];

        $titlePatterns = [
            '/<h1[^>]*class="[^"]*ui-vip-title[^"]*"[^>]*>(.*?)<\/h1>/si',
            '/<h1[^>]*data-testid="title"[^>]*>(.*?)<\/h1>/si',
            '/<title>(.*?)<\/title>/si',
        ];
        
        foreach ($titlePatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['title'] = $this->cleanText($matches[1]);
                break;
            }
        }

        $pricePatterns = [
            '/<span[^>]*class="[^"]*(?:price|amount)[^"]*"[^>]*>R\$\s*([\d.,]+)/si',
            '/"price"\s*:\s*([\d.]+)/',
            '/<meta[^>]*itemprop="price"[^>]*content="([\d.]+)"/si',
        ];
        
        foreach ($pricePatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['price'] = (float) str_replace([',', '.'], ['.', ''], $matches[1]);
                break;
            }
        }

        $originalPricePatterns = [
            '/<s[^>]*class="[^"]*(?:original-price|price-original|strikethrough)[^"]*"[^>]*>R\$\s*([\d.,]+)/si',
            '/"priceBefore"\s*:\s*([\d.]+)/',
        ];
        
        foreach ($originalPricePatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['original_price'] = (float) str_replace([',', '.'], ['.', ''], $matches[1]);
                break;
            }
        }

        $imagePatterns = [
            '/<img[^>]*class="[^"]*(?:ui-vip-image|ui-pdp-image)[^"]*"[^>]*src="([^"]+)"/si',
            '/"image"\s*:\s*"(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i',
            '/<meta[^>]*property="og:image"[^>]*content="([^"]+)"/si',
            '/<img[^>]*data-src="([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/si',
        ];
        
        foreach ($imagePatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['main_image'] = $this->cleanUrl($matches[1]);
                break;
            }
        }

        $conditionPatterns = [
            '/<span[^>]*class="[^"]*ui-pdp-label[^"]*"[^>]*>(.*?)<\/span>/si',
            '/"condition"\s*:\s*"([^"]+)"/',
        ];
        
        foreach ($conditionPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $conditionText = $this->cleanText($matches[1]);
                $data['condition'] = (stripos($conditionText, 'novo') !== false) ? 'new' : 'used';
                break;
            }
        }

        $sellerPatterns = [
            '/<span[^>]*class="[^"]*(?:seller-name|ui-pdp-seller__name)[^"]*"[^>]*>(.*?)<\/span>/si',
            '/"seller"\s*:\s*\{"nickname"\s*:\s*"([^"]+)"/',
        ];
        
        foreach ($sellerPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['seller_name'] = $this->cleanText($matches[1]);
                break;
            }
        }

        $categoryPatterns = [
            '/"category"\s*:\s*"([^"]+)"/',
            '/<a[^>]*class="[^"]*breadcrumb[^"]*"[^>]*>(.*?)<\/a>/si',
        ];
        
        foreach ($categoryPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['category'] = $this->cleanText($matches[1]);
                break;
            }
        }

        return $data;
    }

    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }

    private function cleanUrl(string $url): string
    {
        $url = html_entity_decode($url);
        $url = str_replace(['\\/', '\\'], ['/', ''], $url);
        return trim($url);
    }
}
