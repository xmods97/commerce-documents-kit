<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress\Contracts;

/**
 * The narrow slice of the WordPress media library the logo resolver needs.
 *
 * It exists so the resolver's security decisions — path containment, format,
 * size — can be tested without a WordPress runtime. Everything behind this
 * interface is a lookup; every judgement is made in WordPressLogoProvider.
 */
interface MediaLibrary
{
    /** The attachment id set as the site's Custom Logo, or 0 when none is set. */
    public function customLogoAttachmentId(): int;

    /**
     * The active theme header logo attachment, or 0 when the theme does not
     * expose one through a safe local media reference.
     */
    public function themeHeaderLogoAttachmentId(): int;

    /** The attachment's recorded MIME type, or '' when it is unknown. */
    public function mimeTypeOf(int $attachmentId): string;

    /** Absolute path of the originally uploaded file, or '' when unavailable. */
    public function pathOf(int $attachmentId): string;

    /**
     * The attachment metadata, from which the generated size variants are read.
     *
     * @return array<string, mixed>
     */
    public function metadataOf(int $attachmentId): array;

    /** Absolute path of the uploads directory — the only place a logo may live. */
    public function uploadsBaseDirectory(): string;
}
