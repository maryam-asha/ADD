<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Models\DocumentAccess;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmployeeDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Employee documents are the most sensitive data HR holds. They are stored
 * encrypted with the app key on a private disk, never under a public path,
 * and read only through a signed link that expires in minutes. Every link
 * issued and every file served is logged.
 */
class DocumentVault
{
    /**
     * @param  array{issued_on?: ?string, expires_on?: ?string, notes?: ?string}  $meta
     */
    public function store(Employee $employee, DocumentType $type, UploadedFile $file, array $meta, int|string|null $uploaderId): EmployeeDocument
    {
        $contents = $file->getContent();
        $path = trim(config('hr.documents.directory'), '/')."/{$employee->id}/".Str::uuid().'.bin';

        $this->disk()->put($path, Crypt::encryptString($contents));

        return $employee->documents()->create([
            'document_type_id' => $type->id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'issued_on' => $meta['issued_on'] ?? null,
            'expires_on' => $meta['expires_on'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'uploaded_by' => $uploaderId,
        ]);
    }

    /**
     * @return array{url: string, expires_at: string}
     */
    public function link(EmployeeDocument $document, int|string|null $userId): array
    {
        $expiresAt = now()->addMinutes((int) config('hr.documents.link_ttl_minutes'));

        $this->log($document, DocumentAccess::LINK_ISSUED, $userId);

        return [
            'url' => URL::temporarySignedRoute('hr.documents.file', $expiresAt, ['document' => $document->id]),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function response(EmployeeDocument $document, Request $request): Response
    {
        $contents = Crypt::decryptString($this->disk()->get($document->path));

        $this->log($document, DocumentAccess::DOWNLOADED, null, $request->ip());

        return new Response($contents, 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $document->original_name,
                Str::ascii($document->original_name) ?: 'document',
            ),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function log(EmployeeDocument $document, string $action, int|string|null $userId, ?string $ip = null): void
    {
        $document->accesses()->create(['action' => $action, 'user_id' => $userId, 'ip' => $ip]);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('hr.documents.disk'));
    }
}
