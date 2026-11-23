@extends('layouts.app', ['title' => 'Announcements'])
@section('crumb', 'People / Announcements')
@section('content')
<div class="page-wrap">
    <div class="page-heading"><div><div class="eyebrow">COMMUNICATION</div><h1>Announcements</h1><p>Published HR news and supporting documents.</p></div></div>
    @if(in_array(auth()->user()->role, ['system_admin', 'hr_director', 'hr_manager'], true))
    <section class="panel department-create-panel">
        <div class="panel-heading"><div><h2>Publish announcement</h2><p>The notice is visible to all employee accounts.</p></div></div>
        <form class="department-create-form" method="post" enctype="multipart/form-data" action="{{ route('announcements.store') }}">
            @csrf
            <label>Title<input name="title" maxlength="180" required value="{{ old('title') }}"></label>
            <label>Message<textarea name="body" rows="3" maxlength="5000" required>{{ old('body') }}</textarea></label>
            <label>Attachment<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></label>
            <div class="form-actions"><button class="button button-primary">Publish</button></div>
        </form>
    </section>
    @endif
    <section class="panel events-panel">
        @forelse($announcements as $announcement)
        <article class="device-row">
            <div class="device-glyph">▣</div>
            <div class="device-info"><strong>{{ $announcement->title }}</strong><span>{{ $announcement->body }}</span></div>
            @if($announcement->attachment_path)<a class="text-link" href="{{ route('announcements.download', $announcement) }}">Download →</a>@endif
        </article>
        @empty
        <div class="empty-state">No announcements have been published.</div>
        @endforelse
        <div class="pagination">{{ $announcements->links() }}</div>
    </section>
</div>
@endsection
