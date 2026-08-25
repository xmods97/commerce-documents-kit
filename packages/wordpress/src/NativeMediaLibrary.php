<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use Xmods\CommerceDocuments\WordPress\Contracts\MediaLibrary;

/**
 * The WordPress-backed media library lookup.
 *
 * Every call is guarded by function_exists and degrades to "nothing found", so
 * the class is inert outside a WordPress request rather than fatal. It performs
 * no validation of its own — that is entirely WordPressLogoProvider's job — and
 * it issues no HTTP request: `get_attached_file()` returns a local path, and the
 * URL-returning media functions are deliberately not used.
 */
final class NativeMediaLibrary implements MediaLibrary
{
    /** Header layouts are a fallback only; never parse an unbounded post set. */
    private const MAX_HEADER_LAYOUTS = 12;

    public function customLogoAttachmentId(): int
    {
        if (!function_exists('get_theme_mod')) {
            return 0;
        }
        $id = get_theme_mod('custom_logo');
        return is_numeric($id) && (int) $id > 0 ? (int) $id : 0;
    }

    public function themeHeaderLogoAttachmentId(): int
    {
        if (!function_exists('get_posts') || !function_exists('get_post_field')) {
            return 0;
        }

        $layouts = get_posts([
            'post_type' => 'et_header_layout',
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_HEADER_LAYOUTS,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => true,
        ]);
        $candidates = [];

        foreach ((array) $layouts as $layout) {
            $layoutId = is_object($layout) && isset($layout->ID) ? (int) $layout->ID : 0;
            if ($layoutId < 1) {
                continue;
            }
            $content = get_post_field('post_content', $layoutId);
            if (!is_string($content) || $content === '' || !function_exists('parse_blocks')) {
                continue;
            }
            $blocks = parse_blocks($content);
            $id = self::findLogoAttachmentId((array) $blocks);
            if ($id > 0) {
                $candidates[$id] = true;
            }
        }

        // If several published layouts expose different logos, fail closed
        // rather than silently putting the wrong brand on a document.
        return count($candidates) === 1 ? (int) array_key_first($candidates) : 0;
    }

    /** @param array<string|int, mixed> $value */
    private static function findLogoAttachmentId(array $blocks): int
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $attrs = $block['attrs'] ?? [];
            $blockName = strtolower((string) ($block['blockName'] ?? ''));
            if (self::isHeaderLogoImageBlock($blockName, (array) $attrs)) {
                $id = self::imageAttachmentId((array) $attrs);
                if ($id > 0) {
                    return $id;
                }
            }
            $inner = $block['innerBlocks'] ?? [];
            $id = self::findLogoAttachmentId((array) $inner);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    /** @param array<string|int, mixed> $attrs */
    private static function isHeaderLogoImageBlock(string $blockName, array $attrs): bool
    {
        return preg_match('#/(?:image|logo)$#', $blockName) === 1
            && self::containsHeaderLogoClass($attrs);
    }

    private static function containsHeaderLogoClass($value): bool
    {
        if (is_string($value)) {
            return preg_match('/(?:^|\s)[a-z0-9_-]*header-logo[a-z0-9_-]*(?:\s|$)/i', $value) === 1;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if (self::containsHeaderLogoClass($child)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string|int, mixed> $attrs */
    private static function imageAttachmentId(array $attrs): int
    {
        foreach ($attrs as $key => $value) {
            if (in_array((string) $key, ['image', 'image_id', 'imageId'], true)) {
                $id = self::numericId($value);
                if ($id > 0) {
                    return $id;
                }
            }
            if (is_array($value)) {
                $id = self::imageAttachmentId($value);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        return 0;
    }

    private static function numericId($value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        foreach ($value as $key => $child) {
            if ($key === 'id' && is_numeric($child) && (int) $child > 0) {
                return (int) $child;
            }
            if (is_array($child)) {
                $id = self::numericId($child);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        return 0;
    }

    public function mimeTypeOf(int $attachmentId): string
    {
        if ($attachmentId < 1 || !function_exists('get_post_mime_type')) {
            return '';
        }
        $mime = get_post_mime_type($attachmentId);
        return is_string($mime) ? strtolower($mime) : '';
    }

    public function pathOf(int $attachmentId): string
    {
        if ($attachmentId < 1 || !function_exists('get_attached_file')) {
            return '';
        }
        $path = get_attached_file($attachmentId);
        return is_string($path) ? $path : '';
    }

    public function metadataOf(int $attachmentId): array
    {
        if ($attachmentId < 1 || !function_exists('wp_get_attachment_metadata')) {
            return [];
        }
        $metadata = wp_get_attachment_metadata($attachmentId);
        return is_array($metadata) ? $metadata : [];
    }

    public function uploadsBaseDirectory(): string
    {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }
        $uploads = wp_upload_dir();
        if (!is_array($uploads) || ($uploads['error'] ?? false)) {
            return '';
        }
        $base = $uploads['basedir'] ?? '';
        return is_string($base) ? $base : '';
    }
}
