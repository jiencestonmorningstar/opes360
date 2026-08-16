<?php

namespace App\Services\Assets;

use App\Models\AssetTransfer;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place an asset changes site or custodian.
 *
 * The asset's current position and the transfer that produced it are written
 * together or not at all. Letting screens set `asset_location_id` directly is
 * what produces an asset that is somewhere with no record of having gone
 * there — which is precisely the question this feature exists to answer.
 */
class AssetMovements
{
    /**
     * @param  array{to_location_id?: ?string, to_custodian_id?: ?int, reason?: ?string}  $changes
     */
    public function transfer(
        FixedAsset $asset,
        array $changes,
        ?Carbon $on = null,
        ?int $userId = null,
    ): AssetTransfer {
        if ($asset->isDisposed()) {
            throw new RuntimeException(
                "{$asset->name} has been disposed of and cannot be transferred. ".
                'If it is still in use, reopen it before moving it.'
            );
        }

        // Absent keys mean "leave as is"; a present null means "clear it".
        // The distinction matters — handing an asset back to the store is
        // setting the custodian to nobody, not declining to say.
        $toLocation = array_key_exists('to_location_id', $changes)
            ? $changes['to_location_id']
            : $asset->asset_location_id;

        $toCustodian = array_key_exists('to_custodian_id', $changes)
            ? $changes['to_custodian_id']
            : $asset->custodian_id;

        if ($toLocation === $asset->asset_location_id && $toCustodian === $asset->custodian_id) {
            throw new RuntimeException(
                "Nothing would change: {$asset->name} is already there and with the same person."
            );
        }

        $on ??= Carbon::today();

        return DB::transaction(function () use ($asset, $toLocation, $toCustodian, $on, $userId, $changes) {
            $transfer = AssetTransfer::create([
                'company_id' => $asset->company_id,
                'fixed_asset_id' => $asset->id,
                'from_location_id' => $asset->asset_location_id,
                'to_location_id' => $toLocation,
                'from_custodian_id' => $asset->custodian_id,
                'to_custodian_id' => $toCustodian,
                'transferred_on' => $on,
                'reason' => $changes['reason'] ?? null,
                'created_by' => $userId,
            ]);

            $asset->forceFill([
                'asset_location_id' => $toLocation,
                'custodian_id' => $toCustodian,
            ]);

            // Keep the free-text column honest. It is what screens built
            // before locations existed still read, and leaving it showing the
            // old site would make two parts of the product disagree.
            if ($toLocation !== $transfer->from_location_id) {
                $asset->location = $transfer->toLocation?->name;
            }

            $asset->save();

            $asset->emitDomainEvent('asset.transferred', [
                'asset_id' => $asset->id,
                'asset_name' => $asset->name,
                'to_location' => $transfer->toLocation?->name,
                'to_custodian_id' => $toCustodian,
            ]);

            return $transfer;
        });
    }
}
