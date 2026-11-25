@extends('layouts.app', ['title' => 'My payslips'])
@section('crumb', 'My work / Payslips')
@section('content')
<div class="page-wrap"><div class="page-heading"><div><div class="eyebrow">MY WORK / PAYROLL</div><h1>My payslips</h1><p>Only approved payroll periods are shown.</p></div></div><section class="panel"><div class="table-wrap"><table><thead><tr><th>PERIOD</th><th>PAY DATE</th><th>CURRENCY</th><th>GROSS</th><th>DEDUCTIONS</th><th>NET</th><th></th></tr></thead><tbody>@forelse($entries as $entry)<tr><td>{{ $entry->payrollPeriod->period_key }}</td><td>{{ $entry->payrollPeriod->pay_on->format('M j, Y') }}</td><td>{{ $entry->currency }}</td><td>{{ number_format((float) $entry->gross_amount, 2) }}</td><td>{{ number_format((float) $entry->deductions_amount, 2) }}</td><td>{{ number_format((float) $entry->net_amount, 2) }}</td><td><a class="button button-light button-small" href="{{ route('payroll.payslip.mine', $entry) }}">View payslip</a></td></tr>@empty<tr><td colspan="7" class="table-empty">Approved payslips will appear here.</td></tr>@endforelse</tbody></table></div>{{ $entries->links() }}</section></div>
<section class="candidate-grid">
    <article class="panel events-panel">
        <div class="panel-heading"><div><h2>Submit overtime</h2><p>Claims require an independent review and a configured rule before they enter payroll.</p></div></div>
        @if($errors->any())<div class="flash warning" role="alert">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
        <form class="device-form" method="post" action="{{ route('payroll.overtime.store') }}">
            @csrf
            <div class="form-grid">
                <label>Date worked<input type="date" name="worked_on" max="{{ now()->toDateString() }}" required></label>
                <label>Category<select name="overtime_type" required><option value="normal">Normal</option><option value="weekend">Weekend</option><option value="holiday">Holiday</option></select></label>
                <label>Hours<input type="number" name="hours" min="0.01" max="24" step="0.01" required></label>
                <label>Reason<input name="reason" maxlength="1000" required></label>
            </div>
            <div class="form-actions"><button class="button button-primary">Submit overtime</button></div>
        </form>
        <div class="interview-list">
            @foreach($overtimeRequests as $overtime)
                <article class="interview-card"><strong>{{ $overtime->worked_on->format('M j, Y') }} · {{ ucfirst($overtime->overtime_type) }}</strong><span>{{ $overtime->hours }} hours · {{ ucfirst($overtime->status) }}{{ $overtime->review_note ? ' · '.$overtime->review_note : '' }}</span></article>
            @endforeach
        </div>
        {{ $overtimeRequests->links() }}
    </article>
    <article class="panel events-panel">
        <div class="panel-heading"><div><h2>Request a loan or salary advance</h2><p>A request is not a loan agreement. Nothing is deducted until an authorized reviewer approves documented repayment terms.</p></div></div>
        <form class="device-form" method="post" action="{{ route('payroll.loans.store') }}">
            @csrf
            <div class="form-grid">
                <label>Request type<select name="loan_type" required><option value="advance">Salary advance</option><option value="loan">Employee loan</option></select></label>
                <label>Requested amount<input type="number" name="principal_amount" min="0.01" step="0.01" required></label>
                <label>Reason<input name="reason" maxlength="1000" required></label>
            </div>
            <div class="form-actions"><button class="button button-primary">Submit request</button></div>
        </form>
        <div class="interview-list">
            @foreach($loans as $loan)
                <article class="interview-card"><strong>{{ ucfirst($loan->loan_type) }} · {{ $loan->currency }} {{ number_format((float) $loan->principal_amount, 2) }}</strong><span>{{ ucfirst($loan->status) }}@if($loan->status === 'active') · Remaining {{ $loan->currency }} {{ number_format((float) $loan->remainingBalance(), 2) }} · {{ $loan->terms_reference }}@endif</span></article>
            @endforeach
        </div>
        {{ $loans->links() }}
    </article>
</section>
@endsection
