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
            'posts_per_page' => -1,
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
            if (self::containsLogoMarker($attrs)) {
                $id = self::findNumericId($attrs);
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

    private static function containsLogoMarker($value): bool
    {
        if (is_string($value)) {
            return preg_match('/\blogo\b|header-logo/i', $value) === 1;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if (self::containsLogoMarker($child)) {
                return true;
            }
        }
        return false;
    }

    private static function findNumericId($value): int
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ($key === 'id' && is_numeric($child) && (int) $child > 0) {
                    return (int) $child;
                }
                $id = self::findNumericId($child);
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
