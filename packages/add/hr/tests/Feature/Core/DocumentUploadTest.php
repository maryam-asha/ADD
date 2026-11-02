<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Models\EmployeeDocument;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Concerns\TravelsOnTheOrganisationsClock;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentUploadTest extends TestCase
{
    use HiresEmployees;
    use TravelsOnTheOrganisationsClock;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAsWith([Abilities::DOCUMENTS_VIEW, Abilities::DOCUMENTS_MANAGE, Abilities::SETTINGS_MANAGE]);
    }

    public function test_upload_stores_ciphertext_not_plaintext(): void
    {
        $employee = $this->hireEmployee();
        $type = DocumentType::factory()->create();
        $contents = '%PDF-1.4 secret national id scan';

        $response = $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->createWithContent('id-card.pdf', $contents),
            'issued_on' => '2024-03-01',
        ])->assertCreated()
            ->assertJsonPath('data.original_name', 'id-card.pdf')
            ->assertJsonPath('data.document_type.id', $type->id)
            ->assertJsonMissingPath('data.path');

        $document = EmployeeDocument::query()->findOrFail($response->json('data.id'));
        $stored = Storage::disk('local')->get($document->path);

        $this->assertNotSame($contents, $stored);
        $this->assertStringNotContainsString('secret national id', $stored);
        $this->assertSame(hash('sha256', $contents), $document->checksum);
        $this->assertStringStartsWith('hr/documents/'.$employee->id.'/', $document->path);
    }

    public function test_expiry_required_for_expiring_type(): void
    {
        $employee = $this->hireEmployee();
        $type = DocumentType::factory()->create(['has_expiry' => true]);

        $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('passport.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('expires_on');
    }

    public function test_disallowed_mime_rejected(): void
    {
        $employee = $this->hireEmployee();
        $type = DocumentType::factory()->create();

        $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('macro.docm', 10, 'application/vnd.ms-word.document.macroEnabled.12'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_soft_deleted_document_is_gone_from_the_list_but_kept_on_disk(): void
    {
        $employee = $this->hireEmployee();
        $type = DocumentType::factory()->create();
        $id = $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
        ])->json('data.id');
        $path = EmployeeDocument::query()->findOrFail($id)->path;

        $this->deleteJson("/api/hr/documents/{$id}")->assertNoContent();

        $this->getJson("/api/hr/employees/{$employee->id}/documents")->assertOk()->assertJsonCount(0, 'data');
        Storage::disk('local')->assertExists($path);
        $this->postJson("/api/hr/documents/{$id}/link")->assertNotFound();
    }

    public function test_view_ability_cannot_upload(): void
    {
        $employee = $this->hireEmployee();
        $type = DocumentType::factory()->create();
        $this->actingAsWith([Abilities::DOCUMENTS_VIEW]);

        $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_document_types_crud_and_a_used_type_cannot_be_deleted(): void
    {
        $id = $this->postJson('/api/hr/document-types', [
            'code' => 'passport',
            'name' => ['ar' => 'جواز السفر', 'en' => 'Passport'],
            'is_required' => true,
            'has_expiry' => true,
            'contract_types' => ['fixed_term', 'open_ended'],
        ])->assertCreated()->assertJsonPath('data.contract_types', ['fixed_term', 'open_ended'])->json('data.id');

        $this->patchJson("/api/hr/document-types/{$id}", ['is_required' => false])
            ->assertOk()
            ->assertExactJson(['message' => __('hr::core.document_type_updated')]);

        $employee = $this->hireEmployee();
        $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => $id,
            'file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
            'expires_on' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $this->deleteJson("/api/hr/document-types/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('hr::core.document_type_in_use'));
    }

    public function test_a_document_issued_on_the_organisations_today_is_accepted(): void
    {
        // 01:00 on 11 March locally, still 10 March in UTC.
        $this->travelToLocal('2026-03-11 01:00');
        $employee = $this->hireEmployee();
        $this->actingAsWith([Abilities::DOCUMENTS_MANAGE]);

        $this->postJson("/api/hr/employees/{$employee->id}/documents", [
            'document_type_id' => DocumentType::factory()->create()->id,
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'issued_on' => '2026-03-11',
        ])->assertCreated();
    }
}
