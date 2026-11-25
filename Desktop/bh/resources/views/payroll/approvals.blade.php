@extends('layouts.app', ['title' => 'Payroll requests'])
@section('crumb', 'Finance / Payroll / Requests')
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div>
            <div class="eyebrow">PAYROLL / REVIEW</div>
            <h1>Overtime and loan approvals</h1>
            <p>Only approved overtime enters payroll. Loan deductions start only after documented terms are approved and disbursement is recorded.</p>
        </div>
        <a class="button button-light" href="{{ route('payroll.index') }}">Back to payroll</a>
    </div>
    @if($errors->any())<div class="flash warning" role="alert">{{ $errors->first() }}</div>@endif
    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @if($canReviewOvertime)
    <section class="panel events-panel">
        <div class="panel-heading"><div><h2>Overtime awaiting review</h2><p>Review dates, claimed hours, and employee explanations before approving.</p></div></div>
        <div class="interview-list">
            @forelse($overtimeRequests as $overtime)
                <article class="interview-card">
                    <strong>{{ $overtime->employee->name }} · {{ $overtime->worked_on->format('M j, Y') }}</strong>
                    <span>{{ ucfirst($overtime->overtime_type) }} · {{ $overtime->hours }} hours · {{ $overtime->reason }}</span>
                    <form class="device-form" method="post" action="{{ route('payroll.overtime.review', $overtime) }}">
                        @csrf
                        <div class="form-grid">
                            <label>Review note<input name="review_note" maxlength="1000"></label>
                        </div>
                        <div class="form-actions">
                            <button class="button button-primary" name="decision" value="approve">Approve</button>
                            <button class="button button-light" name="decision" value="reject">Reject</button>
                        </div>
                    </form>
                </article>
            @empty
                <div class="table-empty">No overtime requests await review.</div>
            @endforelse
        </div>
        {{ $overtimeRequests->links() }}
    </section>
    @endif
    @if($canReviewLoans)
    <section class="panel events-panel">
        <div class="panel-heading"><div><h2>Loans and advances awaiting review</h2><p>Set the approved total, installment schedule, first repayment date, and terms reference before recording disbursement.</p></div></div>
        <div class="interview-list">
            @forelse($loanRequests as $loan)
                <article class="interview-card">
                    <strong>{{ $loan->employee->name }} · {{ ucfirst($loan->loan_type) }}</strong>
                    <span>Requested {{ $loan->currency }} {{ number_format((float) $loan->principal_amount, 2) }} · {{ $loan->reason }}</span>
                    <form class="device-form" method="post" action="{{ route('payroll.loans.review', $loan) }}">
                        @csrf
                        <div class="form-grid">
                            <label>Approved total repayable<input type="number" name="total_repayable" min="{{ $loan->principal_amount }}" step="0.01"></label>
                            <label>Installment amount<input type="number" name="installment_amount" min="0.01" step="0.01"></label>
                            <label>First repayment date<input type="date" name="repayment_starts_on"></label>
                            <label>Terms / approval reference<input name="terms_reference" maxlength="180"></label>
                            <label>Review note<input name="review_note" maxlength="1000"></label>
                        </div>
                        <div class="form-actions">
                            <button class="button button-primary" name="decision" value="approve">Approve and activate</button>
                            <button class="button button-light" name="decision" value="reject">Reject</button>
                        </div>
                    </form>
                </article>
            @empty
                <div class="table-empty">No loan or advance requests await review.</div>
            @endforelse
        </div>
        {{ $loanRequests->links() }}
    </section>
    @if($canDisburseLoans)
        <section class="panel events-panel">
            <div class="panel-heading"><div><h2>Approved loans awaiting disbursement</h2><p>Record the actual payment reference and date. Repayment does not start until disbursement is confirmed.</p></div></div>
            <div class="interview-list">
                @forelse($loansAwaitingDisbursement as $loan)
                    <article class="interview-card">
                        <strong>{{ $loan->employee->name }} · {{ ucfirst($loan->loan_type) }}</strong>
                        <span>Principal {{ $loan->currency }} {{ number_format((float) $loan->principal_amount, 2) }} · Repayable {{ number_format((float) $loan->total_repayable, 2) }} · Installment {{ number_format((float) $loan->installment_amount, 2) }} · {{ $loan->terms_reference }}</span>
                        <form class="device-form" method="post" action="{{ route('payroll.loans.disburse', $loan) }}">
                            @csrf
                            <div class="form-grid">
                                <label>Disbursement reference<input name="disbursement_reference" maxlength="180" required></label>
                                <label>Actual disbursement date<input type="date" name="disbursed_at" max="{{ now()->toDateString() }}" required></label>
                            </div>
                            <div class="form-actions"><button class="button button-primary">Confirm disbursement</button></div>
                        </form>
                    </article>
                @empty
                    <div class="table-empty">No approved loans await disbursement.</div>
                @endforelse
            </div>
            {{ $loansAwaitingDisbursement->links() }}
        </section>
    @endif
    @endif
</div>
@endsection
