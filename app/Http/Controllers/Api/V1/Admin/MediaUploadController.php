<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Media\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaUploadController extends Controller
{
    public function upload(Request $request, ImageService $imageService): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpeg,png,jpg,webp,gif,svg'],
            'folder' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\/]+$/'],
        ]);

        $file = $request->file('file');
        $folder = trim((string) $request->input('folder', 'uploads'), '/\\');

        if ($folder === '') {
            $folder = 'uploads';
        } elseif (! str_starts_with($folder, 'uploads/') && $folder !== 'uploads') {
            $folder = 'uploads/'.$folder;
        }

        $result = $imageService->store($file, $folder);

        return response()->json($result, 201);
    }
}
