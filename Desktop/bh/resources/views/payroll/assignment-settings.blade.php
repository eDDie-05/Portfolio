@extends('layouts.app', ['title' => 'Salary grade assignments'])
@section('crumb', 'Finance / Payroll / Salary assignments')
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div><div class="eyebrow">PAYROLL / COMPENSATION</div><h1>Salary grade and step</h1><p>Record the approved grade, scale, and step with the effective-dated salary assignment.</p></div>
        <a class="button button-light" href="{{ route('payroll.index') }}">Back to payroll</a>
    </div>
    @if($errors->any())<div class="flash warning" role="alert">{{ $errors->first() }}</div>@endif
    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
    <section class="candidate-grid">
        @foreach($employees as $employee)
            <article class="panel events-panel">
                <div class="panel-heading"><div><h2>{{ $employee->name }}</h2><p>{{ $employee->employee_no }}</p></div></div>
                <form class="device-form" method="post" action="{{ route('payroll.assignments.store') }}">
                    @csrf
                    <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                    <div class="form-grid">
                        <label>Salary structure<select name="salary_structure_id" required><option value="">Choose structure</option>@foreach($activeStructures as $structure)<option value="{{ $structure->id }}">{{ $structure->name }} · {{ $structure->currency }} {{ number_format((float) $structure->basic_salary, 2) }}</option>@endforeach</select></label>
                        <label>Effective from<input type="date" name="effective_from" required></label>
                        <label>Grade<input name="salary_grade" maxlength="60"></label>
                        <label>Scale<input name="salary_scale" maxlength="60"></label>
                        <label>Step<input name="salary_step" maxlength="60"></label>
                        <label>Reason / approval reference<input name="change_reason" maxlength="1000" required></label>
                    </div>
                    <div class="form-actions"><button class="button button-primary">Assign approved salary</button></div>
                </form>
            </article>
        @endforeach
    </section>
    {{ $employees->links() }}
</div>
@endsection
