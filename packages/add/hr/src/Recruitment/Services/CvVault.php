<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Recruitment\Models\Application;
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
 * CVs are personal data sent by people outside the organisation: stored
 * encrypted on the private documents disk, read only through a signed
 * link that expires in minutes, each link audited.
 */
class CvVault
{
    /**
     * @return array{cv_path: string, cv_name: string, cv_mime: string, cv_size: int}
     */
    public function store(UploadedFile $file): array
    {
        $contents = $file->getContent();
        $path = trim((string) config('hr.recruitment.cv_directory', 'hr/cvs'), '/').'/'.Str::uuid().'.bin';

        $this->disk()->put($path, Crypt::encryptString($contents));

        return [
            'cv_path' => $path,
            'cv_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'cv_mime' => $file->getMimeType() ?? 'application/octet-stream',
            'cv_size' => strlen($contents),
        ];
    }

    /**
     * @return array{url: string, expires_at: string}
     */
    public function link(Application $application): array
    {
        $expiresAt = now()->addMinutes((int) config('hr.documents.link_ttl_minutes', 5));

        HrAudit::record('cv_link_issued', $application);

        return [
            'url' => URL::temporarySignedRoute('hr.applications.cv', $expiresAt, ['application' => $application->id]),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function response(Application $application, Request $request): Response
    {
        $contents = Crypt::decryptString($this->disk()->get($application->cv_path));

        return new Response($contents, 200, [
            'Content-Type' => $application->cv_mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $application->cv_name,
                Str::ascii($application->cv_name) ?: 'cv',
            ),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            $this->disk()->delete($path);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('hr.documents.disk'));
    }
}
