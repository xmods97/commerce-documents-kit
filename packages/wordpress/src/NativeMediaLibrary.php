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
