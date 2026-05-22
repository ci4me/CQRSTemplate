<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Storage\AttachmentService;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageException;
use Tests\Support\IntegrationTestCase;

final class AttachmentServiceTest extends IntegrationTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/attachments-' . uniqid('', true);
        mkdir($dir);
        $this->tempDir = $dir;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_attach_persists_metadata_and_bytes(): void
    {
        $svc = $this->makeService();

        $ref = $svc->attachTo(
            attachableType: 'App\\Domain\\Invoice',
            attachableId: '42',
            contents: 'PDF-BODY',
            originalName: 'invoice-42.pdf',
            mimeType: 'application/pdf',
            actor: Actor::user(7)
        );

        $this->assertGreaterThan(0, $ref->id);
        $this->assertSame('invoice-42.pdf', $ref->originalName);
        $this->assertSame(8, $ref->sizeBytes);
        $this->assertSame(hash('sha256', 'PDF-BODY'), $ref->checksumSha256);
        $this->assertSame(7, $ref->uploadedBy);

        $this->seeInDatabase('attachments', [
            'id' => $ref->id,
            'attachable_type' => 'App\\Domain\\Invoice',
            'attachable_id' => '42',
            'storage_driver' => 'local',
        ]);

        // Bytes round-trip via the service:
        $this->assertSame('PDF-BODY', $svc->read($ref->id));
    }

    public function test_list_for_returns_attachments_in_insertion_order(): void
    {
        $svc = $this->makeService();

        $svc->attachTo('Invoice', '1', 'first', 'a.txt', 'text/plain', Actor::system());
        $svc->attachTo('Invoice', '1', 'second', 'b.txt', 'text/plain', Actor::system());

        $rows = $svc->listFor('Invoice', '1');
        $this->assertCount(2, $rows);
        $this->assertSame('a.txt', $rows[0]->originalName);
        $this->assertSame('b.txt', $rows[1]->originalName);
    }

    public function test_list_for_excludes_soft_deleted(): void
    {
        $svc = $this->makeService();

        $kept = $svc->attachTo('Invoice', '1', 'one', 'a.txt', 'text/plain', Actor::system());
        $gone = $svc->attachTo('Invoice', '1', 'two', 'b.txt', 'text/plain', Actor::system());

        $svc->delete($gone->id);

        $rows = $svc->listFor('Invoice', '1');
        $this->assertCount(1, $rows);
        $this->assertSame($kept->id, $rows[0]->id);
    }

    public function test_delete_removes_underlying_file(): void
    {
        $svc = $this->makeService();
        $storage = new LocalStorage($this->tempDir);

        $ref = $svc->attachTo('Invoice', '1', 'bytes', 'file.txt', 'text/plain', Actor::system());

        $storageKey = $this->loadStorageKey($ref->id);
        $this->assertTrue($storage->exists($storageKey));

        $svc->delete($ref->id);

        $this->assertFalse($storage->exists($storageKey));
    }

    public function test_read_missing_attachment_throws(): void
    {
        $svc = $this->makeService();

        $this->expectException(StorageException::class);
        $svc->read(9999);
    }

    public function test_empty_contents_are_rejected(): void
    {
        $svc = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $svc->attachTo('Invoice', '1', '', 'empty.txt', 'text/plain', Actor::system());
    }

    public function test_empty_attachable_identifiers_are_rejected(): void
    {
        $svc = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attachableType and attachableId are required');
        $svc->attachTo('', '', 'data', 'note.txt', 'text/plain', Actor::system());
    }

    public function test_delete_is_idempotent_for_missing_id(): void
    {
        $svc = $this->makeService();
        // No attachment with id=99999 — must not throw.
        $svc->delete(99999);
        $this->assertTrue(true, 'delete() is a no-op when the row is gone');
    }

    public function test_list_for_unknown_attachable_returns_empty(): void
    {
        $svc = $this->makeService();
        $this->assertSame([], $svc->listFor('NeverHeardOf', 'no-id-like-this'));
    }

    public function test_isolation_by_attachable_type(): void
    {
        $svc = $this->makeService();

        $svc->attachTo('Invoice', '1', 'a', 'a.txt', 'text/plain', Actor::system());
        $svc->attachTo('PurchaseOrder', '1', 'b', 'b.txt', 'text/plain', Actor::system());

        $this->assertCount(1, $svc->listFor('Invoice', '1'));
        $this->assertCount(1, $svc->listFor('PurchaseOrder', '1'));
        $this->assertCount(0, $svc->listFor('Invoice', '2'));
    }

    private function makeService(): AttachmentService
    {
        return new AttachmentService(new LocalStorage($this->tempDir));
    }

    private function loadStorageKey(int $id): string
    {
        $row = \Config\Database::connect()->table('attachments')->where('id', $id)->get()->getRowArray();
        return (string) $row['storage_key'];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
