@extends('layouts.app', ['title' => 'Leave approvals'])
@section('crumb', 'Leave approvals')
@section('content')
<div class="page-wrap">
    <div class="page-heading"><div><div class="eyebrow">PEOPLE / APPROVALS</div><h1>Leave approvals</h1><p>Review pending requests from your authorized team. Decisions are recorded in the audit history.</p></div></div>
    <section class="panel events-panel"><div class="panel-heading"><div><h2>Pending requests</h2><p>Balance-deducting requests are rechecked against the current allocation when approved.</p></div><span class="table-count">{{ $requests->total() }} pending</span></div>
        <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>LEAVE</th><th>DATES</th><th>DAYS</th><th>REASON</th><th>DECISION</th></tr></thead><tbody>
            @forelse($requests as $leaveRequest)<tr><td><strong>{{ $leaveRequest->employee->name }}</strong><small>{{ $leaveRequest->employee->employee_no }} · {{ $leaveRequest->employee->branch?->name ?? 'No branch' }}</small></td><td>{{ $leaveRequest->leaveType->name }}</td><td>{{ $leaveRequest->start_date->format('M j, Y') }} – {{ $leaveRequest->end_date->format('M j, Y') }}</td><td>{{ number_format((float) $leaveRequest->requested_days, 2) }}</td><td>{{ $leaveRequest->reason }}</td><td><form class="leave-decision" method="post" action="{{ route('leave.review', [$leaveRequest, 'approve']) }}">@csrf<label class="sr-only" for="note-{{ $leaveRequest->id }}">Review note</label><input id="note-{{ $leaveRequest->id }}" name="review_note" maxlength="2000" placeholder="Optional note"><button class="button button-primary button-small" formaction="{{ route('leave.review', [$leaveRequest, 'approve']) }}">Approve</button><button class="button button-light button-small" formaction="{{ route('leave.review', [$leaveRequest, 'reject']) }}">Reject</button></form></td></tr>@empty<tr><td colspan="6"><div class="table-empty"><strong>No requests awaiting your review</strong><span>New team applications will appear here.</span></div></td></tr>@endforelse
        </tbody></table></div><div class="pagination">{{ $requests->links() }}</div>
    </section>
</div>
@endsection
