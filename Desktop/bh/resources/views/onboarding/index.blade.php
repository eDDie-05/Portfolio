@extends('layouts.app', ['title' => 'Onboarding'])
@section('crumb', 'People / Onboarding')
@section('content')
<div class="page-wrap"><div class="page-heading"><div><div class="eyebrow">PEOPLE OPERATIONS</div><h1>Onboarding</h1><p>Track new starters and assigned onboarding work.</p></div></div>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>ROLE</th><th>START DATE</th><th>OWNER</th><th>STATUS</th><th>CONTRACT</th><th>TASKS</th><th></th></tr></thead><tbody>@forelse($cases as $case)<tr><td>{{ $case->employee->name }}<small>{{ $case->employee->employee_no }}</small></td><td>{{ $case->application->vacancy->title }}</td><td>{{ $case->start_on->format('M j, Y') }}</td><td>{{ $case->owner->name }}</td><td>{{ ucfirst($case->status) }}</td><td>{{ ucfirst($case->contract?->status ?? 'not issued') }}</td><td>{{ $case->tasks_count }}</td><td><a class="button button-light button-small" href="{{ route('onboarding.show', $case) }}">Open</a></td></tr>@empty<tr><td colspan="8" class="table-empty">No onboarding cases have been started.</td></tr>@endforelse</tbody></table></div>{{ $cases->links() }}</section></div>
@endsection
