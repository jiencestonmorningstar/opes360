<?php

namespace App\Console\Commands;

use App\Support\TokenAbilities;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Writes the OpenAPI description of the v1 API.
 *
 * Generated from the router rather than hand-written, for the same reason the
 * install schema is generated from the migrations: a spec maintained by hand
 * drifts from the thing it describes, and a spec that lies is worse than none
 * — a client generated from it fails in ways that look like server bugs.
 *
 * What cannot be read from the router — what an endpoint is *for*, and the
 * rules it enforces — is annotated below, deliberately in one table so that
 * adding a route without describing it is visible in review.
 */
class ExportOpenApi extends Command
{
    protected $signature = 'opes:export-openapi {--out=public/openapi.json}';

    protected $description = 'Write the OpenAPI description of the v1 API';

    /**
     * Prose for the routes that have some. Keyed by route name minus the
     * `api.v1.` prefix.
     *
     * @var array<string, string>
     */
    protected array $summaries = [
        'tokens.store' => 'Exchange credentials for an API token. The only endpoint that does not need one.',
        'tokens.destroy' => 'Revoke the token making the request.',
        'user' => 'Who this token belongs to, which business it acts on, and what it may do.',

        'contacts.index' => 'List customers and suppliers.',
        'contacts.store' => 'Add a customer or supplier.',
        'contacts.show' => 'One contact.',
        'contacts.update' => 'Change a contact. Merges: fields you omit keep their values.',
        'contacts.destroy' => 'Remove a contact.',

        'items.index' => 'List products and services.',
        'items.store' => 'Add a product or service. Stock is not settable here.',
        'items.show' => 'One product or service.',
        'items.update' => 'Change a product or service.',
        'items.destroy' => 'Remove a product or service.',

        'documents.index' => 'List quotations, invoices and the rest.',
        'documents.store' => 'Create a draft document, or issue it in the same call with "issue": true.',
        'documents.show' => 'One document, with its lines.',
        'documents.issue' => 'Issue a draft: assigns its number, freezes it, and enters it in the books.',
        'documents.void' => 'Cancel an issued document. Refused while payments sit against it.',
        'documents.convert' => 'Quotation to invoice, proforma to invoice.',
        'documents.credit-note' => 'Credit part or all of a paid invoice.',
        'documents.destroy' => 'Delete a draft. Issued documents cannot be deleted.',

        'deals.index' => 'List deals in the pipeline.',
        'deals.store' => 'Add a deal. Needs either a contact or a lead name.',
        'deals.show' => 'One deal.',
        'deals.update' => 'Change a deal.',
        'deals.destroy' => 'Remove a deal.',
        'deals.move' => 'Move a deal to another stage, keeping its closure honest.',
        'deals.invoice' => 'Turn a won deal into a draft invoice.',

        'payments.index' => 'List payments received.',
        'payments.store' => 'Record a payment against an issued document, and issue its receipt.',
        'payments.show' => 'One payment, with its receipt.',
        'payments.refund' => 'Give money back. The payment and its receipt survive; the invoice becomes owed again.',

        'expenses.index' => 'List supplier bills and spending.',
        'expenses.store' => 'Record an expense. vat_rate is a fraction, not a percentage.',
        'expenses.show' => 'One expense.',
        'expenses.settle' => 'Pay an expense, in part or in full.',
        'expenses.void' => 'Cancel an expense recorded in error. Its ledger entry is reversed.',

        'accounting.accounts' => 'The chart of accounts.',
        'accounting.trial-balance' => 'Trial balance for a period.',
        'accounting.income-statement' => 'Income statement for a period.',
        'accounting.balance-sheet' => 'Balance sheet as at a date.',
        'accounting.journal' => 'Journal entries for a period.',

        'employees.index' => 'List staff. Identity numbers and bank details are never returned.',
        'employees.store' => 'Add a staff record.',
        'employees.show' => 'One staff record.',
        'employees.update' => 'Change a staff record.',

        'payroll.runs' => 'List payroll runs. Read only: approving a month is not an API act.',
        'payroll.payslips' => 'The payslips in one run.',

        'events.index' => 'List events.',
        'events.show' => 'One event.',
        'events.ticket-types' => 'The ticket types on an event, with their prices and what is left.',
        'events.tickets.index' => 'The tickets issued for an event.',
        'events.tickets.store' => 'Sell a ticket. Refused once the type is sold out.',
        'events.tickets.check-in' => 'Check a ticket in at the door.',

        'forms.index' => 'List forms.',
        'forms.show' => 'One form.',
        'forms.responses' => 'The answers submitted to a form.',

        'loyalty.show' => "A customer's loyalty balance.",
        'loyalty.transactions' => 'The points a customer has earned and spent.',
        'loyalty.redeem' => 'Spend a customer\'s points.',

        'webhooks.index' => 'List registered webhook endpoints. The signing secret is never returned here.',
        'webhooks.store' => 'Register an endpoint. The response carries the signing secret, and it is the only one that ever will.',
        'webhooks.show' => 'One endpoint.',
        'webhooks.update' => 'Change an endpoint. Re-enabling one clears the failure count that switched it off.',
        'webhooks.destroy' => 'Remove an endpoint. Nothing further is sent to it.',
        'webhooks.deliveries' => 'What we tried to send, with the body, the response and the error.',
        'webhooks.redeliver' => 'Send a failed delivery again, with the body it originally carried and the same delivery id.',

        'partners.clients' => "A secretariat's client book.",
        'partners.clients.store' => 'Add a client to the book.',
        'partners.clients.show' => 'One client.',
        'partners.clients.update' => 'Change a client.',
        'partners.earnings' => 'What the programme has earned, and what is withdrawable now.',
        'partners.commissions' => 'The commission lines behind that balance.',
        'partners.payouts' => 'Payouts requested and settled.',
        'partners.payouts.request' => 'Ask for the balance to be paid out. The amount is not a parameter.',

        'vip.tiers' => 'The membership tiers this business offers.',
        'vip.tiers.store' => 'Add a tier.',
        'vip.tiers.update' => 'Change a tier. Affects future sales only — members keep the terms they bought.',
        'vip.memberships' => 'Memberships sold. Filter with active=1.',
        'vip.memberships.show' => 'One membership, on the terms it was sold under.',
        'vip.memberships.sell' => 'Sell a membership. Raises a real invoice for the fee and starts the term.',
        'vip.memberships.cancel' => 'Cancel a membership, with a reason. The record is kept.',

        'imports.preview' => 'Read a CSV or Excel file and report what would happen. Writes nothing.',
        'imports.store' => 'Import the rows a preview showed.',
    ];

    /** Endpoints where a retry must not repeat the work. */
    protected array $idempotent = [
        'payments.store', 'expenses.store', 'expenses.settle', 'expenses.void',
        'documents.store', 'documents.issue', 'documents.void',
        'documents.convert', 'documents.credit-note',
        'events.tickets.store', 'loyalty.redeem', 'payments.refund',
        'partners.payouts.request',
        'vip.memberships.sell',
    ];

    public function handle(): int
    {
        $paths = [];
        $undocumented = [];

        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), 'api/v1/')) {
                continue;
            }

            $name = Str::after((string) $route->getName(), 'api.v1.');
            $path = '/'.$route->uri();

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                if (! isset($this->summaries[$name])) {
                    $undocumented[$name] = true;
                }

                $paths[$path][strtolower($method)] = $this->operation($route, $name, $method);
            }
        }

        ksort($paths);

        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('opes.brand.name').' API',
                'version' => '1.0.0',
                'description' => 'Token-authenticated access to a business\'s records. '
                    ."A token can never do more than the person it belongs to could do while signed in.\n\n"
                    .'Generated from the router by `php artisan opes:export-openapi` — do not edit by hand.',
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/')]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'parameters' => [
                    'idempotencyKey' => [
                        'name' => 'Idempotency-Key',
                        'in' => 'header',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'A key you generate. Repeating a request with the same key '
                            .'replays the first response instead of doing the work again.',
                    ],
                ],
            ],
            'security' => [['bearerAuth' => []]],
            'tags' => $this->tags(),
            'paths' => $paths,
        ];

        $out = base_path($this->option('out'));
        @mkdir(dirname($out), 0755, true);
        file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info(sprintf('Wrote %s (%d paths).', $this->option('out'), count($paths)));

        if ($undocumented !== []) {
            // Not a failure — a new route should not break the build — but it
            // is the one thing about this file that rots quietly.
            $this->warn('No summary for: '.implode(', ', array_keys($undocumented)));
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    protected function operation(RoutingRoute $route, string $name, string $method): array
    {
        $operation = [
            'operationId' => $name === '' ? null : Str::camel(str_replace('.', ' ', $name)),
            'summary' => $this->summaries[$name] ?? Str::headline($name),
            'tags' => [$this->tagFor($name)],
            'parameters' => [],
            'responses' => $this->responses($name, $method),
        ];

        foreach ($this->pathParameters($route) as $parameter) {
            $operation['parameters'][] = $parameter;
        }

        if (in_array($name, $this->idempotent, true)) {
            $operation['parameters'][] = ['$ref' => '#/components/parameters/idempotencyKey'];
        }

        $scope = $this->scopeFor($route);

        if ($scope !== null) {
            $operation['description'] = 'Requires the `'.$scope.'` token scope, '
                .'on top of the permission the same action needs in the app.';
            $operation['security'] = [['bearerAuth' => [$scope]]];
        }

        $operation = array_filter($operation, fn ($v) => $v !== null && $v !== []);

        if ($name === 'tokens.store') {
            /*
             * The one endpoint reachable without a token, so it overrides the
             * document-level security requirement with an empty one. Set after
             * the filter above, which strips empty values and would otherwise
             * remove exactly the declaration that carries the meaning — leaving
             * a spec that says you need a token to get a token.
             */
            $operation['security'] = [];
        }

        return $operation;
    }

    /** @return array<int, array<string, mixed>> */
    protected function pathParameters(RoutingRoute $route): array
    {
        return collect($route->parameterNames())
            ->map(fn (string $parameter) => [
                'name' => $parameter,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    protected function responses(string $name, string $method): array
    {
        $ok = match (true) {
            $method === 'POST' && Str::endsWith($name, ['.store', 'tokens.store']) => '201',
            $method === 'DELETE' => '204',
            default => '200',
        };

        $responses = [
            $ok => ['description' => 'Success'],
            '401' => ['description' => 'No token, or a revoked one'],
            '403' => ['description' => 'The permission, module switch or token scope says no'],
            '404' => ['description' => 'Not found, or belongs to another business'],
            '422' => ['description' => 'Validation failed, or a rule of the domain refused'],
            '429' => ['description' => 'Rate limited'],
        ];

        if ($name === 'tokens.store') {
            unset($responses['401'], $responses['403'], $responses['404']);
        }

        return $responses;
    }

    protected function scopeFor(RoutingRoute $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && Str::startsWith($middleware, 'ability:')) {
                return Str::after($middleware, 'ability:');
            }
        }

        return null;
    }

    protected function tagFor(string $name): string
    {
        return Str::headline(Str::before($name, '.') ?: 'General');
    }

    /** @return array<int, array<string, string>> */
    protected function tags(): array
    {
        $scopes = collect(TokenAbilities::CATALOGUE)
            ->map(fn (string $label, string $key) => "`{$key}` — {$label}")
            ->implode('; ');

        return [[
            'name' => 'Scopes',
            'description' => 'Token scopes: '.$scopes.'. A token minted without any holds them all.',
        ]];
    }
}
