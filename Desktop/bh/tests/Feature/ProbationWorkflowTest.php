<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProbationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_extend_probation_and_audited_review_data_updates_the_employee_record(): void
    {
        $administrator = $this->user('probation.admin@example.test', 'system_admin');
        $employee = $this->user('probation.employee@example.test', 'employee', 'PRB-101');
        $originalEndDate = now()->subDays(2)->toDateString();
        $employee->forceFill(['probation_ends_on' => $originalEndDate])->save();

        $this->actingAs($administrator)->get('/probation')
            ->assertOk()
            ->assertSee('Probation reviews')
            ->assertSee('Probation Employee')
            ->assertSee('2 days overdue')
            ->assertSee('data-probation-search', false)
            ->assertSee('Log out');
        $this->post('/probation/'.$employee->id.'/review', [
            'decision' => 'extended',
            'review_note' => 'Additional coaching period required.',
            'new_probation_ends_on' => now()->addDays(30)->toDateString(),
        ])->assertRedirect();

        $employee->refresh();
        $this->assertSame('extended', $employee->probation_review_status);
        $this->assertSame(now()->addDays(30)->toDateString(), $employee->probation_ends_on->toDateString());
        $this->assertSame($administrator->id, $employee->probation_reviewed_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.probation_reviewed',
            'subject_id' => $employee->id,
        ]);

        $this->post('/probation/'.$employee->id.'/review', [
            'decision' => 'confirmed',
            'review_note' => 'Successfully completed the extended period.',
        ])->assertRedirect();
        $this->assertSame('confirmed', $employee->fresh()->probation_review_status);
        $this->post('/probation/'.$employee->id.'/review', [
            'decision' => 'extended',
            'review_note' => 'Should be closed.',
            'new_probation_ends_on' => now()->addDays(60)->toDateString(),
        ])->assertSessionHasErrors('decision');
    }

    public function test_extensions_require_a_valid_later_end_date_and_hr_managers_only_review_direct_reports(): void
    {
        $manager = $this->user('probation.manager@example.test', 'hr_manager');
        $report = $this->user('probation.report@example.test', 'employee', 'PRB-201');
        $otherEmployee = $this->user('probation.other@example.test', 'employee', 'PRB-202');
        $probationEndDate = now()->addDays(10)->toDateString();
        $report->forceFill(['probation_ends_on' => $probationEndDate, 'manager_id' => $manager->id])->save();
        $otherEmployee->forceFill(['probation_ends_on' => $probationEndDate])->save();

        $this->actingAs($manager)->get('/probation')
            ->assertOk()
            ->assertSee('PRB-201')
            ->assertDontSee('PRB-202')
            ->assertSee('Log out');
        $this->post('/probation/'.$otherEmployee->id.'/review', [
            'decision' => 'confirmed',
            'review_note' => 'Not in scope.',
        ])->assertForbidden();
        $this->from('/probation')->actingAs($manager)->post('/probation/'.$report->id.'/review', [
            'decision' => 'extended',
            'review_note' => 'Extension date is unchanged.',
            'new_probation_ends_on' => $probationEndDate,
            'review_employee_id' => $report->id,
        ])->assertRedirect('/probation')->assertSessionHasErrors('new_probation_ends_on');
        $reviewPage = $this->get('/probation')->assertOk()->assertSee('Extension date is unchanged.');
        $this->assertMatchesRegularExpression(
            '/<details[^>]*data-probation-review-form[^>]*\bopen\b/s',
            $reviewPage->getContent()
        );
        $this->assertSame('pending', $report->fresh()->probation_review_status);
        $this->actingAs($report)->get('/probation')->assertForbidden();
    }

    private function user(string $email, string $role, ?string $employeeNumber = null): User
    {
        $user = new User;
        $user->forceFill([
            'name' => str($email)->before('@')->replace('.', ' ')->title()->toString(),
            'email' => $email,
            'employee_no' => $employeeNumber,
            'password' => Hash::make('Secure-password-123!'),
            'role' => $role,
            'is_active' => true,
        ])->save();

        return $user;
    }
}
