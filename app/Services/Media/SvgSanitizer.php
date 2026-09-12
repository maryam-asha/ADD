<?php

namespace App\Services\Media;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

class SvgSanitizer
{
    /**
     * Disallowed SVG/XML tags that could execute scripts or load external resources.
     */
    private const BLOCKED_TAGS = [
        'script',
        'iframe',
        'object',
        'embed',
        'applet',
        'foreignobject',
        'meta',
        'link',
    ];

    /**
     * Sanitize SVG string content.
     *
     * @throws InvalidArgumentException if the SVG is invalid, malicious, or malformed.
     */
    public function sanitize(string $svgContent): string
    {
        $svgContent = trim($svgContent);

        if ($svgContent === '') {
            throw new InvalidArgumentException('Empty SVG content.');
        }

        // Fast reject for obvious XXE entities or script injections
        if (preg_match('/<!ENTITY/i', $svgContent) || preg_match('/<!DOCTYPE[^>]*\[/i', $svgContent)) {
            throw new InvalidArgumentException('SVG contains disallowed XML entity or DOCTYPE definitions.');
        }

        $dom = new DOMDocument();
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        // Suppress libxml warnings while parsing untrusted XML
        $previousEntityLoader = libxml_use_internal_errors(true);

        $loadFlags = LIBXML_NONET | LIBXML_NOWARNING;
        if (defined('LIBXML_NOERROR')) {
            $loadFlags |= LIBXML_NOERROR;
        }

        $loaded = $dom->loadXML($svgContent, $loadFlags);

        libxml_clear_errors();
        libxml_use_internal_errors($previousEntityLoader);

        if (! $loaded) {
            throw new InvalidArgumentException('Invalid SVG XML content.');
        }

        // Ensure root element is <svg>
        $root = $dom->documentElement;
        if (! $root || strtolower($root->tagName) !== 'svg') {
            throw new InvalidArgumentException('Root element must be an <svg> tag.');
        }

        // Clean document recursively
        $this->sanitizeNode($root);

        $cleanSvg = $dom->saveXML($dom->documentElement);
        if ($cleanSvg === false) {
            throw new InvalidArgumentException('Failed to serialize sanitized SVG.');
        }

        return $cleanSvg;
    }

    private function sanitizeNode(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $tagName = strtolower($node->tagName);

            if (in_array($tagName, self::BLOCKED_TAGS, true)) {
                $node->parentNode?->removeChild($node);

                return;
            }

            // Remove dangerous attributes
            $attributesToRemove = [];
            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->name);
                $attrValue = trim($attr->value);

                // Disallow any inline event handler (onload, onerror, onclick, etc.)
                if (str_starts_with($attrName, 'on')) {
                    $attributesToRemove[] = $attr->name;

                    continue;
                }

                // Check for javascript: or vbscript: or data: in href or src attributes
                if (in_array($attrName, ['href', 'xlink:href', 'src', 'action', 'formaction'], true)) {
                    $normalizedValue = strtolower(preg_replace('/\s+/', '', $attrValue));
                    if (
                        str_starts_with($normalizedValue, 'javascript:') ||
                        str_starts_with($normalizedValue, 'vbscript:') ||
                        str_starts_with($normalizedValue, 'data:text')
                    ) {
                        $attributesToRemove[] = $attr->name;

                        continue;
                    }
                }

                // Check for expression() or javascript in style attribute
                if ($attrName === 'style') {
                    $normalizedStyle = strtolower(preg_replace('/\s+/', '', $attrValue));
                    if (str_contains($normalizedStyle, 'expression(') || str_contains($normalizedStyle, 'javascript:')) {
                        $attributesToRemove[] = $attr->name;

                        continue;
                    }
                }
            }

            foreach ($attributesToRemove as $attrName) {
                $node->removeAttribute($attrName);
            }
        }

        // Recurse children
        $child = $node->firstChild;
        while ($child) {
            $nextChild = $child->nextSibling;
            $this->sanitizeNode($child);
            $child = $nextChild;
        }
    }
}
