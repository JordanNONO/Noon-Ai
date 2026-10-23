<?php

use App\Agents\ComboRunner;
use App\Models\Combo;
use App\Models\Setting;
use App\Models\User;
use Cron\CronExpression;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Lance les parcours planifiés dont l'heure est venue (à exécuter chaque minute par le planificateur)
Artisan::command('combos:run-scheduled', function (ComboRunner $runner) {
    $now = now('Africa/Douala');
    Combo::where('trigger_type', 'schedule')->whereNotNull('schedule_cron')->get()
        ->filter(fn (Combo $c) => CronExpression::isValidExpression($c->schedule_cron) && (new CronExpression($c->schedule_cron))->isDue($now))
        ->each(function (Combo $c) use ($runner) {
            $owner = User::find($c->created_by) ?? User::find(User::PROTECTED_ADMIN_ID);
            if ($owner) {
                $this->info("Parcours « {$c->name} »");
                $runner->start($c, $owner, null, fn () => null);
            }
        });
})->purpose('Lance les parcours planifiés');

// Supprime les conversations plus anciennes que la durée de conservation choisie
Artisan::command('conversations:prune', function () {
    $days = Setting::get('retention_days');
    if ($days) {
        $n = \App\Models\Conversation::where('updated_at', '<', now()->subDays((int) $days))->delete();
        $this->info("{$n} conversation(s) supprimée(s).");
    }
})->purpose('Applique la durée de conservation des conversations');

Schedule::command('combos:run-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('conversations:prune')->daily();
