<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Infrastructure\Notifications\NotificationLevel;
use App\Infrastructure\Notifications\NotificationService;
use Tests\Support\IntegrationTestCase;

final class NotificationServiceTest extends IntegrationTestCase
{
    public function test_notify_persists_with_defaults(): void
    {
        $svc = new NotificationService();

        $id = $svc->notify(
            userId: 42,
            type: 'invoice.approved',
            title: 'Invoice INV-2026-0001 approved'
        );

        $this->assertGreaterThan(0, $id);

        $list = $svc->listFor(42);
        $this->assertCount(1, $list);
        $this->assertSame('invoice.approved', $list[0]->type);
        $this->assertSame(NotificationLevel::Info, $list[0]->level);
        $this->assertFalse($list[0]->isRead());
        $this->assertSame([], $list[0]->data);
    }

    public function test_data_payload_round_trips_through_json(): void
    {
        $svc = new NotificationService();

        $svc->notify(
            userId: 1,
            type: 'order.shipped',
            title: 'Shipped',
            data: ['order_id' => 7, 'tracking' => 'XX-123', 'items' => [1, 2, 3]]
        );

        $list = $svc->listFor(1);
        $this->assertSame(7, $list[0]->data['order_id']);
        $this->assertSame('XX-123', $list[0]->data['tracking']);
        $this->assertSame([1, 2, 3], $list[0]->data['items']);
    }

    public function test_count_unread_isolates_per_user(): void
    {
        $svc = new NotificationService();

        $svc->notify(userId: 1, type: 't', title: 'a');
        $svc->notify(userId: 1, type: 't', title: 'b');
        $svc->notify(userId: 2, type: 't', title: 'c');

        $this->assertSame(2, $svc->countUnread(1));
        $this->assertSame(1, $svc->countUnread(2));
        $this->assertSame(0, $svc->countUnread(3));
    }

    public function test_mark_read_only_works_for_owner(): void
    {
        $svc = new NotificationService();

        $id = $svc->notify(userId: 1, type: 't', title: 'mine');

        // Wrong owner: no-op.
        $this->assertFalse($svc->markRead($id, 999));
        $this->assertSame(1, $svc->countUnread(1));

        // Correct owner: flips.
        $this->assertTrue($svc->markRead($id, 1));
        $this->assertSame(0, $svc->countUnread(1));

        // Idempotent: already-read returns false on the second call
        // because the WHERE read_at = NULL no longer matches.
        $this->assertFalse($svc->markRead($id, 1));
    }

    public function test_mark_all_read_returns_count_and_clears(): void
    {
        $svc = new NotificationService();

        $svc->notify(userId: 5, type: 't', title: 'a');
        $svc->notify(userId: 5, type: 't', title: 'b');
        $svc->notify(userId: 5, type: 't', title: 'c');

        $affected = $svc->markAllRead(5);
        $this->assertSame(3, $affected);
        $this->assertSame(0, $svc->countUnread(5));
    }

    public function test_list_for_filters_unread(): void
    {
        $svc = new NotificationService();

        $kept = $svc->notify(userId: 1, type: 't', title: 'unread');
        $hide = $svc->notify(userId: 1, type: 't', title: 'will-be-read');
        $svc->markRead($hide, 1);

        $unread = $svc->listFor(1, unreadOnly: true);
        $this->assertCount(1, $unread);
        $this->assertSame($kept, $unread[0]->id);
    }

    public function test_list_for_orders_newest_first(): void
    {
        $svc = new NotificationService();

        $svc->notify(userId: 1, type: 't', title: 'oldest');
        sleep(1); // ensure created_at differs to make the ordering observable
        $svc->notify(userId: 1, type: 't', title: 'newest');

        $list = $svc->listFor(1);
        $this->assertSame('newest', $list[0]->title);
        $this->assertSame('oldest', $list[1]->title);
    }

    public function test_invalid_user_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NotificationService())->notify(userId: 0, type: 't', title: 'no');
    }

    public function test_empty_type_or_title_is_rejected(): void
    {
        $svc = new NotificationService();

        try {
            $svc->notify(userId: 1, type: '', title: 't');
            $this->fail('Expected InvalidArgumentException for empty type');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        try {
            $svc->notify(userId: 1, type: 't', title: '');
            $this->fail('Expected InvalidArgumentException for empty title');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }
}
