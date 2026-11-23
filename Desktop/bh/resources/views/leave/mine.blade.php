@extends('layouts.app', ['title' => 'My leave'])
@section('crumb', 'My leave')
@section('content')
<div class="page-wrap">
    <div class="page-heading"><div><div class="eyebrow">PEOPLE / SELF SERVICE</div><h1>My leave</h1><p>Request time away and track the review and balance for your leave.</p></div>@if(in_array(auth()->user()->role, ['system_admin', 'hr_director'], true))<a class="button button-light" href="{{ route('leave.settings') }}">Leave settings</a>@endif</div>

    <div class="leave-grid">
        <section class="panel events-panel"><div class="panel-heading"><div><h2>Request leave</h2><p>Requests use calendar days. Any applicable working-day rules must be configured by HR separately.</p></div></div>
            <form class="device-form" method="post" enctype="multipart/form-data" action="{{ route('leave.store') }}">@csrf
                <div class="form-grid">
                    <label>Leave type<select name="leave_type_id" required><option value="">Select leave type</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected(old('leave_type_id') == $type->id)>{{ $type->name }}{{ $type->deducts_balance ? ' · balance required' : ' · no balance deduction' }}</option>@endforeach</select>@error('leave_type_id')<small class="form-error">{{ $message }}</small>@enderror</label>
                    <label>Start date<input type="date" name="start_date" min="{{ now()->toDateString() }}" value="{{ old('start_date') }}" required>@error('start_date')<small class="form-error">{{ $message }}</small>@enderror</label>
                    <label>End date<input type="date" name="end_date" min="{{ now()->toDateString() }}" value="{{ old('end_date') }}" required>@error('end_date')<small class="form-error">{{ $message }}</small>@enderror</label>
                    <label class="wide-field">Reason<textarea name="reason" rows="3" maxlength="2000" required>{{ old('reason') }}</textarea>@error('reason')<small class="form-error">{{ $message }}</small>@enderror</label>
                    <label class="wide-field">Supporting attachment <span class="muted">Optional PDF, image or Word document, maximum 10 MB.</span><input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">@error('attachment')<small class="form-error">{{ $message }}</small>@enderror</label>
                </div>
                <div class="form-actions"><button class="button button-primary" type="submit" @disabled($types->isEmpty())>Submit request</button></div>
                @if($types->isEmpty())<p class="muted-note">No active leave types are configured yet. Contact HR.</p>@endif
            </form>
        </section>

        <section class="panel events-panel"><div class="panel-heading"><div><h2>{{ $year }} balances</h2><p>HR allocates entitlements based on your organization's approved policy.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>LEAVE TYPE</th><th>ALLOCATED</th><th>USED</th><th>AVAILABLE</th></tr></thead><tbody>
                @forelse($balances as $balance)<tr><td>{{ $balance->leaveType->name }}</td><td>{{ number_format((float) $balance->allocated_days, 2) }}</td><td>{{ number_format((float) $balance->used_days, 2) }}</td><td>{{ number_format((float) $balance->allocated_days - (float) $balance->used_days, 2) }}</td></tr>@empty<tr><td colspan="4"><div class="table-empty"><strong>No balances recorded for {{ $year }}</strong><span>Unpaid or non-balance leave types may not require a balance.</span></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>

    <section class="panel events-panel"><div class="panel-heading"><div><h2>Request history</h2><p>Review notes and the responsible approver are retained with each decision.</p></div><span class="table-count">{{ $requests->total() }} requests</span></div>
        <div class="table-wrap"><table><thead><tr><th>LEAVE</th><th>DATES</th><th>DAYS</th><th>STATUS</th><th>REVIEW</th><th></th></tr></thead><tbody>
            @forelse($requests as $leaveRequest)<tr><td><strong>{{ $leaveRequest->leaveType->name }}</strong><small>{{ $leaveRequest->reason }}</small></td><td>{{ $leaveRequest->start_date->format('M j, Y') }} – {{ $leaveRequest->end_date->format('M j, Y') }}</td><td>{{ number_format((float) $leaveRequest->requested_days, 2) }}</td><td><span class="status-pill {{ $leaveRequest->status === 'approved' ? 'ok' : ($leaveRequest->status === 'pending' ? 'pending' : 'off') }}"><i></i>{{ ucfirst($leaveRequest->status) }}</span></td><td>@if($leaveRequest->reviewer){{ $leaveRequest->reviewer->name }}@endif{{ $leaveRequest->review_note ? ' · '.$leaveRequest->review_note : '' }}</td><td>@if(in_array($leaveRequest->status, ['pending', 'approved'], true) && ($leaveRequest->status === 'pending' || $leaveRequest->start_date->toDateString() >= now()->toDateString()))<form method="post" action="{{ route('leave.cancel', $leaveRequest) }}" onsubmit="return confirm('Cancel this leave request?')">@csrf<button class="button button-light button-small" type="submit">Cancel</button></form>@endif</td></tr>@empty<tr><td colspan="6"><div class="table-empty"><strong>No leave requests yet</strong><span>Your applications will appear here.</span></div></td></tr>@endforelse
        </tbody></table></div><div class="pagination">{{ $requests->links() }}</div>
    </section>
</div>
@endsection
