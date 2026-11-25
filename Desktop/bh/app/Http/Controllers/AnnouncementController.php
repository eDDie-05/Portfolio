<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director', 'hr_manager', 'employee'], true), 403);
        $query = Announcement::with('publisher')->latest('published_at');

        if ($request->user()->role === 'employee') {
            $query->where('is_published', true);
        }

        return view('announcements.index', [
            'announcements' => $query->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director', 'hr_manager'], true), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
        ]);

        $attachmentPath = null;
        $attachmentName = null;
        $attachmentMime = null;
        if (isset($data['attachment'])) {
            $file = $data['attachment'];
            $extension = $file->guessExtension() ?? $file->extension();
            $attachmentPath = $file->storeAs('announcements', Str::uuid().'.'.$extension, config('filesystems.private_disk'));
            $attachmentName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $attachmentMime = $file->getMimeType();
        }

        Announcement::create([
            'published_by' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_mime' => $attachmentMime,
            'is_published' => true,
            'published_at' => now(),
        ]);

        return back()->with('status', 'Announcement published.');
    }

    public function download(Request $request, Announcement $announcement): StreamedResponse
    {
        abort_unless(
            $announcement->is_published || in_array($request->user()->role, ['system_admin', 'hr_director', 'hr_manager'], true),
            404,
        );
        $disk = Storage::disk(config('filesystems.private_disk'));
        abort_unless($announcement->attachment_path && $disk->exists($announcement->attachment_path), 404);

        return $disk->download(
            $announcement->attachment_path,
            $announcement->attachment_name,
            ['Content-Type' => $announcement->attachment_mime ?: 'application/octet-stream'],
        );
    }
}
