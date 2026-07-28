<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Device;
use App\Services\DocumentNumbers;
use App\Services\SyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sync API, versioned so a device running older code keeps working after a
 * server deploy — an app on a phone in a market cannot be upgraded on demand.
 */
class SyncController extends Controller
{
    public function push(Request $request, SyncEngine $engine): JsonResponse
    {
        $request->validate([
            'device_id' => ['nullable', 'string'],
            'envelopes' => ['required', 'array', 'max:200'],
            'envelopes.*.id' => ['required', 'string', 'max:40'],
            'envelopes.*.entity_type' => ['required', 'string'],
            'envelopes.*.entity_id' => ['required', 'string', 'max:40'],
            'envelopes.*.operation' => ['required', 'in:create,update,void'],
            'envelopes.*.assigned_number' => ['nullable', 'string', 'max:40'],
        ]);

        $device = $this->resolveDevice($request);

        // Read the envelopes from the raw input, not from validate()'s return:
        // validate() hands back only the keys it was given rules for, which
        // would silently drop every payload and line item.
        return response()->json([
            'results' => $engine->push($request->input('envelopes'), $request->user(), $device),
            // Echoed so a device that registered itself on this call stores the
            // id the server gave it, and every later push and lease is
            // recognised as the same device.
            'device_id' => $device?->id,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function pull(Request $request, SyncEngine $engine): JsonResponse
    {
        $since = collect($request->array('since'))
            ->map(fn ($value) => (int) $value)
            ->all();

        $result = $engine->pull($since, min(500, max(1, $request->integer('limit', 500))));

        return response()->json($result + ['server_time' => now()->toIso8601String()]);
    }

    /**
     * Leases a block of document numbers so the device can number documents
     * while offline.
     *
     * Requested per type: a phone that only writes receipts should not be
     * holding a hole in the invoice sequence. The response is everything the
     * client needs to compose numbers without asking again.
     */
    public function lease(Request $request, DocumentNumbers $numbers): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['nullable', 'string'],
            'type' => ['required', 'string', Rule::in($this->leasableTypes())],
            'size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // Leasing numbers is issuing them in advance, so it takes the same right.
        abort_unless($request->user()->can('sales.issue'), 403);

        $device = $this->resolveDevice($request);
        $lease = $numbers->leaseFor($device, $validated['type'], (int) ($validated['size'] ?? 25));

        return response()->json([
            // Echoed for the same reason as on push: a device that registered
            // itself on this very call must learn the id it was given, or the
            // next request registers a *second* device and strands the block
            // this one just leased.
            'device_id' => $device->id,
            'lease_id' => $lease->id,
            'type' => $lease->document_type,
            'prefix' => DocumentNumbers::prefixFor($lease->document_type),
            'year' => (int) $lease->year,
            'range_start' => (int) $lease->range_start,
            'range_end' => (int) $lease->range_end,
            'next_available' => (int) $lease->next_available,
            'remaining' => $lease->remaining(),
            'expires_at' => $lease->expires_at?->toIso8601String(),
        ]);
    }

    /** @return array<int, string> */
    protected function leasableTypes(): array
    {
        return array_merge(
            array_column(DocumentType::cases(), 'value'),
            ['receipt'],
        );
    }

    /**
     * Registers the calling device on first contact so it appears in Settings
     * and can be revoked. A revoked device is refused outright.
     */
    protected function resolveDevice(Request $request): ?Device
    {
        $name = $request->string('device_name')->toString()
            ?: Str::limit((string) $request->userAgent(), 60, '');

        $deviceId = $request->string('device_id')->toString();

        $device = $deviceId ? Device::find($deviceId) : null;

        if ($device === null) {
            $device = Device::create([
                'user_id' => $request->user()->id,
                'name' => $name ?: 'Unknown device',
                'platform' => Str::limit((string) $request->userAgent(), 40, ''),
                'token_hash' => hash('sha256', Str::uuid()->toString()),
            ]);
        }

        abort_if($device->isRevoked(), 403, 'This device has been revoked.');

        $device->forceFill(['last_synced_at' => now()])->save();

        return $device;
    }
}
