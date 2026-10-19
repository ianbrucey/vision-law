<?php

namespace App\Console\Commands;

use App\Models\HolidayCalendar;
use App\Models\HolidayDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load the versioned holiday packs (009-D05, T-11) from
 * database/data/holiday-calendars/*.json into the holiday_calendars
 * + holiday_dates tables.
 *
 * Pack file shape: {key, version, label, demonstration, owner,
 * dates: [{date, name}]}. The legacy pre-T-11 shape ({pack, version,
 * label, owner, holidays: [...]}) is also accepted, keyed by the
 * file name, so older checkouts still load.
 *
 * Idempotent: a (key, version) that is already loaded is a no-op —
 * historical versions are retained and never edited in place.
 * Callable from seeders and tests via Artisan::call().
 */
class LoadHolidayPacks extends Command
{
    protected $signature = 'calendaring:load-holiday-packs';

    protected $description = 'Load versioned holiday calendar packs from database/data/holiday-calendars/*.json.';

    public function handle(): int
    {
        $directory = database_path('data/holiday-calendars');
        $files = glob($directory.'/*.json') ?: [];
        sort($files);

        $loaded = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                $this->warn("Unreadable pack file: {$file}");

                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $key = isset($data['key']) && is_string($data['key'])
                ? $data['key']
                : pathinfo($file, PATHINFO_FILENAME);
            $version = isset($data['version']) && is_string($data['version']) ? $data['version'] : '1';
            $label = isset($data['label']) && is_string($data['label']) ? $data['label'] : $key;
            $demonstration = isset($data['demonstration']) ? (bool) $data['demonstration'] : true;
            $owner = isset($data['owner']) && is_string($data['owner'])
                ? $data['owner']
                : (isset($data['owner_label']) && is_string($data['owner_label']) ? $data['owner_label'] : null);

            /** @var list<array<string, mixed>> $dates */
            $dates = [];
            if (isset($data['dates']) && is_array($data['dates'])) {
                $dates = array_values($data['dates']);
            } elseif (isset($data['holidays']) && is_array($data['holidays'])) {
                $dates = array_values($data['holidays']);
            }

            $existing = HolidayCalendar::query()->where('key', $key)->where('version', $version)->first();
            if ($existing !== null) {
                $skipped++;

                continue;
            }

            DB::transaction(function () use ($key, $version, $label, $demonstration, $owner, $dates): void {
                $calendar = HolidayCalendar::create([
                    'key' => $key,
                    'version' => $version,
                    'label' => $label,
                    'demonstration' => $demonstration,
                    'owner_label' => $owner,
                ]);

                foreach ($dates as $entry) {
                    if (! isset($entry['date'], $entry['name']) || ! is_string($entry['date']) || ! is_string($entry['name'])) {
                        continue;
                    }
                    HolidayDate::create([
                        'calendar_id' => (string) $calendar->getKey(),
                        'date' => $entry['date'],
                        'name' => $entry['name'],
                    ]);
                }
            });

            $loaded++;
            $this->info("Loaded holiday pack {$key} v{$version} (".count($dates).' dates).');
        }

        $this->info("Holiday packs: {$loaded} loaded, {$skipped} already present.");

        return self::SUCCESS;
    }
}
