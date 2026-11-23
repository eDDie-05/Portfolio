@extends('layouts.app', ['title' => 'Branches'])
@section('crumb', 'Branches')
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div>
            <div class="eyebrow">PEOPLE / ORGANIZATION</div>
            <h1>Branches and locations</h1>
            <p>Manage workplace locations and the timezones used for local attendance reporting.</p>
        </div>
        <a class="button button-light" href="{{ route('employees.index') }}">View employees</a>
    </div>

    @if($errors->any())<div class="flash warning">{{ $errors->first() }}</div>@endif

    <section class="panel department-create-panel">
        <div class="panel-heading"><div><h2>Add a branch</h2><p>Use an IANA timezone name, such as Africa/Dar_es_Salaam.</p></div></div>
        <form class="department-create-form" method="post" action="{{ route('branches.store') }}">
            @csrf
            <label>Name<input name="name" required maxlength="255" value="{{ old('name') }}">@error('name')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Timezone<input name="timezone" required maxlength="80" value="{{ old('timezone', 'Africa/Dar_es_Salaam') }}">@error('timezone')<small class="form-error">{{ $message }}</small>@enderror</label>
            <button class="button button-primary" type="submit">Add branch</button>
        </form>
    </section>

    <section class="panel events-panel">
        <div class="panel-heading"><div><h2>Organization locations</h2><p>Deactivate a branch only after moving all assigned records.</p></div><span class="table-count">{{ $branches->count() }} branches</span></div>
        <div class="table-wrap"><table><thead><tr><th>BRANCH</th><th>TIMEZONE</th><th>EMPLOYEES</th><th>DEVICES</th><th>COMPUTERS</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody>
            @forelse($branches as $branch)
                @php($hasAssignments = $branch->employees_count > 0 || $branch->devices_count > 0 || $branch->registered_computers_count > 0)
                <tr>
                    <td colspan="2">
                        <form class="department-edit-form" method="post" action="{{ route('branches.update', $branch) }}">
                            @csrf @method('PUT')
                            <label class="visually-hidden" for="branch-name-{{ $branch->id }}">Branch name</label>
                            <input id="branch-name-{{ $branch->id }}" name="name" value="{{ $branch->name }}" required maxlength="255">
                            <label class="visually-hidden" for="branch-timezone-{{ $branch->id }}">Branch timezone</label>
                            <input id="branch-timezone-{{ $branch->id }}" name="timezone" value="{{ $branch->timezone }}" required maxlength="80">
                            <button class="button button-light button-small" type="submit">Save</button>
                        </form>
                    </td>
                    <td>{{ $branch->employees_count }}</td>
                    <td>{{ $branch->devices_count }}</td>
                    <td>{{ $branch->registered_computers_count }}</td>
                    <td><span class="status-pill {{ $branch->is_active ? 'ok' : 'off' }}"><i></i>{{ $branch->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        @if($branch->is_active)
                            <form method="post" action="{{ route('branches.deactivate', $branch) }}">@csrf<button class="button button-light button-small" type="submit" @disabled($hasAssignments)>Deactivate</button></form>
                        @else
                            <form method="post" action="{{ route('branches.activate', $branch) }}">@csrf<button class="button button-light button-small" type="submit">Activate</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="table-empty"><strong>No branches configured</strong><span>Add a branch before assigning employees or devices.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
