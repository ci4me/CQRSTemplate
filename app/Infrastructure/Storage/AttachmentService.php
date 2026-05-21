<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\AttachmentRef;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Domain-facing attach/detach API (D11).
 *
 * Persists bytes via the {@see StorageInterface} and metadata in the
 * `attachments` table. Returns {@see AttachmentRef} so callers never see
 * the storage key directly.
 *
 * Polymorphic association: callers pass an attachable_type (FQCN of the
 * owning aggregate) and an attachable_id (the aggregate's id as a string).
 * Example:
 *     $service->attachTo(
 *         attachableType: Invoice::class,
 *         attachableId:   (string) $invoice->getId(),
 *         contents:       file_get_contents($_FILES['upload']['tmp_name']),
 *         originalName:   $_FILES['upload']['name'],
 *         mimeType:       $_FILES['upload']['type'],
 *         actor:          $request->user_actor,
 *     );
 *
 * Keys are computed deterministically as
 *   {attachable_type_slug}/{attachable_id}/{uuid}-{safe_name}
 * which keeps files grouped by owner on disk.
 */
final class AttachmentService
{
    /**
     * @param StorageInterface                                                  $storage
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?BaseConnection $db = null
    ) {
    }

    /**
     * attachTo.
     *
     * @param string   $attachableType
     * @param string   $attachableId
     * @param string   $contents
     * @param string   $originalName
     * @param string   $mimeType
     * @param Actor    $actor
     * @param int|null $tenantId
     * @return AttachmentRef
     * @throws \InvalidArgumentException
     */
    public function attachTo(
        string $attachableType,
        string $attachableId,
        string $contents,
        string $originalName,
        string $mimeType,
        Actor $actor,
        ?int $tenantId = null
    ): AttachmentRef {
        if ($contents === '') {
            throw new \InvalidArgumentException('Refusing to attach empty contents.');
        }
        if ($attachableType === '' || $attachableId === '') {
            throw new \InvalidArgumentException('attachableType and attachableId are required.');
        }

        $key = $this->buildKey($attachableType, $attachableId, $originalName);
        $this->storage->put($key, $contents);

        $size = strlen($contents);
        $checksum = hash('sha256', $contents);
        $now = date('Y-m-d H:i:s');

        $this->connection()->table('attachments')->insert([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'storage_key' => $key,
            'storage_driver' => $this->storage->name(),
            'original_name' => $originalName,
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'uploaded_by' => $actor->id,
            'tenant_id' => $tenantId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->connection()->insertID();

        return AttachmentRef::create(
            id: $id,
            attachableType: $attachableType,
            attachableId: $attachableId,
            originalName: $originalName,
            mimeType: $mimeType,
            sizeBytes: $size,
            checksumSha256: $checksum,
            uploadedBy: $actor->id
        );
    }

    /**
     * Read the raw bytes for a previously-attached file. Caller is expected
     * to authorise the read at the controller layer (e.g. via PermissionMiddleware).
     *
     * @param int $id
     * @return string
     * @throws StorageException
     */
    public function read(int $id): string
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            throw new StorageException(sprintf('Attachment #%d not found.', $id));
        }
        return $this->storage->get((string) $row['storage_key']);
    }

    /**
     * Soft-delete (sets deleted_at + deletes underlying bytes). The row is
     * preserved for audit; the file goes away to reclaim space.
     *
     * @param int $id
     * @return void
     */
    public function delete(int $id): void
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return;
        }

        $this->storage->delete((string) $row['storage_key']);

        $now = date('Y-m-d H:i:s');
        $this->connection()->table('attachments')
            ->where('id', $id)
            ->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param string $attachableType
     * @param string $attachableId
     * @return list<AttachmentRef>
     */
    public function listFor(string $attachableType, string $attachableId): array
    {
        $result = $this->connection()
            ->table('attachments')
            ->where('attachable_type', $attachableType)
            ->where('attachable_id', $attachableId)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->get();

        if ($result === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();

        return array_map(fn(array $row): AttachmentRef => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return AttachmentRef
     */
    private function hydrate(array $row): AttachmentRef
    {
        return AttachmentRef::reconstitute(
            id: (int) $row['id'],
            attachableType: (string) $row['attachable_type'],
            attachableId: (string) $row['attachable_id'],
            originalName: (string) $row['original_name'],
            mimeType: (string) $row['mime_type'],
            sizeBytes: (int) $row['size_bytes'],
            checksumSha256: $row['checksum_sha256'] === null ? null : (string) $row['checksum_sha256'],
            uploadedBy: (int) $row['uploaded_by']
        );
    }

    /**
     * buildKey.
     *
     * @param string $type
     * @param string $id
     * @param string $name
     * @return string
     */
    private function buildKey(string $type, string $id, string $name): string
    {
        $typeSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($type)) ?? 'attach';
        $typeSlug = trim($typeSlug, '-');
        if ($typeSlug === '') {
            $typeSlug = 'attach';
        }

        $idSlug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $id) ?? 'x';

        $safeName = $this->safeName($name);
        $uuid = bin2hex(random_bytes(8));

        return sprintf('%s/%s/%s-%s', $typeSlug, $idSlug, $uuid, $safeName);
    }

    /**
     * safeName.
     *
     * @param string $name
     * @return string
     */
    private function safeName(string $name): string
    {
        $name = basename($name);
        $sanitised = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        return $sanitised === null || $sanitised === '' ? 'file' : substr($sanitised, 0, 100);
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null
     */
    private function fetchRow(int $id): ?array
    {
        $result = $this->connection()
            ->table('attachments')
            ->where('id', $id)
            ->where('deleted_at', null)
            ->get();

        if ($result === false) {
            return null;
        }
        /** @var array<string, mixed>|null $row */
        $row = $result->getRowArray();
        return $row;
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
