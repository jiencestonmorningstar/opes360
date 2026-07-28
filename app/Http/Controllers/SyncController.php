<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\SyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

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
        ]);

        $device = $this->resolveDevice($request);

        // Read the envelopes from the raw input, not from validate()'s return:
        // validate() hands back only the keys it was given rules for, which
        // would silently drop every payload and line item.
        return response()->json([
            'results' => $engine->push($request->input('envelopes'), $request->user(), $device?->id),
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
