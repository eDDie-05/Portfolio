<?php

namespace Tests\Feature;

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_upload_a_private_document_and_only_the_employee_can_download_shared_files(): void
    {
        Storage::fake('local');
        $administrator = $this->user('documents.admin@example.test', 'system_admin');
        $employee = $this->user('documents.employee@example.test', 'employee', 'DOC-101');
        $otherEmployee = $this->user('documents.other@example.test', 'employee', 'DOC-102');

        $this->actingAs($administrator)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'Identity document',
            'document' => UploadedFile::fake()->image('identity.png', 20, 20),
            'expires_on' => now()->addYear()->toDateString(),
            'employee_visible' => '1',
        ])->assertRedirect('/employees/'.$employee->id.'/documents');

        $document = EmployeeDocument::firstOrFail();
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame(64, strlen($document->sha256));
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_document.uploaded', 'subject_id' => $document->id]);

        $this->actingAs($employee)->get('/my-documents')->assertOk()->assertSee('Identity document')->assertSee('identity.png');
        $this->get('/my-documents/'.$document->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_document.downloaded', 'subject_id' => $document->id]);
        $this->actingAs($otherEmployee)->get('/my-documents/'.$document->id)->assertNotFound();

        $this->actingAs($administrator)->get('/employees/'.$employee->id.'/documents/'.$document->id)->assertOk();
    }

    public function test_private_documents_are_hidden_from_employees_and_archiving_preserves_the_file(): void
    {
        Storage::fake('local');
        $administrator = $this->user('documents.admin@example.test', 'system_admin');
        $employee = $this->user('documents.employee@example.test', 'employee', 'DOC-201');

        $this->actingAs($administrator)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'Employment contract',
            'document' => UploadedFile::fake()->image('contract.png', 20, 20),
        ])->assertRedirect();

        $document = EmployeeDocument::firstOrFail();
        $this->actingAs($employee)->get('/my-documents')->assertOk()->assertDontSee('Employment contract');
        $this->get('/my-documents/'.$document->id)->assertNotFound();

        $this->actingAs($administrator)->post('/employees/'.$employee->id.'/documents/'.$document->id.'/archive')->assertRedirect();
        $this->assertNotNull($document->fresh()->archived_at);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->actingAs($employee)->get('/my-documents/'.$document->id)->assertNotFound();
    }

    public function test_hr_manager_document_access_is_limited_to_direct_reports_and_upload_types_are_validated(): void
    {
        Storage::fake('local');
        $manager = $this->user('documents.manager@example.test', 'hr_manager');
        $managedEmployee = $this->user('documents.managed@example.test', 'employee', 'DOC-301');
        $otherEmployee = $this->user('documents.other@example.test', 'employee', 'DOC-302');
        $managedEmployee->forceFill(['manager_id' => $manager->id])->save();

        $this->actingAs($manager)->get('/employees/'.$otherEmployee->id.'/documents')->assertForbidden();
        $this->get('/employees/'.$managedEmployee->id.'/documents')->assertOk();
        $this->post('/employees/'.$managedEmployee->id.'/documents', [
            'category' => 'Unsupported executable',
            'document' => UploadedFile::fake()->create('malware.exe', 20, 'application/octet-stream'),
        ])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('employee_documents', 0);
    }

    private function user(string $email, string $role, ?string $employeeNumber = null): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Test '.ucfirst($role),
            'email' => $email,
            'password' => Hash::make('SecureStartPassword123!'),
            'role' => $role,
            'employee_no' => $employeeNumber,
            'is_active' => true,
        ])->save();

        return $user;
    }
}
