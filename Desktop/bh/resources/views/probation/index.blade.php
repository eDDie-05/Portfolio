@extends('layouts.app', ['title' => 'Probation reviews'])
@section('crumb', 'People / Probation')
@section('content')
<div class="page-wrap probation-page">
    <div class="page-heading probation-heading">
        <div>
            <div class="eyebrow">PEOPLE / EMPLOYMENT REVIEWS</div>
            <h1>Probation reviews</h1>
            <p>Track upcoming decisions, capture review notes, and keep each employee's timeline current.</p>
        </div>
        <span class="probation-heading-note"><span class="live-dot"></span> Active employee reviews</span>
    </div>

    <div class="stat-grid probation-stats">
        <article class="stat-card probation-stat-card">
            <div class="stat-top"><span>Overdue</span><span class="stat-icon amber" aria-hidden="true">!</span></div>
            <div class="stat-value" data-count-up="{{ $overdueCount }}">{{ $overdueCount }}</div>
            <div class="stat-foot">Past the recorded end date</div>
        </article>
        <article class="stat-card probation-stat-card">
            <div class="stat-top"><span>Due in 30 days</span><span class="stat-icon blue" aria-hidden="true">◷</span></div>
            <div class="stat-value" data-count-up="{{ $dueSoonCount }}">{{ $dueSoonCount }}</div>
            <div class="stat-foot">Upcoming review dates</div>
        </article>
        <article class="stat-card probation-stat-card">
            <div class="stat-top"><span>Tracked employees</span><span class="stat-icon indigo" aria-hidden="true">♙</span></div>
            <div class="stat-value" data-count-up="{{ $employees->total() }}">{{ $employees->total() }}</div>
            <div class="stat-foot">Within your access scope</div>
        </article>
    </div>

    <section class="panel events-panel probation-panel">
        <div class="panel-heading">
            <div>
                <h2>Employee reviews</h2>
                <p>Extensions require a future date later than the current probation end.</p>
            </div>
            <span class="table-count">{{ $employees->total() }} tracked</span>
        </div>

        <div class="probation-toolbar">
            <label class="probation-search">
                <span>Search this page</span>
                <input type="search" data-probation-search aria-label="Search current probation page" placeholder="Name, employee number, department">
            </label>
            <label class="probation-status-filter">
                <span>Review status</span>
                <select class="filter-select" data-probation-status aria-label="Filter by review status">
                    <option value="all">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="in_review">In review</option>
                    <option value="extended">Extended</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="not_confirmed">Not confirmed</option>
                </select>
            </label>
            <p class="probation-results" data-probation-results role="status" aria-live="polite">
                Showing {{ $employees->count() }} of {{ $employees->total() }} employees on this page
            </p>
        </div>

        <div class="table-wrap probation-table-wrap">
            <table class="probation-table" data-probation-table>
                <thead>
                    <tr>
                        <th>EMPLOYEE</th>
                        <th>DEPARTMENT</th>
                        <th>PROBATION ENDS</th>
                        <th>REVIEW STATUS</th>
                        <th>LAST REVIEW</th>
                        <th>REVIEW ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $employee)
                        <tr
                            data-probation-row
                            data-review-status="{{ $employee->probation_review_status ?? 'pending' }}"
                            data-search-text="{{ $employee->name }} {{ $employee->employee_no }} {{ $employee->department }} {{ $employee->job_title }}"
                        >
                            <td class="probation-employee">
                                <strong>{{ $employee->name }}</strong>
                                <small>{{ $employee->employee_no }} · {{ $employee->job_title ?: 'Job title not set' }}</small>
                            </td>
                            <td>{{ $employee->department ?: '—' }}</td>
                            <td>
                                <strong>{{ $employee->probation_ends_on->format('M j, Y') }}</strong>
                                <small>{{ $employee->probation_days_remaining < 0 ? abs($employee->probation_days_remaining).' days overdue' : ($employee->probation_days_remaining === 0 ? 'Due today' : $employee->probation_days_remaining.' days remaining') }}</small>
                            </td>
                            <td class="probation-review-status">
                                <span class="status-pill {{ in_array($employee->probation_review_status, ['confirmed'], true) ? 'ok' : (in_array($employee->probation_review_status, ['not_confirmed'], true) ? 'off' : 'pending') }}">
                                    <i></i>{{ ucfirst(str_replace('_', ' ', $employee->probation_review_status ?? 'pending')) }}
                                </span>
                                @if($employee->probation_review_note)
                                    <small>{{ $employee->probation_review_note }}</small>
                                @endif
                            </td>
                            <td>
                                {{ $employee->probation_reviewed_at?->format('M j, Y') ?? 'Not reviewed' }}
                                <small>{{ $employee->probationReviewer?->name ?? 'No reviewer recorded' }}</small>
                            </td>
                            <td class="probation-review-action">
                                @if(!in_array($employee->probation_review_status, ['confirmed', 'not_confirmed'], true))
                                    <details
                                        class="probation-review"
                                        data-probation-review-form
                                        @if((int) old('review_employee_id') === $employee->id) open @endif
                                    >
                                        <summary class="button button-light button-small">Record review</summary>
                                        <form class="feedback-form probation-review-form" method="post" action="{{ route('probation.review', $employee) }}">
                                            @csrf
                                            <input type="hidden" name="review_employee_id" value="{{ $employee->id }}">
                                            <label>
                                                Decision
                                                <select name="decision" required>
                                                    <option value="in_review" @selected(old('decision') === 'in_review')>In review</option>
                                                    <option value="confirmed" @selected(old('decision') === 'confirmed')>Confirm employment</option>
                                                    <option value="extended" @selected(old('decision') === 'extended')>Extend probation</option>
                                                    <option value="not_confirmed" @selected(old('decision') === 'not_confirmed')>Do not confirm</option>
                                                </select>
                                                @error('decision')
                                                    <small class="form-error" role="alert">{{ $message }}</small>
                                                @enderror
                                            </label>
                                            <label>
                                                Review note
                                                <textarea name="review_note" rows="3" maxlength="3000" required>{{ old('review_note') }}</textarea>
                                                @error('review_note')
                                                    <small class="form-error" role="alert">{{ $message }}</small>
                                                @enderror
                                            </label>
                                            <label>
                                                New probation end date
                                                <input type="date" name="new_probation_ends_on" value="{{ old('new_probation_ends_on') }}" min="{{ $employee->probation_ends_on->addDay()->toDateString() }}">
                                                @error('new_probation_ends_on')
                                                    <small class="form-error" role="alert">{{ $message }}</small>
                                                @enderror
                                            </label>
                                            <button class="button button-primary" type="submit">Save review</button>
                                        </form>
                                    </details>
                                @else
                                    <span class="probation-closed">Review closed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="table-empty">
                                    <strong>No probation dates found</strong>
                                    <span>Add a probation end date to an active employee profile to track a review.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    <tr data-probation-empty-filter hidden>
                        <td colspan="6">
                            <div class="table-empty">
                                <strong>No reviews match these filters</strong>
                                <span>Try a different name or review status.</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="probation-pagination">{{ $employees->links() }}</div>
    </section>
</div>
@endsection
