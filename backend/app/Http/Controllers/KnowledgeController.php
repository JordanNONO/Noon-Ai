<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeEntry;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Bibliothèque des savoirs : chacun propose, les modérateurs valident, Noon s'en sert. */
class KnowledgeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status', 'approved');
        $q = KnowledgeEntry::with('author', 'reviewer')->latest();

        if ($status === 'mine') {
            $q->where('author_id', $user->id);
        } elseif ($status === 'pending' && $user->canModerate()) {
            $q->where('status', 'pending');
        } else {
            $q->approved();
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($lang = $request->query('language')) {
            $q->where('language', $lang);
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->search($s);
        }

        return [
            'entries' => $q->limit(200)->get()->map->toPublic()->values(),
            'types' => collect(KnowledgeEntry::TYPES)->map(fn ($l, $k) => [
                'key' => $k, 'label' => $l, 'count' => KnowledgeEntry::approved()->where('type', $k)->count(),
            ])->values(),
            'counts' => [
                'approved' => KnowledgeEntry::approved()->count(),
                'pending' => $user->canModerate() ? KnowledgeEntry::where('status', 'pending')->count() : 0,
                'mine' => KnowledgeEntry::where('author_id', $user->id)->count(),
            ],
            'can_moderate' => $user->canModerate(),
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $entry = new KnowledgeEntry($data);
        $entry->author_id = $request->user()->id;
        // Un modérateur publie directement ; les autres contributions attendent une validation
        $entry->status = $request->user()->canModerate() ? 'approved' : 'pending';
        if ($entry->status === 'approved') {
            $entry->reviewed_by = $request->user()->id;
            $entry->reviewed_at = now();
        }
        $entry->audio_path = $this->storeAudio($request);
        $entry->save();

        return ['entry' => $entry->load('author', 'reviewer')->toPublic()];
    }

    public function update(Request $request, KnowledgeEntry $entry)
    {
        $user = $request->user();
        abort_unless($user->canModerate() || ($entry->author_id === $user->id && $entry->status !== 'approved'), 403);
        $entry->fill($this->validated($request, true));
        if ($path = $this->storeAudio($request)) {
            $entry->audio_path = $path;
        }
        if (! $user->canModerate()) {
            $entry->status = 'pending';
        }
        $entry->save();

        return ['entry' => $entry->load('author', 'reviewer')->toPublic()];
    }

    public function review(Request $request, KnowledgeEntry $entry)
    {
        abort_unless($request->user()->canModerate(), 403);
        $data = $request->validate([
            'status' => 'required|in:approved,rejected',
            'note' => 'nullable|string|max:255',
        ]);
        $entry->update(['status' => $data['status'], 'review_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return ['entry' => $entry->load('author', 'reviewer')->toPublic()];
    }

    public function destroy(Request $request, KnowledgeEntry $entry)
    {
        $user = $request->user();
        abort_unless($user->canModerate() || ($entry->author_id === $user->id && $entry->status !== 'approved'), 403);
        $entry->delete();

        return response()->noContent();
    }

    public function audio(Request $request, KnowledgeEntry $entry)
    {
        $user = $request->user();
        abort_unless($entry->status === 'approved' || $entry->author_id === $user->id || $user->canModerate(), 404);
        abort_unless($entry->audio_path && Storage::disk('local')->exists($entry->audio_path), 404);

        return response()->file(Storage::disk('local')->path($entry->audio_path));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => [$req, Rule::in(array_keys(KnowledgeEntry::TYPES))],
            'language' => [$req, Rule::exists(Language::class, 'code')],
            'title' => "{$req}|string|max:190",
            'content' => "{$req}|string|max:50000",
            'translation' => 'sometimes|nullable|string|max:50000',
            'meaning' => 'sometimes|nullable|string|max:5000',
            'pronunciation' => 'sometimes|nullable|string|max:190',
            'village' => 'sometimes|nullable|string|max:120',
            'tags' => 'sometimes|array|max:10',
            'tags.*' => 'string|max:40',
            'audio' => 'sometimes|file|max:20480',
        ]);
    }

    private function storeAudio(Request $request): ?string
    {
        return $request->hasFile('audio') ? $request->file('audio')->store('knowledge', 'local') : null;
    }
}
