<?php

namespace App\Models\Concerns;

use App\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Audit trail for a model's create / update / delete.
 *
 * Wraps Spatie's LogsActivity so every audited model in this app agrees on
 * the parts that should never vary — the `model` log name, dirty-only
 * updates, and a human description built from the model's own label. A model
 * only has to say *which* fields matter, via $auditable.
 *
 * Only listed attributes are recorded. That is deliberate: an unlisted
 * column (a password hash, a cached total) never reaches the log, so the
 * audit trail cannot become a place secrets leak to.
 */
trait RecordsActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(Activity::LOG_MODEL)
            ->logOnly($this->auditable ?? [])
            // An update that changed nothing is noise, not history.
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * "Product “Angkor can” updated" — read as a sentence on the Activity
     * screen without having to open the row's properties.
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return trim(sprintf(
            '%s %s %s',
            class_basename($this),
            $this->auditLabel() ? '“'.$this->auditLabel().'”' : '',
            $eventName
        ));
    }

    /** What to call this record in the log. Override when it isn't `name`. */
    public function auditLabel(): ?string
    {
        return $this->name ?? null;
    }
}
