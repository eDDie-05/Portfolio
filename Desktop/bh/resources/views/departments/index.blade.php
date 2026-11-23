@extends('layouts.app', ['title' => 'Departments'])
@section('crumb', 'Departments')
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div>
            <div class="eyebrow">PEOPLE / ORGANIZATION</div>
            <h1>Departments</h1>
            <p>Manage department names used on employee profiles and reports.</p>
        </div>
        <a class="button button-light" href="{{ route('employees.index') }}">View employees</a>
    </div>

    @if($errors->any())
        <div class="flash warning">{{ $errors->first() }}</div>
    @endif

    <section class="panel department-create-panel">
        <div class="panel-heading"><div><h2>Add a department</h2><p>Department names must be unique.</p></div></div>
        <form class="department-create-form" method="post" action="{{ route('departments.store') }}">
            @csrf
            <label>Name<input name="name" required maxlength="120" value="{{ old('name') }}">@error('name')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Description<input name="description" maxlength="2000" value="{{ old('description') }}">@error('description')<small class="form-error">{{ $message }}</small>@enderror</label>
            <button class="button button-primary" type="submit">Add department</button>
        </form>
    </section>

    <section class="panel events-panel">
        <div class="panel-heading"><div><h2>Organization departments</h2><p>Assigned departments cannot be deactivated until employees are moved.</p></div><span class="table-count">{{ $departments->count() }} departments</span></div>
        <div class="table-wrap"><table><thead><tr><th>DEPARTMENT</th><th>DESCRIPTION</th><th>EMPLOYEES</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody>
            @forelse($departments as $department)
                <tr>
                    <td colspan="2">
                        <form class="department-edit-form" method="post" action="{{ route('departments.update', $department) }}">
                            @csrf @method('PUT')
                            <label class="visually-hidden" for="department-name-{{ $department->id }}">Department name</label>
                            <input id="department-name-{{ $department->id }}" name="name" value="{{ $department->name }}" required maxlength="120">
                            <label class="visually-hidden" for="department-description-{{ $department->id }}">Department description</label>
                            <input id="department-description-{{ $department->id }}" name="description" value="{{ $department->description }}" maxlength="2000" placeholder="Description">
                            <button class="button button-light button-small" type="submit">Save</button>
                        </form>
                    </td>
                    <td>{{ $department->employees_count }}</td>
                    <td><span class="status-pill {{ $department->is_active ? 'ok' : 'off' }}"><i></i>{{ $department->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        @if($department->is_active)
                            <form method="post" action="{{ route('departments.deactivate', $department) }}">@csrf<button class="button button-light button-small" type="submit" @disabled($department->employees_count > 0)>Deactivate</button></form>
                        @else
                            <form method="post" action="{{ route('departments.activate', $department) }}">@csrf<button class="button button-light button-small" type="submit">Activate</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="table-empty"><strong>No departments configured</strong><span>Add departments before assigning employees.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
