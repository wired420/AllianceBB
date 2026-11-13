<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Hooks;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/** Turns post Markdown into safe HTML. Raw HTML in posts is escaped. */
final class Markup
{
    private ?GithubFlavoredMarkdownConverter $converter = null;

    public function __construct(private readonly Hooks $hooks)
    {
    }

    public function render(string $markdown): string
    {
        $this->converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $markdown = $this->hooks->applyFilters('markup.before', $markdown);
        $html = (string) $this->converter->convert($markdown);
        // Links in user content should not pass on referrer or ranking.
        $html = str_replace('<a href=', '<a rel="nofollow ugc noopener" href=', $html);
        return $this->hooks->applyFilters('markup.after', $html, $markdown);
    }
}
