<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ImageService
{
    /**
     * Allowed image MIME types.
     */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/svg+xml',
    ];

    /**
     * Allowed image extensions.
     */
    public const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'svg',
    ];

    /**
     * Maximum dimension (width or height) in pixels before downscaling.
     */
    public const MAX_DIMENSION = 2560;

    /**
     * WebP compression quality (0-100).
     */
    public const WEBP_QUALITY = 85;

    protected string $disk;

    protected SvgSanitizer $svgSanitizer;

    public function __construct(?string $disk = null, ?SvgSanitizer $svgSanitizer = null)
    {
        $this->disk = $disk ?? config('filesystems.media_disk', 'public');
        $this->svgSanitizer = $svgSanitizer ?? new SvgSanitizer();
    }

    /**
     * Get the active disk name.
     */
    public function getDisk(): string
    {
        return $this->disk;
    }

    /**
     * Store and optionally optimize/convert an uploaded image.
     *
     * @return array{url: string, path: string, name: string, size: int, mime: string}
     *
     * @throws InvalidArgumentException
     */
    public function store(UploadedFile $file, string $folder = 'uploads', bool $optimize = true): array
    {
        $this->guardAgainstPathTraversal($folder);
        $folder = trim($folder, '/\\');

        if (! $file->isValid()) {
            throw new InvalidArgumentException('Uploaded file is not valid.');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true) || ! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('Unsupported or invalid image format.');
        }

        $rawContent = file_get_contents($file->getPathname());
        if ($rawContent === false || strlen($rawContent) === 0) {
            throw new InvalidArgumentException('Uploaded file is empty or unreadable.');
        }

        // SVG handling (XML, no GD)
        if ($extension === 'svg' || $mime === 'image/svg+xml') {
            $cleanSvg = $this->svgSanitizer->sanitize($rawContent);
            $filename = Str::random(40).'.svg';
            $path = $folder !== '' ? "{$folder}/{$filename}" : $filename;

            Storage::disk($this->disk)->put($path, $cleanSvg);

            return [
                'url' => $this->url($path),
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => strlen($cleanSvg),
                'mime' => 'image/svg+xml',
            ];
        }

        // GIF handling (preserve animation without WebP flattening)
        if ($extension === 'gif' || $mime === 'image/gif') {
            $this->validateImageHeader($file->getPathname());
            $filename = Str::random(40).'.gif';
            $path = $folder !== '' ? "{$folder}/{$filename}" : $filename;

            Storage::disk($this->disk)->put($path, $rawContent);

            return [
                'url' => $this->url($path),
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => strlen($rawContent),
                'mime' => 'image/gif',
            ];
        }

        // Raster images (JPEG, PNG, WebP)
        $this->validateImageHeader($file->getPathname());

        if ($optimize) {
            [$processedData, $finalExt, $finalMime] = $this->processAndConvertToWebp($file->getPathname());
        } else {
            $processedData = $rawContent;
            $finalExt = $extension === 'jpeg' ? 'jpg' : $extension;
            $finalMime = $mime;
        }

        $filename = Str::random(40).".{$finalExt}";
        $path = $folder !== '' ? "{$folder}/{$filename}" : $filename;

        Storage::disk($this->disk)->put($path, $processedData);

        return [
            'url' => $this->url($path),
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => strlen($processedData),
            'mime' => $finalMime,
        ];
    }

    /**
     * Resolve the public or relative URL for a stored image path.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        // If it's already an external URL (and not pointing to our local storage)
        if ($this->isExternalUrl($path)) {
            return $path;
        }

        // Strip any absolute baseUrl prefix (e.g. http://localhost/storage/uploads/...)
        $storagePath = $this->toStoragePath($path);

        if ($storagePath === null) {
            return null;
        }

        $url = Storage::disk($this->disk)->url($storagePath);

        // For public disk, guarantee a relative URL if disk URL starts with /storage
        if ($this->disk === 'public') {
            return $this->toRelativeUrl($url);
        }

        return $url;
    }

    /**
     * Safely delete a file from the disk.
     *
     * @return bool True if deleted or did not exist, false if skipped (external URL/placeholder).
     */
    public function delete(?string $pathOrUrl): bool
    {
        if ($pathOrUrl === null || trim($pathOrUrl) === '') {
            return false;
        }

        // External URLs are ignored safely
        if ($this->isExternalUrl($pathOrUrl)) {
            return false;
        }

        $storagePath = $this->toStoragePath($pathOrUrl);
        if ($storagePath === null || $storagePath === '') {
            return false;
        }

        $this->guardAgainstPathTraversal($storagePath);

        if (Storage::disk($this->disk)->exists($storagePath)) {
            return Storage::disk($this->disk)->delete($storagePath);
        }

        return true;
    }

    /**
     * Normalize any image URL or path to a relative URL (e.g. /storage/uploads/...).
     */
    public function toRelativeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if ($this->isExternalUrl($url)) {
            return $url;
        }

        // Strip scheme and host if present (e.g. http://localhost:8000/storage/...)
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;

        // Ensure leading slash
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        // If it doesn't have /storage prefix, add it (for public disk)
        if (! str_starts_with($path, '/storage/')) {
            $path = '/storage'.rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Extract the relative storage disk path (e.g. 'uploads/xyz.webp') from any URL or path.
     */
    public function toStoragePath(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if ($this->isExternalUrl($url)) {
            return null;
        }

        // Strip host/scheme if present
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;

        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        // If it starts with storage/, strip it
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $this->guardAgainstPathTraversal($path);

        return $path !== '' ? $path : null;
    }

    /**
     * Check if a URL is an external link not belonging to this app's storage.
     */
    public function isExternalUrl(string $url): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        // If it contains /storage/, it is a local storage URL that was saved with full BaseURL
        if (str_contains($url, '/storage/')) {
            return false;
        }

        return true;
    }

    /**
     * Guard against path traversal attacks.
     *
     * @throws InvalidArgumentException
     */
    public function guardAgainstPathTraversal(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);

        if (
            str_contains($normalized, '../') ||
            str_contains($normalized, '/..') ||
            $normalized === '..' ||
            str_starts_with($normalized, '../')
        ) {
            throw new InvalidArgumentException('Path traversal attempt detected.');
        }
    }

    /**
     * Validate that file header is indeed an image.
     *
     * @throws InvalidArgumentException
     */
    protected function validateImageHeader(string $filePath): void
    {
        $info = @getimagesize($filePath);
        if ($info === false) {
            throw new InvalidArgumentException('Corrupted or invalid image file.');
        }
    }

    /**
     * Process raster image using GD:
     * - Correct orientation via EXIF
     * - Downscale if larger than MAX_DIMENSION
     * - Convert to WebP (stripping EXIF)
     *
     * @return array{0: string, 1: string, 2: string} [binaryData, extension, mimeType]
     *
     * @throws InvalidArgumentException
     */
    protected function processAndConvertToWebp(string $filePath): array
    {
        $imageData = file_get_contents($filePath);
        if ($imageData === false) {
            throw new InvalidArgumentException('Cannot read image file.');
        }

        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            throw new InvalidArgumentException('GD cannot parse image data.');
        }

        // Auto-orient based on EXIF if available (JPEG only)
        $image = $this->autoOrient($image, $filePath);

        $width = imagesx($image);
        $height = imagesy($image);

        // Downscale if exceeds max dimension
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            if ($width >= $height) {
                $newWidth = self::MAX_DIMENSION;
                $newHeight = (int) round(($height / $width) * self::MAX_DIMENSION);
            } else {
                $newHeight = self::MAX_DIMENSION;
                $newWidth = (int) round(($width / $height) * self::MAX_DIMENSION);
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // Output to WebP in memory
        ob_start();
        imagesavealpha($image, true);
        imagewebp($image, null, self::WEBP_QUALITY);
        $webpContent = ob_get_clean();

        imagedestroy($image);

        if ($webpContent === false || strlen($webpContent) === 0) {
            throw new InvalidArgumentException('Failed to encode image to WebP.');
        }

        return [$webpContent, 'webp', 'image/webp'];
    }

    /**
     * Rotate image according to EXIF Orientation flag.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    protected function autoOrient($image, string $filePath)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($filePath);
        if (! is_array($exif) || empty($exif['Orientation'])) {
            return $image;
        }

        $orientation = (int) $exif['Orientation'];

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated !== false && $rotated !== $image) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
