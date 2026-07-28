<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\Payment;
use App\Models\SyncReceipt;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Server half of the offline sync protocol.
 * See docs/architecture/offline-sync.md.
 *
 * Two guarantees hold everything together:
 *
 *  - Idempotency. Every envelope carries a client-generated ULID recorded in
 *    sync_receipts before the write. A replay returns the original outcome
 *    rather than writing twice, which is what lets a device retry freely after
 *    a timeout it cannot interpret.
 *
 *  - Per-envelope isolation. Each envelope applies in its own transaction, so
 *    one bad record cannot block the twenty good ones behind it. A batch is
 *    deliberately not all-or-nothing.
 */
class SyncEngine
{
    /** Entities a device may create or edit offline, and the model behind each. */
    public const ENTITIES = [
        'contact' => Contact::class,
        'item' => Item::class,
        'document' => Document::class,
        'payment' => Payment::class,
    ];

    public function __construct(
        protected DocumentIssuer $issuer,
        protected DocumentNumbers $numbers,
    ) {}

    /**
     * Applies a batch of envelopes, returning one result per envelope in the
     * order received.
     *
     * @param  array<int, array<string, mixed>>  $envelopes
     * @return array<int, array<string, mixed>>
     */
    public function push(array $envelopes, User $user, ?Device $device = null): array
    {
        return collect($envelopes)
            ->map(fn (array $envelope) => $this->applyOne($envelope, $user, $device))
            ->all();
    }

    /** @return array<string, mixed> */
    protected function applyOne(array $envelope, User $user, ?Device $device): array
    {
        $deviceId = $device?->id;

        $id = (string) ($envelope['id'] ?? '');

        if ($id === '') {
            return ['id' => null, 'status' => 'rejected', 'error' => 'missing_envelope_id'];
        }

        // Replay check first: an envelope already seen returns its recorded
        // outcome without touching the database again.
        if ($existing = SyncReceipt::query()->withoutGlobalScopes()->find($id)) {
            return [
                'id' => $id,
                'status' => 'duplicate',
                'server_version' => $existing->server_version,
                'assigned_number' => $existing->assigned_number,
            ];
        }

        $type = (string) ($envelope['entity_type'] ?? '');

        if (! array_key_exists($type, self::ENTITIES)) {
            return $this->record($id, $envelope, $user, $deviceId, 'rejected', error: 'unknown_entity_type');
        }

        try {
            return DB::transaction(fn () => $this->write($id, $envelope, $type, $user, $device));
        } catch (Throwable $e) {
            // A rejected envelope is still recorded, so the device stops retrying
            // something that will never succeed and can show the user why.
            return $this->record($id, $envelope, $user, $deviceId, 'rejected', error: $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    protected function write(string $id, array $envelope, string $type, User $user, ?Device $device): array
    {
        $deviceId = $device?->id;
        /** @var class-string<Model> $modelClass */
        $modelClass = self::ENTITIES[$type];
        $entityId = (string) ($envelope['entity_id'] ?? '');
        $operation = (string) ($envelope['operation'] ?? 'create');
        $payload = (array) ($envelope['payload'] ?? []);

        $existing = $modelClass::query()->find($entityId);

        if ($operation === 'create' && $existing !== null) {
            // The record landed on an earlier attempt whose response was lost.
            return $this->record($id, $envelope, $user, $deviceId, 'duplicate');
        }

        if ($operation === 'update') {
            if ($existing === null) {
                return $this->record($id, $envelope, $user, $deviceId, 'rejected', error: 'record_not_found');
            }

            // An issued document is frozen; an edit made offline against one is
            // a conflict the user must resolve, not something to force through.
            if ($existing instanceof Document && $existing->status->isImmutable()) {
                return $this->record($id, $envelope, $user, $deviceId, 'conflict', error: 'document_already_issued');
            }
        }

        $number = null;

        $model = $existing ?? new $modelClass;
        $model->forceFill($this->sanitise($modelClass, $payload));
        $model->id = $entityId;
        $model->company_id = app(CurrentCompany::class)->id();
        $model->exists = $existing !== null;

        if ($model instanceof Document) {
            $model->created_by ??= $user->id;
        }

        $model->device_id = $deviceId;
        $model->synced_at = now();
        // The sequence stamp comes from the Syncable trait, the same way it does
        // for a record saved through a web form — one source, so a device can
        // never pull a record the server forgot to number.
        $model->save();

        if ($model instanceof Document) {
            $this->syncLines($model, (array) ($envelope['lines'] ?? []));

            // A document created offline as issued still needs its hash and
            // verification token assigned server-side.
            if (($payload['status'] ?? null) === DocumentStatus::Issued->value && blank($model->number)) {
                $model->status = DocumentStatus::Draft;
                $model->save();

                // A device that numbered the document offline keeps that number:
                // the customer is already holding paper with it printed on. It
                // is honoured only after being checked against that device's
                // lease, so a client cannot invent its own sequence.
                $claimed = $this->claimedNumber($envelope, $model, $device);

                $number = $this->issuer->issue($model, $user, $claimed)->number;
            }
        }

        return $this->record($id, $envelope, $user, $deviceId, 'applied', assignedNumber: $number);
    }

    /**
     * Verifies and consumes the number an offline device assigned, if any.
     *
     * Throwing here rejects the whole envelope, which is deliberate: renumbering
     * would leave the server and the customer's copy disagreeing, and that is a
     * far worse outcome than a visible failure the user can act on.
     */
    protected function claimedNumber(array $envelope, Document $document, ?Device $device): ?string
    {
        $number = trim((string) ($envelope['assigned_number'] ?? ''));

        if ($number === '') {
            return null;
        }

        if ($device === null) {
            throw new RuntimeException('A document number was supplied without an identified device.');
        }

        $this->numbers->claim($number, $document->type->value, $device);

        return $number;
    }

    protected function syncLines(Document $document, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $document->lines()->delete();

        foreach ($lines as $index => $line) {
            DocumentLine::create([
                'document_id' => $document->id,
                'description' => (string) ($line['description'] ?? 'Item'),
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit' => (string) ($line['unit'] ?? 'unit'),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'line_total' => (float) ($line['line_total'] ?? 0),
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Only columns the model actually has are written, and never the ones the
     * server owns — a device must not be able to set its own document number,
     * content hash or company.
     *
     * @param  class-string<Model>  $modelClass
     * @return array<string, mixed>
     */
    protected function sanitise(string $modelClass, array $payload): array
    {
        $serverOwned = [
            'id', 'company_id', 'number', 'number_lease_id', 'content_hash',
            'verification_token_id', 'issued_at', 'issued_by', 'sync_sequence',
            'created_at', 'updated_at', 'deleted_at',
        ];

        $columns = (new $modelClass)->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing((new $modelClass)->getTable());

        return collect($payload)
            ->only($columns)
            ->except($serverOwned)
            ->all();
    }

    /** @return array<string, mixed> */
    protected function record(
        string $id,
        array $envelope,
        User $user,
        ?string $deviceId,
        string $status,
        ?string $assignedNumber = null,
        ?string $error = null,
    ): array {
        SyncReceipt::create([
            'id' => $id,
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'entity_type' => (string) ($envelope['entity_type'] ?? 'unknown'),
            'entity_id' => (string) ($envelope['entity_id'] ?? ''),
            'operation' => (string) ($envelope['operation'] ?? 'create'),
            'status' => $status,
            'assigned_number' => $assignedNumber,
            'error' => $error,
        ]);

        return array_filter([
            'id' => $id,
            'status' => $status,
            'assigned_number' => $assignedNumber,
            'error' => $error,
        ], fn ($value) => $value !== null);
    }

    /**
     * Everything changed since each per-entity cursor.
     *
     * @param  array<string, int>  $since
     * @return array<string, mixed>
     */
    public function pull(array $since, int $limit = 500): array
    {
        $changes = [];
        $cursors = [];

        foreach (self::ENTITIES as $type => $modelClass) {
            $from = (int) ($since[$type] ?? 0);

            $rows = $modelClass::query()
                ->where('sync_sequence', '>', $from)
                ->orderBy('sync_sequence')
                ->limit($limit)
                ->get();

            $changes[$type] = $rows->map(fn (Model $model) => $model->toArray())->all();
            $cursors[$type] = (int) ($rows->max('sync_sequence') ?: $from);
        }

        return ['changes' => $changes, 'cursors' => $cursors];
    }
}
