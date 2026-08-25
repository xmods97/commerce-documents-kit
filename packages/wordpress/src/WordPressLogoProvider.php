<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use Throwable;
use Xmods\CommerceDocuments\Contracts\LogoProvider;
use Xmods\CommerceDocuments\Rendering\Image\RasterImage;
use Xmods\CommerceDocuments\WordPress\Contracts\MediaLibrary;

/**
 * Resolves the site's Custom Logo to local image bytes for the PDF renderer.
 *
 * The rule this class exists to enforce: **the logo is a file inside the
 * WordPress uploads directory, and nothing else.** Not a URL, not a data URL,
 * not a theme asset, not a path assembled from anything a request can influence.
 * WordPress is asked for the attachment; the answer is then treated as untrusted
 * and checked before a single byte is read.
 *
 * The checks, in the order they run:
 *
 *  1. The Custom Logo attachment id must exist and be positive.
 *  2. Its recorded MIME type must be image/png or image/jpeg. SVG is refused
 *     here, and again by RasterImage, which has no SVG code path at all.
 *  3. A size variant is preferred over the original, so a 3705x1536 upload does
 *     not become the thing that gets decoded on every invoice.
 *  4. The candidate path is rejected outright if it contains a scheme separator
 *     or a NUL byte, then resolved with realpath() and required to sit under
 *     realpath(uploads). realpath() resolves `..` and symlinks, so containment is
 *     decided on the real location, not on the spelling of the path.
 *  5. Extension, file size, finfo MIME and getimagesize must all agree, and only
 *     then are the bytes handed to RasterImage, which parses them independently.
 *
 * Any failure returns null. A missing or unsafe logo must never stop a document
 * from being issued, so nothing here throws — the reason is kept in
 * lastRejection() for diagnostics.
 */
final class WordPressLogoProvider implements LogoProvider
{
    /** Variants narrower than this are too coarse for a printed header. */
    private const PREFERRED_MIN_WIDTH = 480;

    /**
     * How far a size variant's proportions may differ from the original's before
     * it is treated as a crop rather than a scaled copy.
     */
    private const ASPECT_TOLERANCE = 0.01;

    private const ALLOWED_MIME = ['image/png', 'image/jpeg'];
    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg'];

    /** @var MediaLibrary */
    private $media;
    /** @var string */
    private $rejection = '';

    public function __construct(?MediaLibrary $media = null)
    {
        $this->media = $media ?? new NativeMediaLibrary();
    }

    public function logo(): ?RasterImage
    {
        $this->rejection = '';

        try {
            return $this->resolve();
        } catch (Throwable $exception) {
            // Includes RasterImage's rejections. A logo is decoration; nothing it
            // can do may take a document down with it.
            $this->rejection = $exception->getMessage();
            return null;
        }
    }

    /** Why the last call returned null. Empty when a logo was produced. */
    public function lastRejection(): string
    {
        return $this->rejection;
    }

    private function resolve(): ?RasterImage
    {
        $customLogoId = $this->media->customLogoAttachmentId();
        // The Custom Logo is the explicit site setting. Avoid scanning all Divi
        // header layouts when it is present; a rejected custom image remains a
        // rejection rather than silently switching brand sources.
        $attachmentIds = $customLogoId > 0
            ? [$customLogoId]
            : [$this->media->themeHeaderLogoAttachmentId()];
        $attachmentIds = array_values(array_filter($attachmentIds, static function ($id): bool {
            return (int) $id > 0;
        }));
        if ($attachmentIds === []) {
            $this->rejection = 'No Custom Logo or theme header logo is set.';
            return null;
        }

        foreach ($attachmentIds as $attachmentId) {
            $image = $this->resolveAttachment((int) $attachmentId);
            if ($image instanceof RasterImage) {
                return $image;
            }
        }
        return null;
    }

    private function resolveAttachment(int $attachmentId): ?RasterImage
    {

        $mime = strtolower(trim($this->media->mimeTypeOf($attachmentId)));
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $this->rejection = 'Custom Logo is not a PNG or JPEG attachment.';
            return null;
        }

        $original = $this->media->pathOf($attachmentId);
        if ($original === '') {
            $this->rejection = 'Custom Logo has no local file.';
            return null;
        }

        $base = $this->media->uploadsBaseDirectory();
        if ($base === '') {
            $this->rejection = 'The uploads directory is unavailable.';
            return null;
        }
        $uploads = realpath($base);
        if ($uploads === false) {
            $this->rejection = 'The uploads directory does not resolve.';
            return null;
        }

        foreach ($this->candidates($attachmentId, $original) as $candidate) {
            $path = $this->accept($candidate, $uploads);
            if ($path === null) {
                continue;
            }

            $binary = file_get_contents($path, false, null, 0, RasterImage::MAX_BYTES + 1);
            if ($binary === false || $binary === '') {
                $this->rejection = 'The logo file could not be read.';
                continue;
            }
            if (!$this->contentMatches($binary, $path)) {
                continue;
            }

            $image = RasterImage::fromBinary($binary);
            $this->rejection = '';
            return $image;
        }

        if ($this->rejection === '') {
            $this->rejection = 'No usable logo file was found.';
        }
        return null;
    }

    /**
     * Candidate paths, best first: a proportional size variant wide enough to
     * print, then the original. Only the file *name* is taken from the metadata —
     * the directory always comes from the attachment's own path.
     *
     * The aspect ratio check is the important part. WordPress generates two kinds
     * of size variant from one upload: scaled copies, which keep the proportions,
     * and **hard crops** such as `thumbnail` or the theme's own sizes, which do
     * not. A cropped variant of a logo is a piece of a logo — for the GEWARD
     * lockup that would mean the emblem without the wordmark, or the reverse.
     * Anything whose proportions do not match the original within a percent is
     * therefore skipped, and the full-size original is used instead.
     *
     * @return string[]
     */
    private function candidates(int $attachmentId, string $original): array
    {
        $directory = dirname($original);
        $sizes = [];

        $metadata = $this->media->metadataOf($attachmentId);
        $originalWidth = (int) ($metadata['width'] ?? 0);
        $originalHeight = (int) ($metadata['height'] ?? 0);
        // Without the original proportions there is nothing to compare a variant
        // against, so no variant is trusted and the original is used as it is.
        $ratio = ($originalWidth > 0 && $originalHeight > 0)
            ? $originalWidth / $originalHeight
            : null;

        foreach ((array) ($metadata['sizes'] ?? []) as $size) {
            if (!is_array($size) || $ratio === null) {
                continue;
            }
            $file = (string) ($size['file'] ?? '');
            $width = (int) ($size['width'] ?? 0);
            $height = (int) ($size['height'] ?? 0);
            $mime = strtolower((string) ($size['mime-type'] ?? ''));
            // basename() alone would already defeat a traversal attempt; the
            // equality check makes a rejected attempt visible instead of quietly
            // rewriting it into something that looks legitimate.
            if ($file === '' || basename($file) !== $file || $width < 1 || $height < 1) {
                continue;
            }
            if ($mime !== '' && !in_array($mime, self::ALLOWED_MIME, true)) {
                continue;
            }
            // A percent of tolerance absorbs WordPress's rounding when it scales;
            // a crop misses by far more than that.
            if (abs(($width / $height) - $ratio) > $ratio * self::ASPECT_TOLERANCE) {
                continue;
            }
            $sizes[] = ['file' => $file, 'width' => $width];
        }

        usort($sizes, static function (array $a, array $b): int {
            return $a['width'] <=> $b['width'];
        });

        $preferred = [];
        $fallback = [];
        foreach ($sizes as $size) {
            if ($size['width'] >= self::PREFERRED_MIN_WIDTH) {
                $preferred[] = $directory . DIRECTORY_SEPARATOR . $size['file'];
            } else {
                array_unshift($fallback, $directory . DIRECTORY_SEPARATOR . $size['file']);
            }
        }

        return array_merge($preferred, [$original], $fallback);
    }

    /** The path checks. Returns the resolved path, or null with a reason set. */
    private function accept(string $candidate, string $uploads): ?string
    {
        if ($candidate === '' || strpos($candidate, "\0") !== false) {
            $this->rejection = 'The logo path is empty or contains a NUL byte.';
            return null;
        }
        // Refused before the filesystem is touched: a scheme means somebody is
        // trying to make this fetch something, and this class never fetches.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) || stripos($candidate, 'data:') === 0) {
            $this->rejection = 'The logo path is a URL, which is never fetched.';
            return null;
        }

        $path = realpath($candidate);
        if ($path === false || !is_file($path)) {
            $this->rejection = 'The logo file does not exist.';
            return null;
        }

        $normalisedPath = str_replace('\\', '/', $path);
        $normalisedUploads = rtrim(str_replace('\\', '/', $uploads), '/');
        if (strpos($normalisedPath, $normalisedUploads . '/') !== 0) {
            // realpath() has already collapsed `..` and followed symlinks, so this
            // is the real location of the file, not the spelling of the request.
            $this->rejection = 'The logo file is outside the uploads directory.';
            return null;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->rejection = 'The logo file does not have a PNG or JPEG extension.';
            return null;
        }

        $size = filesize($path);
        if ($size === false || $size < 1) {
            $this->rejection = 'The logo file is empty.';
            return null;
        }
        if ($size > RasterImage::MAX_BYTES) {
            $this->rejection = 'The logo file is larger than the accepted maximum.';
            return null;
        }

        return $path;
    }

    /**
     * The extension says one thing; finfo and getimagesize have to agree with it
     * and with each other before the bytes go any further.
     */
    private function contentMatches(string $binary, string $path): bool
    {
        $detected = '';
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = strtolower((string) $finfo->buffer($binary));
        } elseif (function_exists('finfo_open')) {
            $handle = finfo_open(FILEINFO_MIME_TYPE);
            if ($handle !== false) {
                $detected = strtolower((string) finfo_buffer($handle, $binary));
                finfo_close($handle);
            }
        }
        if ($detected !== '' && !in_array($detected, self::ALLOWED_MIME, true)) {
            $this->rejection = 'The logo file content is not a PNG or JPEG (' . $detected . ').';
            return false;
        }

        if (function_exists('getimagesize')) {
            $info = @getimagesize($path);
            if ($info === false) {
                $this->rejection = 'The logo file is not a readable image.';
                return false;
            }
            if (!in_array((int) $info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                $this->rejection = 'The logo file is not a PNG or JPEG image.';
                return false;
            }
            $expected = (int) $info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg';
            if ($detected !== '' && $detected !== $expected) {
                $this->rejection = 'The logo file type is reported inconsistently.';
                return false;
            }
            if ((int) $info[0] > RasterImage::MAX_DIMENSION
                || (int) $info[1] > RasterImage::MAX_DIMENSION
                || (int) $info[0] * (int) $info[1] > RasterImage::MAX_PIXELS
            ) {
                $this->rejection = 'The logo image is larger than the renderer accepts.';
                return false;
            }
        }

        return true;
    }
}
