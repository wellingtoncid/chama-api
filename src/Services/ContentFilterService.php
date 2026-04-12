<?php

namespace App\Services;

class ContentFilterService {
    
    private static array $badWords = [
        // Ofensas
        'idiota', 'imbecil', 'besta', 'besta feroz', 'otario', 'otário', 'palhaço', 
        'estupido', 'estúpido', 'burro', 'babaca', 'cuzao', 'cuzao', 'fdp', 'filha da puta',
        'vsf', 'vsf', 'puta', 'merda', 'caralho', 'porra', 'desgraça', 'maldito', 'maldita',
        // Golpe e spam
        'golpe', 'fraude', 'scam', 'piramide', 'pirâmide', 'esquema', 'ganhe dinheiro fácil',
        'urubu do pix', 'urubudopix', 'pix jogo', 'aposta gratis',
        // Concorrência
        'site-concorrente.com', 'www.concorrente', 'melhor que', 'troque para',
        // Outros
        'lixo', 'nunca mais', 'nunca contrate', 'pior', 'pessimo', 'péssimo', 'horrivel', 'horrível',
    ];

    public static function isClean(string $text): bool {
        if (empty(trim($text))) {
            return true;
        }

        $textLower = mb_strtolower($text);

        // 1. Verificar palavras proibidas
        foreach (self::$badWords as $word) {
            if (str_contains($textLower, $word)) {
                return false;
            }
        }

        // 2. Evitar excesso de links (mais de 2)
        $linkCount = preg_match_all('/https?:\/\/|www\./i', $text);
        if ($linkCount > 2) {
            return false;
        }

        // 3. Bloquear números de telefone (máscara brasileira)
        // Padrões: (11) 99999-9999, (11) 9999-9999, 11999999999, etc.
        $phonePatterns = [
            '/\(?\d{2}\)?[\s.-]?\d{4,5}[\s.-]?\d{4}/',  // (11) 99999-9999 ou (11) 9999-9999
            '/\d{10,11}/',                                  // 11999999999 ou 1199999999
        ];
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }

        // 4. Evitar excesso de números (suspeito de spam de telefone/CPF)
        $digitCount = preg_match_all('/\d/', $text);
        if ($digitCount > 30) {
            return false;
        }

        return true;
    }

    public static function getReason(string $text): ?string {
        if (empty(trim($text))) {
            return null;
        }

        $textLower = mb_strtolower($text);

        // Verificar palavras proibidas
        foreach (self::$badWords as $word) {
            if (str_contains($textLower, $word)) {
                return "Conteúdo contém palavras não permitidas.";
            }
        }

        // Evitar excesso de links
        $linkCount = preg_match_all('/https?:\/\/|www\./i', $text);
        if ($linkCount > 2) {
            return "Muitas tentativas de links detectados.";
        }

        // Bloquear números de telefone
        $phonePatterns = [
            '/\(?\d{2}\)?[\s.-]?\d{4,5}[\s.-]?\d{4}/',
            '/\d{10,11}/',
        ];
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return "Números de telefone não são permitidos.";
            }
        }

        // Evitar excesso de números
        $digitCount = preg_match_all('/\d/', $text);
        if ($digitCount > 30) {
            return "Excesso de números detectado.";
        }

        return null;
    }

    public static function sanitize(string $text): string {
        // Remove excesso de espaços
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
