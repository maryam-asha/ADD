<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentLinkTest extends TestCase
{
    use HiresEmployees;

    private int $documentId;

    private string $contents = "%PDF-1.4\nbinary \x00\x01\x02 payload";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAsWith([Abilities::DOCUMENTS_VIEW, Abilities::DOCUMENTS_MANAGE]);

        $employee = $this->hireEmployee();
        $this->documentId = $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => DocumentType::factory()->create()->id,
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', $this->contents),
        ])->json('data.id');
    }

    private function link(): string
    {
        return $this->postJson("/api/hr/documents/{$this->documentId}/link")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']])
            ->json('data.url');
    }

    public function test_link_is_signed_and_download_returns_original_bytes_and_logs_both_actions(): void
    {
        $viewer = $this->actingAsWith([Abilities::DOCUMENTS_VIEW]);
        $url = $this->link();

        $this->assertStringContainsString('signature=', $url);

        auth()->logout();
        $response = $this->get($url)->assertOk();

        $this->assertSame($this->contents, $response->getContent());
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertDatabaseHas('hr_document_accesses', ['document_id' => $this->documentId, 'action' => 'link_issued', 'user_id' => $viewer->id]);
        $this->assertDatabaseHas('hr_document_accesses', ['document_id' => $this->documentId, 'action' => 'downloaded']);
    }

    public function test_expired_link_is_forbidden(): void
    {
        $url = $this->link();

        $this->travel(6)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_tampered_signature_is_forbidden(): void
    {
        $url = $this->link();

        $this->get(preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $url))->assertForbidden();
        $this->get(str_replace("/documents/{$this->documentId}/", '/documents/999/', $url))->assertForbidden();
    }

    public function test_linking_needs_the_view_ability(): void
    {
        $this->actingAsWith([]);

        $this->postJson("/api/hr/documents/{$this->documentId}/link")->assertForbidden();
    }
}
