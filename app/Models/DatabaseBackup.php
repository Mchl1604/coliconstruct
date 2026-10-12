<?php

namespace App\Models;

use App\Support\BusinessTime;
use Database\Factories\DatabaseBackupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One backup archive: the database, and usually the uploaded files with it.
 *
 * The row is only the listing. The archive itself is on the backups disk at
 * `path`, and carries its own signed manifest - so an archive downloaded and
 * uploaded again restores without this row ever having existed.
 */
class DatabaseBackup extends Model
{
    /** @use HasFactory<DatabaseBackupFactory> */
    use HasFactory;

    public const KIND_MANUAL = 'manual';

    /**
     * Made automatically just before a restore, so the restore can be undone.
     */
    public const KIND_PRE_RESTORE = 'pre_restore';

    /**
     * An archive somebody uploaded through Upload & Restore.
     */
    public const KIND_UPLOADED = 'uploaded';

    /**
     * @var array<string, string>
     */
    public const KINDS = [
        self::KIND_MANUAL => 'Manual',
        self::KIND_PRE_RESTORE => 'Before restore',
        self::KIND_UPLOADED => 'Uploaded',
    ];

    protected $table = 'tbl_database_backups';

    protected $primaryKey = 'backup_id';

    protected $fillable = [
        'file_name',
        'path',
        'kind',
        'includes_files',
        'size_bytes',
        'table_count',
        'row_count',
        'file_count',
        'created_by',
        'created_by_name',
    ];

    protected $casts = [
        'includes_files' => 'boolean',
        'size_bytes' => 'integer',
        'table_count' => 'integer',
        'row_count' => 'integer',
        'file_count' => 'integer',
    ];

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Backup';
    }

    /**
     * The row as the Backup & Restore table draws it.
     *
     * @return array{id: int, file_name: string, kind: string, kind_label: string, includes_files: bool, size_bytes: int, table_count: int, row_count: int, file_count: int, created_by: string, created_at: string, created_at_iso: string|null}
     */
    public function toListing(): array
    {
        return [
            'id' => $this->backup_id,
            'file_name' => $this->file_name,
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel(),
            'includes_files' => $this->includes_files,
            'size_bytes' => $this->size_bytes,
            'table_count' => $this->table_count,
            'row_count' => $this->row_count,
            'file_count' => $this->file_count,
            'created_by' => $this->created_by_name ?: 'System',
            'created_at' => BusinessTime::format($this->created_at, BusinessTime::DATE_TIME),
            'created_at_iso' => $this->created_at?->toIso8601String(),
        ];
    }
}
