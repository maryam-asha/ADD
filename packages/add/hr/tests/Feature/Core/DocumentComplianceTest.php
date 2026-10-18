<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Services\DocumentCompliance;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentComplianceTest extends TestCase
{
    use HiresEmployees;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAsWith([Abilities::DOCUMENTS_VIEW, Abilities::DOCUMENTS_MANAGE]);
    }

    private function upload(int $employeeId, DocumentType $type, ?string $expiresOn = null): void
    {
        $this->postJson("/api/hr/employees/{$employeeId}/documents", array_filter([
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'expires_on' => $expiresOn,
        ]))->assertCreated();
    }

    public function test_compliance_lists_missing_required_types_for_contract_type_and_expired_documents(): void
    {
        $idCard = DocumentType::factory()->create(['code' => 'id_card', 'is_required' => true]);
        $permit = DocumentType::factory()->create(['code' => 'permit', 'is_required' => true, 'has_expiry' => true]);
        $internOnly = DocumentType::factory()->create(['code' => 'school', 'is_required' => true, 'contract_types' => ['internship']]);
        DocumentType::factory()->create(['code' => 'optional', 'is_required' => false]);
        DocumentType::factory()->create(['code' => 'retired', 'is_required' => true, 'is_active' => false]);

        $employee = $this->hireEmployee(['contract' => ['type' => 'open_ended']]);

        $compliance = app(DocumentCompliance::class)->for($employee);
        $this->assertEqualsCanonicalizing(['id_card', 'permit'], collect($compliance['missing'])->pluck('code')->all());
        $this->assertFalse($compliance['complete']);

        $this->upload($employee->id, $idCard);
        $this->upload($employee->id, $permit, now()->addDay()->toDateString());
        $this->assertTrue(app(DocumentCompliance::class)->for($employee)['complete']);

        $this->travel(2)->days();
        $compliance = app(DocumentCompliance::class)->for($employee);
        $this->assertSame([], $compliance['missing']);
        $this->assertSame(['permit'], collect($compliance['expired'])->map(fn ($d) => $d->documentType->code)->all());
        $this->assertFalse($compliance['complete']);

        $this->assertNotContains($internOnly->code, collect($compliance['missing'])->pluck('code')->all());
    }

    public function test_a_newer_upload_replaces_an_expired_one(): void
    {
        $permit = DocumentType::factory()->create(['code' => 'permit', 'is_required' => true, 'has_expiry' => true]);
        $employee = $this->hireEmployee();
        $this->upload($employee->id, $permit, now()->addDay()->toDateString());

        $this->travel(2)->days();
        $this->upload($employee->id, $permit, now()->addYear()->toDateString());

        $this->assertTrue(app(DocumentCompliance::class)->for($employee)['complete']);
    }

    public function test_documents_index_carries_the_compliance_summary(): void
    {
        DocumentType::factory()->create(['code' => 'id_card', 'is_required' => true]);
        $employee = $this->hireEmployee();

        $this->getJson("/api/hr/employees/{$employee->id}/documents")
            ->assertOk()
            ->assertJsonPath('compliance.complete', false)
            ->assertJsonPath('compliance.missing.0.code', 'id_card');
    }
}
