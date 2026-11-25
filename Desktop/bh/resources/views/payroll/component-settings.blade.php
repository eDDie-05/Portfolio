@extends('layouts.app', ['title' => 'Salary component settings'])
@section('crumb', 'Finance / Payroll / Components')
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div><div class="eyebrow">PAYROLL / CONFIGURATION</div><h1>Salary component treatment</h1><p>Set component codes, pensionability, recurrence, and effective dates. Payroll preserves the applied values in its immutable entry lines.</p></div>
        <a class="button button-light" href="{{ route('payroll.index') }}">Back to payroll</a>
    </div>
    @if($errors->any())<div class="flash warning" role="alert">{{ $errors->first() }}</div>@endif
    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @foreach($structures as $structure)
        <section class="panel events-panel">
            <div class="panel-heading"><div><h2>{{ $structure->name }}</h2><p>{{ $structure->country_code }} · {{ $structure->currency }} · {{ ucfirst($structure->frequency) }}</p></div></div>
            <div class="interview-list">
                @forelse($structure->components as $component)
                    <article class="interview-card">
                        <strong>{{ $component->name }} · {{ ucfirst($component->component_type) }}</strong>
                        <form class="device-form" method="post" action="{{ route('payroll.components.settings.update', $component) }}">
                            @csrf
                            @method('PUT')
                            <div class="form-grid">
                                <label>Component code<input name="code" maxlength="80" value="{{ $component->code }}"></label>
                                <label>Taxable<select name="is_taxable" required><option value="0" @selected(! $component->is_taxable)>No</option><option value="1" @selected($component->is_taxable)>Yes</option></select></label>
                                <label>Pensionable<select name="is_pensionable" required><option value="0" @selected(! $component->is_pensionable)>No</option><option value="1" @selected($component->is_pensionable)>Yes</option></select></label>
                                <label>Statutory<select name="is_statutory" required><option value="0" @selected(! $component->is_statutory)>No</option><option value="1" @selected($component->is_statutory)>Yes</option></select></label>
                                <label>Recurring<select name="is_recurring" required><option value="0" @selected(! $component->is_recurring)>No</option><option value="1" @selected($component->is_recurring)>Yes</option></select></label>
                                <label>Effective from<input type="date" name="effective_from" value="{{ $component->effective_from?->toDateString() }}"></label>
                                <label>Effective through<input type="date" name="effective_to" value="{{ $component->effective_to?->toDateString() }}"></label>
                            </div>
                            <div class="form-actions"><button class="button button-primary">Save treatment</button></div>
                        </form>
                    </article>
                @empty
                    <div class="table-empty">No components have been added to this structure.</div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
@endsection
