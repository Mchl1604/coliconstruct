<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Project;
use App\Models\QuotationHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The quotation amount and the quotation file, kept honest with each other.
 *
 * They are two separate pieces of project data that describe the same thing,
 * so changing one without the other can leave a project quoting ₱1.5M on the
 * page and ₱1.2M in the PDF. The edit dialog asks about the other half
 * whenever only one of them changes - see quotationSync.js - and the person
 * stays in charge of the answer: either may change on its own, both may
 * change together, and neither changes unless they said so.
 *
 * The dialog's answer arrives as `quotation_change`, one of the CONFIRMED_*
 * values below. The server does not trust the answer blindly or ignore it:
 * a save whose actual changes are not what was confirmed is refused whole,
 * so a stale form, a missing script or a dropped upload cannot quietly change
 * one half behind the person's back.
 */
class QuotationChange
{
    /** Nothing about the quotation changed. */
    public const CONFIRMED_NONE = 'none';

    /** A new amount, and the existing file kept. */
    public const CONFIRMED_AMOUNT = 'amount';

    /** A new file, and the existing amount kept. */
    public const CONFIRMED_FILE = 'file';

    /** A new file and an amount entered or confirmed alongside it. */
    public const CONFIRMED_BOTH = 'both';

    public const CONFIRMATIONS = [
        self::CONFIRMED_NONE,
        self::CONFIRMED_AMOUNT,
        self::CONFIRMED_FILE,
        self::CONFIRMED_BOTH,
    ];

    public function __construct(private readonly DocumentHistoryLog $documentHistory) {}

    /**
     * Whether two amounts are the same figure.
     *
     * Compared to the centavo rather than as typed: the column holds
     * "1000.00" and the form may send "1000", and saving the figure a
     * project already has is not a change - it must not write history.
     */
    public static function sameAmount(mixed $current, mixed $new): bool
    {
        $currentBlank = $current === null || $current === '';
        $newBlank = $new === null || $new === '';

        if ($currentBlank || $newBlank) {
            return $currentBlank && $newBlank;
        }

        return self::normalize($current) === self::normalize($new);
    }

    /** "1000" -> "1000.00", the shape the column stores. */
    public static function normalize(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** "₱1,500,000.00", or "not set" for a project that has no amount. */
    public static function peso(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return 'not set';
        }

        return '₱'.number_format((float) $amount, 2);
    }

    /**
     * Refuse a save whose quotation changes were not the ones confirmed.
     *
     * Asked before anything is written, so a refusal leaves the project, its
     * files and its history exactly as they were.
     *
     * @throws ValidationException
     */
    public function assertConfirmed(Project $project, mixed $newAmount, bool $fileUploaded, ?string $confirmation): void
    {
        $confirmation = $confirmation ?: self::CONFIRMED_NONE;
        $amountChanged = ! self::sameAmount($project->quotation, $newAmount);

        if ($amountChanged && ! in_array($confirmation, [self::CONFIRMED_AMOUNT, self::CONFIRMED_BOTH], true)) {
            throw ValidationException::withMessages([
                'quotation_change' => 'The quotation amount was changed without confirming whether the quotation file '
                    .'should be replaced too. Nothing was saved.',
            ]);
        }

        if ($fileUploaded && ! in_array($confirmation, [self::CONFIRMED_FILE, self::CONFIRMED_BOTH], true)) {
            throw ValidationException::withMessages([
                'quotation_change' => 'A new quotation file was chosen without confirming whether the quotation amount '
                    .'should change too. Nothing was saved.',
            ]);
        }

        // "Replace the file" was the answer, but no file came with it - most
        // likely one too large for the server, which PHP drops before
        // Laravel sees it. Saving the amount alone would be exactly the
        // half-done change the person said they did not want.
        if (! $fileUploaded && in_array($confirmation, [self::CONFIRMED_FILE, self::CONFIRMED_BOTH], true)) {
            throw ValidationException::withMessages([
                'quotation_change' => 'The replacement quotation file did not arrive. Nothing was saved - choose the '
                    .'file and save again.',
            ]);
        }
    }

    /**
     * Take the project's current quotation files off the record, keeping them.
     *
     * Called inside the save's transaction, just before the replacement is
     * stored, so a failed upload leaves the old files current.
     *
     * @return Collection<int, Document> the files that were replaced
     */
    public function supersedeCurrentFiles(Project $project, ?User $actor): Collection
    {
        $current = Document::query()
            ->where('project_id', $project->project_id)
            ->where('document_type', 'quotation')
            ->whereNull('superseded_at')
            ->orderBy('document_id')
            ->get();

        if ($current->isEmpty()) {
            return $current;
        }

        Document::query()
            ->whereKey($current->modelKeys())
            ->update([
                'superseded_at' => now(),
                'superseded_by' => $actor?->id,
            ]);

        // One entry per file replaced. The file is still there to open, so
        // the entry links to it.
        $current->each(function (Document $document) use ($actor): void {
            $this->documentHistory->record($document, DocumentHistory::EVENT_REPLACED, $actor);
        });

        return $current;
    }

    /**
     * Write the history row for an amount that actually changed.
     *
     * Inside the save's transaction, so it is rolled back with everything
     * else if any later part of the save fails.
     */
    public function recordAmountChange(
        Project $project,
        mixed $previousAmount,
        mixed $newAmount,
        bool $fileReplaced,
        ?User $actor
    ): QuotationHistory {
        return QuotationHistory::create([
            'project_id' => $project->project_id,
            'previous_amount' => $previousAmount === null || $previousAmount === ''
                ? null
                : self::normalize($previousAmount),
            'new_amount' => self::normalize($newAmount),
            'file_replaced' => $fileReplaced,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->fullName() ?? 'System',
            'actor_role' => $actor?->role,
            'created_at' => now(),
        ]);
    }

    /**
     * The sentence the audit trail gets about the quotation, or null when the
     * save did not touch it.
     *
     * Names the files that were replaced: the rows are kept, but the log is
     * the record a reader reaches for first.
     *
     * @param  array<int, string>  $replacedFiles
     */
    public function describe(
        mixed $previousAmount,
        mixed $newAmount,
        bool $amountChanged,
        bool $fileReplaced,
        array $replacedFiles
    ): ?string {
        if (! $amountChanged && ! $fileReplaced) {
            return null;
        }

        $amount = $amountChanged
            ? sprintf('changed the quotation amount from %s to %s', self::peso($previousAmount), self::peso($newAmount))
            : sprintf('kept the quotation amount at %s', self::peso($previousAmount));

        if (! $fileReplaced) {
            return ucfirst($amount).' and kept the existing quotation file.';
        }

        $file = $replacedFiles === []
            ? 'uploaded a quotation file'
            : sprintf('replaced the quotation file (previously %s)', implode(', ', $replacedFiles));

        return $amountChanged
            ? ucfirst($amount).' and '.$file.'.'
            : ucfirst($file).' and '.$amount.'.';
    }

    /**
     * What the person is told once the save has gone through.
     *
     * Spelt out for the half that was left alone as well as the half that
     * changed: "the file was not replaced" is the thing somebody who chose to
     * keep it needs to see confirmed.
     */
    public function flashMessage(mixed $previousAmount, mixed $newAmount, bool $amountChanged, bool $fileReplaced): string
    {
        if ($amountChanged && $fileReplaced) {
            return sprintf(
                'Project updated. The quotation amount is now %s (was %s) and the quotation file was replaced.',
                self::peso($newAmount),
                self::peso($previousAmount)
            );
        }

        if ($amountChanged) {
            return sprintf(
                'Project updated. The quotation amount is now %s (was %s). The uploaded quotation file was not replaced.',
                self::peso($newAmount),
                self::peso($previousAmount)
            );
        }

        if ($fileReplaced) {
            return sprintf(
                'Project updated. The quotation file was replaced. The quotation amount stayed at %s.',
                self::peso($previousAmount)
            );
        }

        return 'Project updated successfully.';
    }
}
