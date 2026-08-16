<?php

namespace Tests\Feature\Departments;

use App\Models\BusinessDocument;
use App\Models\Employee;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DepartmentBackfillTest extends DepartmentTestCase
{
    /** The typed-in column stays. Deleting a business's data to tidy a schema is not a migration. */
    public function test_the_original_free_text_column_survives(): void
    {
        $this->assertTrue(Schema::hasColumn('employees', 'department'));
        $this->assertTrue(Schema::hasColumn('employees', 'department_id'));
    }

    public function test_a_document_can_be_filed_under_a_department(): void
    {
        $department = $this->department();
        $paper = $this->paper(['department_id' => $department->id]);

        $this->assertTrue($paper->department->is($department));
    }

    /**
     * Filing is not editing — the rule folder_id already lives under. An
     * issued contract must be fileable into a department without its tamper
     * hash changing, or the module is useless for the documents that most
     * need managing.
     */
    public function test_an_issued_document_can_be_filed_into_a_department(): void
    {
        $department = $this->department();
        $paper = $this->paper();

        // Issued and hashed in one save. The hash covers issued_at, so the
        // timestamp has to be on the model before it is computed — and a
        // second save would be an edit to an already-issued document, which
        // the model is right to refuse.
        $paper->issued_at = now();

        $paper->forceFill([
            'status' => 'issued',
            'content_hash' => hash('sha256', $paper->canonicalPayload()),
        ])->save();

        $paper->fresh()->update(['department_id' => $department->id]);

        $this->assertFalse($paper->fresh()->isTampered());
        $this->assertSame($department->id, $paper->fresh()->department_id);
    }

    public function test_the_department_is_not_part_of_the_tamper_hash(): void
    {
        $this->assertStringNotContainsString('department', $this->paper()->canonicalPayload());
    }

    public function test_a_folder_can_belong_to_a_department(): void
    {
        $this->assertTrue(Schema::hasColumn('business_document_folders', 'department_id'));
    }

    public function test_an_employee_keeps_what_they_typed_when_the_department_goes(): void
    {
        $department = $this->department();
        $employee = Employee::create([
            'first_name' => 'Aïcha',
            'last_name' => 'Njoya',
            'status' => 'active',
            'department' => 'Finance',
            'department_id' => $department->id,
        ]);

        $department->forceDelete();

        $this->assertSame('Finance', $employee->fresh()->department);
        $this->assertNull($employee->fresh()->department_id);
    }

    /** Core, like users and settings — a business that turns HR off keeps its filing. */
    public function test_departments_are_not_a_switchable_module(): void
    {
        $this->assertArrayNotHasKey('departments', config('modules'));
    }

    protected function paper(array $attributes = []): BusinessDocument
    {
        return BusinessDocument::create(array_merge([
            'template' => 'service_agreement',
            'title' => 'Service agreement',
            'reference' => 'DOC-'.Str::upper(Str::random(5)),
            'recipient' => 'Un Client',
            'fields' => ['client_name' => 'Un Client'],
            'body' => 'The agreed terms.',
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ], $attributes));
    }
}
