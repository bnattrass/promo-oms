<?php

namespace App\Services\Orders;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Small product thumbnails for PDFs. Supplier images are downloaded once, shrunk to 240px and cached
 * on disk, then embedded as data URIs so DomPDF never fetches remote files while rendering.
 */
class ProductImages
{
    private const SIZE = 240;

    /** Best image for a line: the variant's own colour image, else the product's default. */
    public function urlFor(?ProductVariant $variant, ?Product $product = null): ?string
    {
        $product ??= $variant?->product;
        $images = $product?->images ?? [];

        if ($variant?->image_url) {
            return $variant->image_url;
        }
        if ($variant) {
            // Amrod names colour images after the variant full code, e.g. .../ABC-123-BL_1024X1024.jpg
            foreach ($images as $url) {
                if (str_contains($url, '/'.$variant->full_code.'_') || str_contains($url, '/'.$variant->full_code.'.')) {
                    return $url;
                }
            }
        }

        // Prefer the default (unbranded) image.
        foreach ($images as $url) {
            if (str_contains(strtoupper($url), '/DEFAULT_')) {
                return $url;
            }
        }

        return $images[0] ?? null;
    }

    /** Data URI of a cached thumbnail, or null if the image can't be fetched. */
    public function dataUri(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $dir = storage_path('app/private/image-cache');
        @mkdir($dir, 0775, true);
        $file = $dir.'/'.md5($url).'.jpg';

        if (! is_file($file)) {
            try {
                $response = Http::timeout(15)->get(str_replace(' ', '%20', $url));
                if (! $response->successful()) {
                    return null;
                }
                $this->saveThumbnail($response->body(), $file);
            } catch (Throwable $e) {
                Log::info("Product image not available for PDF: {$url} ({$e->getMessage()})");

                return null;
            }
        }

        return is_file($file) ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($file)) : null;
    }

    private function saveThumbnail(string $bytes, string $file): void
    {
        if (! function_exists('imagecreatefromstring') || ! ($src = @imagecreatefromstring($bytes))) {
            file_put_contents($file, $bytes); // no GD: keep the original

            return;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::SIZE / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency onto white
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        imagejpeg($dst, $file, 82);
        imagedestroy($src);
        imagedestroy($dst);
    }
}
