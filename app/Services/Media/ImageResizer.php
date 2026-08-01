<?php

namespace App\Services\Media;

use GdImage;

/**
 * Scales images down with GD.
 *
 * Shared by the post-cover and user-avatar jobs so the encoding and
 * alpha-handling rules only exist in one place.
 */
class ImageResizer
{
    /**
     * Whether the extension needed to do any of this is present.
     */
    public function isAvailable(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /**
     * Scale the given image down to fit a maximum width, preserving its ratio.
     *
     * Images already narrower than the maximum are re-encoded at their existing
     * size rather than enlarged. Returns null when the bytes cannot be decoded.
     */
    public function scaleToWidth(string $contents, int $maxWidth): ?string
    {
        $dimensions = @getimagesizefromstring($contents);

        if ($dimensions === false) {
            return null;
        }

        [$width, $height] = $dimensions;

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        $resized = null;

        try {
            $scale = min(1, $maxWidth / max($width, 1));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($targetWidth, $targetHeight);

            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled(
                $resized, $source,
                0, 0, 0, 0,
                $targetWidth, $targetHeight,
                $width, $height,
            );

            return $this->encode($resized, $dimensions[2]);
        } finally {
            imagedestroy($source);

            if ($resized instanceof GdImage) {
                imagedestroy($resized);
            }
        }
    }

    /**
     * Encode the image back into the format the original used.
     */
    private function encode(GdImage $image, int $imageType): ?string
    {
        ob_start();

        $encoded = match ($imageType) {
            IMAGETYPE_PNG => imagepng($image, null, 6),
            IMAGETYPE_WEBP => imagewebp($image, null, 82),
            default => imagejpeg($image, null, 82),
        };

        $contents = ob_get_clean();

        return $encoded && is_string($contents) && $contents !== '' ? $contents : null;
    }
}
