<?php

namespace App\Services;

use App\Models\SystemContent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single way the public website reads its content, and the single way the
 * Super Admin writes it.
 *
 * Every public page hits this on every request, so the whole table is read
 * once and cached as one map rather than queried key by key. Any write clears
 * that cache, which is why writes go through here too - a controller updating
 * a row directly would leave the site serving yesterday's words.
 */
class SystemContentService
{
    /**
     * One key for the whole map: the table is small and always read together,
     * so caching per key would mean dozens of lookups to render one page.
     */
    private const CACHE_KEY = 'system_contents.map';

    /**
     * Deliberately short.
     *
     * A read-through cache has an unavoidable race: a request that read the
     * table before a save can write its stale map back afterwards, and then
     * the site serves yesterday's words until the entry expires. Every write
     * re-primes the cache to close that window, and this ceiling means even a
     * missed one heals in a minute rather than an hour.
     */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Where uploaded website assets live on the public disk.
     */
    private const DISK = 'public';

    private const DIRECTORY = 'system-contents';

    /**
     * @var array<int, string>
     */
    public const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'ico'];

    public const MAX_IMAGE_KILOBYTES = 4096;

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Every stored value, keyed by content key.
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->readFromDatabase()
        );
    }

    /**
     * One value, falling back to the catalogue default and then to whatever
     * the caller says should stand in.
     *
     * Nothing here ever returns a missing key as an error: a page with an
     * unfilled field should still render.
     */
    public function get(string $key, ?string $fallback = null): ?string
    {
        $value = $this->all()[$key] ?? null;

        if ($value !== null && trim($value) !== '') {
            return $value;
        }

        $default = SystemContent::DEFINITIONS[$key]['default'] ?? null;

        return $fallback ?? $default;
    }

    /**
     * A stored image as a URL the browser can load, or null when there is
     * none - callers decide what to show in its place.
     *
     * Reduced to a path rather than left absolute: the disk builds its URL
     * from APP_URL, and the same installation is reached on more than one host
     * (the Herd domain, `artisan serve`, the deployed name). A path is right
     * on all of them.
     */
    public function image(string $key): ?string
    {
        $path = $this->all()[$key] ?? null;

        if (! $path || trim($path) === '') {
            return null;
        }

        $url = Storage::disk(self::DISK)->url($path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /**
     * Whether this key would render anything.
     *
     * Deliberately counts the catalogue default as content: a field with a
     * sensible default is filled in as far as a visitor is concerned, and a
     * section guarded by has() should show it. Fields with no default - a
     * phone number, a map link - stay absent until somebody types one.
     */
    public function has(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null && trim($value) !== '';
    }

    /**
     * A "Title | Description" block, one entry per line, as a list.
     *
     * This is how the repeatable parts of the site - services, core values,
     * quick links - stay editable without a table each.
     *
     * @return Collection<int, array{title: string, description: string}>
     */
    public function lines(string $key): Collection
    {
        $raw = (string) $this->get($key);

        return collect(preg_split('/\r\n|\r|\n/', $raw) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->map(function (string $line): array {
                $parts = array_map('trim', explode('|', $line, 2));

                return [
                    'title' => $parts[0] ?? '',
                    'description' => $parts[1] ?? '',
                ];
            })
            ->values();
    }

    /**
     * The website title, used for the browser tab on every public page.
     */
    public function siteTitle(): string
    {
        return (string) $this->get('branding.website_title', 'Coliconstruct');
    }

    /**
     * The copyright line, with :year resolved.
     */
    public function copyright(): string
    {
        return str_replace(':year', (string) now()->year, (string) $this->get('footer.copyright'));
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Save a section's text fields.
     *
     * Only keys the catalogue knows about are written, so a tampered form
     * cannot invent content the site would then have to render.
     *
     * @param  array<string, string|null>  $values
     */
    public function saveText(string $section, array $values, User $editor): int
    {
        $definitions = SystemContent::definitionsFor($section);
        $saved = 0;

        DB::transaction(function () use ($definitions, $values, $editor, &$saved): void {
            foreach ($values as $key => $value) {
                if (! isset($definitions[$key]) || SystemContent::isImageKey($key)) {
                    continue;
                }

                $this->put($key, $value === null ? null : (string) $value, $editor);
                $saved++;
            }
        });

        $this->flush();

        return $saved;
    }

    /**
     * Replace an image, deleting whatever it replaced.
     */
    public function saveImage(string $key, UploadedFile $file, User $editor): string
    {
        $this->guardImageKey($key);
        $this->guardImageFile($file);

        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $name = Str::slug(str_replace('.', '-', $key)).'-'.Str::random(8).'.'.$extension;

        $path = $file->storeAs(self::DIRECTORY, $name, self::DISK);

        if ($path === false) {
            throw new RuntimeException('Unable to store image.');
        }

        // Read straight from the table, never the cached map: deleting a file
        // on the strength of a stale cache is how the site ends up pointing at
        // something that is no longer there.
        $previous = $this->storedValue($key);

        $this->put($key, $path, $editor);
        $this->flush();

        // Only after the new path is safely stored, so a failed write never
        // leaves the site pointing at a file that is already gone.
        $this->deleteFile($previous, $path);

        return $path;
    }

    public function removeImage(string $key, User $editor): void
    {
        $this->guardImageKey($key);

        $previous = $this->storedValue($key);

        $this->put($key, null, $editor);
        $this->flush();

        $this->deleteFile($previous);
    }

    /**
     * Re-read the table and replace the cached map with it.
     *
     * Called after every write. Forgetting alone would leave the next reader
     * to re-populate, which is where a slower in-flight request can slip a
     * stale map in behind the save; writing the fresh map immediately leaves
     * nothing to lose the race to.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        Cache::put(self::CACHE_KEY, $this->readFromDatabase(), self::CACHE_TTL_SECONDS);
    }

    /**
     * @return array<string, string|null>
     */
    private function readFromDatabase(): array
    {
        return SystemContent::query()
            ->pluck('content_value', 'content_key')
            ->all();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function put(string $key, ?string $value, User $editor): void
    {
        $definition = SystemContent::DEFINITIONS[$key] ?? null;

        if (! $definition) {
            return;
        }

        SystemContent::updateOrCreate(
            ['content_key' => $key],
            [
                'content_value' => $value,
                'content_type' => $definition['type'],
                'section' => $definition['section'],
                'updated_by' => $editor->id,
            ]
        );
    }

    private function guardImageKey(string $key): void
    {
        if (! SystemContent::isImageKey($key)) {
            throw new RuntimeException('That content field does not hold an image.');
        }
    }

    private function guardImageFile(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException(sprintf(
                'Images must be one of: %s.',
                implode(', ', self::ALLOWED_IMAGE_EXTENSIONS)
            ));
        }

        if ($file->getSize() > self::MAX_IMAGE_KILOBYTES * 1024) {
            throw new RuntimeException(sprintf(
                'Images must be %d MB or smaller.',
                (int) (self::MAX_IMAGE_KILOBYTES / 1024)
            ));
        }
    }

    /**
     * The value as the table has it right now, bypassing the cache.
     */
    private function storedValue(string $key): ?string
    {
        return SystemContent::query()
            ->where('content_key', $key)
            ->value('content_value');
    }

    /**
     * @param  string|null  $keep  A path that must survive, whatever else
     *                             happens - the file just stored.
     */
    private function deleteFile(?string $path, ?string $keep = null): void
    {
        if (! $path || $path === $keep) {
            return;
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
