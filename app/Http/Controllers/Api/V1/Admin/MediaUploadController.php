<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Media\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Images are optimized by ImageService; a PDF (a supplier's invoice
 * attached to a stock operation) is stored as it is, under a random name.
 */
class MediaUploadController extends Controller
{
    public function upload(Request $request, ImageService $imageService): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpeg,png,jpg,webp,gif,svg,pdf'],
            'folder' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\/]+$/'],
        ]);

        $file = $request->file('file');
        $folder = trim((string) $request->input('folder', 'uploads'), '/\\');

        if ($folder === '') {
            $folder = 'uploads';
        } elseif (! str_starts_with($folder, 'uploads/') && $folder !== 'uploads') {
            $folder = 'uploads/'.$folder;
        }

        $result = $file->getMimeType() === 'application/pdf'
            ? $this->storePdf($file, $folder, $imageService)
            : $imageService->store($file, $folder);

        return response()->json($result, 201);
    }

    /**
     * @return array{url: string, path: string, name: string, size: int, mime: string}
     */
    private function storePdf(UploadedFile $file, string $folder, ImageService $images): array
    {
        $path = Storage::disk($images->getDisk())->putFileAs($folder, $file, Str::random(40).'.pdf');

        return [
            'url' => $images->url($path),
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => 'application/pdf',
        ];
    }
}
