<?php

namespace App\Http\Controllers;

use App\Agents\ToolBelt;
use App\Models\Message;
use App\Models\PendingAction;
use Illuminate\Http\Request;

/** Accord ou refus d'une action préparée par un assistant (envoi, publication…). */
class PendingActionController extends Controller
{
    public function approve(Request $request, PendingAction $action)
    {
        $this->own($request, $action);
        abort_unless($action->status === 'pending', 422, 'Cette demande a déjà été traitée.');

        if ($action->kind === 'combo_gate') {
            $action->update(['status' => 'approved']);
            $this->updateStep($action, 'Validé', 'done');

            // Le parcours reprend via POST /combo-runs/{id}/resume (réponse en flux)
            return ['action' => $action->toPublic(), 'resume_run_id' => $action->combo_run_id];
        }

        try {
            $result = ToolBelt::executeApproved($action);
            $action->update(['status' => 'done', 'result' => $result]);
            $this->updateStep($action, 'Terminé', 'done');
            $reply = 'C\'est fait : '.$this->lower($action->summary).'.';
        } catch (\Throwable $e) {
            $action->update(['status' => 'failed', 'result' => ['erreur' => $e->getMessage()]]);
            $this->updateStep($action, 'Échec', 'error');
            $reply = 'Je n\'ai pas pu le faire : '.$e->getMessage();
        }

        $msg = $action->conversation->messages()->create(['role' => 'assistant', 'agent_id' => $action->agent_id, 'content' => $reply]);
        $this->settleMessage($action);

        return ['action' => $action->fresh()->toPublic(), 'message' => $msg->load('agent')->toPublic()];
    }

    public function reject(Request $request, PendingAction $action)
    {
        $this->own($request, $action);
        abort_unless($action->status === 'pending', 422, 'Cette demande a déjà été traitée.');
        $action->update(['status' => 'rejected']);
        $this->updateStep($action, 'Annulé', 'error');
        if ($action->combo_run_id) {
            $action->comboRun?->update(['status' => 'failed']);
        }
        $this->settleMessage($action);

        return ['action' => $action->toPublic()];
    }

    private function updateStep(PendingAction $action, string $status, string $state): void
    {
        $msg = $action->message_id ? Message::find($action->message_id) : null;
        if (! $msg) {
            return;
        }
        $msg->steps = collect($msg->steps ?? [])->map(function ($s) use ($action, $status, $state) {
            if (($s['pending_id'] ?? null) === $action->id) {
                $s['status'] = $status;
                $s['state'] = $state;
                $s['label'] = str_replace([' (attend votre accord)', ' — attend votre validation'], '', $s['label']);
            }

            return $s;
        })->all();
        $msg->save();
    }

    /** Le message repasse à « terminé » quand plus rien n'attend. */
    private function settleMessage(PendingAction $action): void
    {
        $msg = $action->message_id ? Message::find($action->message_id) : null;
        if ($msg && ! $msg->pendingActions()->where('status', 'pending')->exists()) {
            $msg->update(['status' => 'done']);
        }
    }

    private function lower(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private function own(Request $request, PendingAction $action): void
    {
        abort_unless($action->user_id === $request->user()->id, 404);
    }
}
