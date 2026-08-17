<?php

declare(strict_types=1);

namespace Catch\Services;

use DOMDocument;
use DOMNode;

final class EmailContentSanitizer
{
    public function htmlToText(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="catch-email-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('catch-email-root');
        if (!$root) {
            return '';
        }

        $text = $this->renderChildren($root);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function renderChildren(DOMNode $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= $this->render($child);
        }

        return $output;
    }

    private function render(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return html_entity_decode((string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['script', 'style', 'iframe', 'form', 'img', 'svg', 'object', 'embed', 'template'], true)) {
            return '';
        }

        $content = $this->renderChildren($node);

        return match (true) {
            $tag === 'br' => "\n",
            in_array($tag, ['p', 'div', 'section', 'article', 'header', 'footer'], true) => "\n" . trim($content) . "\n\n",
            in_array($tag, ['strong', 'b'], true) => $content === '' ? '' : '**' . $content . '**',
            in_array($tag, ['em', 'i'], true) => $content === '' ? '' : '*' . $content . '*',
            in_array($tag, ['pre'], true) => "\n```\n" . trim($content) . "\n```\n\n",
            $tag === 'code' => '`' . trim($content) . '`',
            $tag === 'blockquote' => "\n" . preg_replace('/^/m', '> ', trim($content)) . "\n\n",
            in_array($tag, ['ul', 'ol'], true) => "\n" . trim($content) . "\n\n",
            $tag === 'li' => $this->listItem($node, $content),
            preg_match('/^h[1-6]$/', $tag) === 1 => "\n" . str_repeat('#', (int) substr($tag, 1)) . ' ' . trim($content) . "\n\n",
            $tag === 'a' => $this->link($node, $content),
            in_array($tag, ['tr'], true) => trim($content) . "\n",
            in_array($tag, ['td', 'th'], true) => trim($content) . ' | ',
            default => $content,
        };
    }

    private function link(DOMNode $node, string $content): string
    {
        $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $content;
        }

        return '[' . trim($content) . '](' . $href . ')';
    }

    private function listItem(DOMNode $node, string $content): string
    {
        $parent = strtolower((string) $node->parentNode?->nodeName);
        if ($parent !== 'ol') {
            return '- ' . trim($content) . "\n";
        }

        $position = 1;
        for ($sibling = $node->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
            if (strtolower($sibling->nodeName) === 'li') {
                ++$position;
            }
        }

        return $position . '. ' . trim($content) . "\n";
    }
}
