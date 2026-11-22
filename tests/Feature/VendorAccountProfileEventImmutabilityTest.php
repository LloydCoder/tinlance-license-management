<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorAccountProfileEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class VendorAccountProfileEventImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_eloquent_rejects_vendor_profile_event_updates_and_deletes(): void
    {
        $event = $this->createEvent();

        try {
            $event->update(['reason' => 'attempted mutation']);
            $this->fail('Vendor profile event update should be rejected.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $event->delete();
            $this->fail('Vendor profile event deletion should be rejected.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_mysql_and_mariadb_triggers_reject_raw_vendor_profile_event_mutations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level immutable triggers are specific to MySQL/MariaDB.');
        }

        $event = $this->createEvent();

        try {
            DB::table('vendor_account_profile_events')
                ->where('id', $event->getKey())
                ->update(['reason' => 'raw database mutation']);
            $this->fail('Database trigger should reject vendor profile event updates.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('vendor_account_profile_events')
                ->where('id', $event->getKey())
                ->delete();
            $this->fail('Database trigger should reject vendor profile event deletion.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('vendor_account_profile_events', [
            'id' => $event->getKey(),
            'reason' => 'Original immutable profile event.',
        ]);
    }

    private function createEvent(): VendorAccountProfileEvent
    {
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'immutable-vendor',
            'legal_name' => 'Immutable Vendor Limited',
            'display_name' => 'Immutable Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
        $actor = User::query()->create([
            'name' => 'Vendor Profile Auditor',
            'email' => 'vendor-profile-audit-'.str()->random(12).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);

        return VendorAccountProfileEvent::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'actor_user_id' => $actor->getKey(),
            'event_type' => 'profile_updated',
            'reason' => 'Original immutable profile event.',
            'changed_fields' => ['display_name'],
            'change_digest' => hash('sha256', 'before=Immutable Vendor;after=Updated Vendor'),
            'request_id' => 'vendor-profile-immutable-0001',
            'created_at' => now(),
        ]);
    }
}
