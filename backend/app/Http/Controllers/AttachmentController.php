<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\TextExtractor;
use Illuminate\Http\Request;

class AttachmentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['file' => 'required|file|max:25600']);
        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $path = $file->store('attachments/'.$request->user()->id, 'local');
        $text = str_starts_with($mime, 'image/') ? null : TextExtractor::fromBytes(file_get_contents($file->getRealPath()), $mime, $file->getClientOriginalName());

        $a = Attachment::create([
            'user_id' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'path' => $path,
            'extracted_text' => $text ?: null,
        ]);

        return ['attachment' => $a->toPublic()];
    }
}
