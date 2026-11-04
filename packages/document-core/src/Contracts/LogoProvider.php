<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\Rendering\Image\RasterImage;

/**
 * Supplies the issuer's logo as already-validated image bytes.
 *
 * The renderer never learns where the logo came from and never resolves a
 * reference itself; an implementation hands over an image or nothing. An
 * implementation that cannot produce a safe image must return null rather than
 * throw — a document without a logo is a valid document.
 */
interface LogoProvider
{
    public function logo(): ?RasterImage;
}
