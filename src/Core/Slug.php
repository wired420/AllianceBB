<?php

declare(strict_types=1);

namespace AllianceBB\Core;

final class Slug
{
    public static function make(string $text, int $max = 60): string
    {
        $text = mb_strtolower(trim($text));
        if (function_exists('transliterator_transliterate')) {
            $text = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        }
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim(substr($text, 0, $max), '-');
        return $text === '' ? 'item' : $text;
    }
}
