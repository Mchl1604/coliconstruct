<?php

namespace App\Services;

use App\Models\SystemContent;
use App\Models\User;
use App\Support\UploadStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * @var array<int, string>
     *
     * SVG is deliberately absent. It is not a picture but an XML document,
     * and one may carry script - which, served from this application's own
     * origin by route('media.system'), would run with the session of whoever
     * opened it. Only an administrator can upload website imagery, so this is
     * a path from admin to super admin rather than one open to the public,
     * but it is a path, and no branding need has ever required SVG.
     */
    public const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'ico'];

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
     * A route rather than a file location. Website imagery is the one upload
     * that has to load for people who are not signed in, so the route is open
     * - but it is still the application handing the bytes over, which keeps
     * every upload on one disk and makes the deployment's storage durable
     * rather than a directory the next deploy empties.
     */
    public function image(string $key): ?string
    {
        return $this->imagePath($key) === null
            ? null
            : route('media.system', ['key' => $key]);
    }

    /**
     * The stored path behind an image key, for the route that serves it.
     *
     * Read from the cached map like everything else here; the route is a page
     * load's worth of traffic and must not cost a query each time.
     */
    public function imagePath(string $key): ?string
    {
        $path = $this->all()[$key] ?? null;

        return $path && trim($path) !== '' ? $path : null;
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
     * The services on the Home page, paired with the optional image stored
     * against each stable service id. Older installations only have the
     * pipe-separated list, which remains a valid image-free service list.
     *
     * @return Collection<int, array{id: string|null, title: string, description: string, image: string|null}>
     */
    public function services(): Collection
    {
        $ids = $this->serviceIds();

        return $this->lines('home.services')
            ->map(function (array $service, int $index) use ($ids): array {
                $id = $ids[$index] ?? null;

                return [
                    'id' => $id,
                    'title' => $service['title'],
                    'description' => $service['description'],
                    'image' => $id ? $this->image($this->serviceImageKey($id)) : null,
                ];
            });
    }

    /**
     * The Services editor needs an id even for entries saved before image
     * support existed. It writes those ids only when the Super Admin saves,
     * leaving old content untouched until then.
     *
     * @return Collection<int, array{id: string, title: string, description: string, image: string|null, image_key: string}>
     */
    public function servicesForEditor(): Collection
    {
        return $this->services()
            ->map(function (array $service): array {
                $id = $service['id'] ?: (string) Str::uuid();

                return [
                    'id' => $id,
                    'title' => $service['title'],
                    'description' => $service['description'],
                    'image' => $service['id'] ? $service['image'] : null,
                    'image_key' => $this->serviceImageKey($id),
                ];
            });
    }

    /**
     * The owners displayed on the public About page.
     *
     * @return Collection<int, array{id: string, name: string, contact: string, image: string|null, image_key: string}>
     */
    public function owners(): Collection
    {
        return collect($this->jsonList('about.owners'))
            ->filter(fn (mixed $owner): bool => is_array($owner)
                && $this->isRepeatableId($owner['id'] ?? null)
                && filled($owner['name'] ?? null)
                && filled($owner['contact'] ?? null))
            ->map(function (array $owner): array {
                $id = (string) $owner['id'];

                return [
                    'id' => $id,
                    'name' => trim((string) $owner['name']),
                    'contact' => trim((string) $owner['contact']),
                    'image' => $this->image($this->ownerImageKey($id)),
                    'image_key' => $this->ownerImageKey($id),
                ];
            })
            ->values();
    }

    /**
     * @param  array<int, array{id: string, title: string, description: string, remove_image?: bool}>  $services
     */
    public function saveServices(array $services, User $editor): int
    {
        $previousIds = $this->serviceIds();
        $ids = array_map(fn (array $service): string => (string) $service['id'], $services);
        $lines = collect($services)
            ->map(fn (array $service): string => trim((string) $service['title']).' | '.trim((string) $service['description']))
            ->implode("\n");

        DB::transaction(function () use ($lines, $ids, $editor): void {
            $this->put('home.services', $lines, $editor);
            $this->put('home.service_ids', json_encode($ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', $editor);
        });

        $this->flush();

        $this->removeImagesForIds(array_diff($previousIds, $ids), fn (string $id): string => $this->serviceImageKey($id), $editor);
        $this->removeMarkedImages($services, fn (string $id): string => $this->serviceImageKey($id), $editor);

        return count($services);
    }

    /**
     * @param  array<int, array{id: string, name: string, contact: string, remove_image?: bool}>  $owners
     */
    public function saveOwners(array $owners, User $editor): int
    {
        $previousIds = $this->owners()->pluck('id')->all();
        $ids = array_map(fn (array $owner): string => (string) $owner['id'], $owners);
        $stored = array_map(fn (array $owner): array => [
            'id' => (string) $owner['id'],
            'name' => trim((string) $owner['name']),
            'contact' => trim((string) $owner['contact']),
        ], $owners);

        $this->put('about.owners', json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', $editor);
        $this->flush();

        $this->removeImagesForIds(array_diff($previousIds, $ids), fn (string $id): string => $this->ownerImageKey($id), $editor);
        $this->removeMarkedImages($owners, fn (string $id): string => $this->ownerImageKey($id), $editor);

        return count($owners);
    }

    public function serviceImageKey(string $id): string
    {
        return 'home.service_image.'.$id;
    }

    public function ownerImageKey(string $id): string
    {
        return 'about.owner_image.'.$id;
    }

    /**
     * A numeric setting, as a positive integer.
     *
     * Everything in this table is stored as text, so this is where a setting
     * becomes the number the application actually uses. Anything that is not a
     * positive whole number - blank, a word, a nought, a negative - falls back
     * to what the caller says it should be rather than being passed on: a
     * confirmation window of nought days would complete every waiting project
     * on the next sweep, and an enquiry cooldown of nought would switch the
     * protection off. Validation stops those being saved; this stops one
     * mattering if it ever gets in.
     */
    public function number(string $key, int $fallback): int
    {
        $raw = trim((string) $this->get($key, (string) $fallback));

        // Deliberately strict: "7 days" is a mistake, not seven.
        if (! preg_match('/^\d+$/', $raw)) {
            return $fallback;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : $fallback;
    }

    /**
     * The Terms and Conditions, as written.
     *
     * Nothing is substituted into them and nothing is interpreted. What an
     * administrator typed into the box is what the page shows, which is the
     * only behaviour that is obvious from looking at the editor - a document
     * that quietly rewrites itself between the textarea and the screen is a
     * document nobody can proof-read.
     */
    public function terms(): string
    {
        return (string) $this->get('legal.terms_and_conditions');
    }

    /**
     * The fingerprint of the terms as they stand right now.
     *
     * This is what "the current version" means throughout the system, and it
     * is derived from the words rather than kept alongside them. The terms are
     * one editable field in tbl_system_contents with no revision number of its
     * own, so a counter would have to be incremented by hand on every save -
     * and the first time somebody forgot, every client would be reading new
     * terms while the system believed they had already agreed to them.
     *
     * A hash cannot fall out of step: if the text a client is shown differs by
     * a single character from the text they accepted, the two fingerprints
     * differ and they are asked again. Equally, saving the editor without
     * changing anything - which is what re-reading a settings page and pressing
     * Save amounts to - leaves the fingerprint alone, so nobody is made to
     * agree to the same words twice.
     *
     * Line endings are normalised and the ends trimmed first. A textarea posts
     * CRLF where the shipped default has LF, and "the same document, submitted
     * from a different browser" must not read as a new version.
     */
    public function termsVersion(): string
    {
        return hash('sha256', $this->normalisedTerms());
    }

    /**
     * The terms as the fingerprint sees them: one kind of line ending, no
     * leading or trailing whitespace.
     */
    private function normalisedTerms(): string
    {
        $terms = $this->terms();

        return trim(preg_replace('/\r\n?/', "\n", $terms) ?? $terms);
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

                $type = $definitions[$key]['type'];

                if (SystemContent::isSpecialEditorType($type) && $type !== SystemContent::TYPE_SERVICE_LIST) {
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

        $path = UploadStore::disk()->putFileAs(
            UploadStore::folder('system_contents'),
            $file,
            $name
        );

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
        $definition = SystemContent::definitionForKey($key);

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

        UploadStore::remove($path);
    }

    /**
     * @return array<int, mixed>
     */
    private function jsonList(string $key): array
    {
        $value = $this->all()[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<int, string>
     */
    private function serviceIds(): array
    {
        return collect($this->jsonList('home.service_ids'))
            ->filter(fn (mixed $id): bool => $this->isRepeatableId($id))
            ->values()
            ->all();
    }

    private function isRepeatableId(mixed $id): bool
    {
        return is_string($id)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) === 1;
    }

    /**
     * @param  array<int, string>  $ids
     * @param  callable(string): string  $keyFor
     */
    private function removeImagesForIds(array $ids, callable $keyFor, User $editor): void
    {
        foreach ($ids as $id) {
            $this->removeImage($keyFor($id), $editor);
        }
    }

    /**
     * @param  array<int, array{id: string, remove_image?: bool}>  $entries
     * @param  callable(string): string  $keyFor
     */
    private function removeMarkedImages(array $entries, callable $keyFor, User $editor): void
    {
        foreach ($entries as $entry) {
            if (($entry['remove_image'] ?? false) === true) {
                $this->removeImage($keyFor($entry['id']), $editor);
            }
        }
    }
}
