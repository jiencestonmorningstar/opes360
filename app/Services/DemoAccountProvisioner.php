<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Event;
use App\Models\Form;
use App\Models\Item;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationToken;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a demo request into a working account: one company, one owner, and
 * exactly one piece of content per module — enough that every screen the
 * person lands on shows something real rather than an empty state, without
 * pretending to be a populated business the way the full demo seeder does.
 */
class DemoAccountProvisioner
{
    /** @return array{company: Company, user: User, password: string} */
    public function provision(string $ownerName, string $email, string $businessName, ?string $industry): array
    {
        $password = Str::password(12);

        return DB::transaction(function () use ($ownerName, $email, $businessName, $industry, $password) {
            $user = User::create([
                'name' => trim($ownerName),
                'email' => strtolower(trim($email)),
                'password' => $password,
            ]);

            // Not mass-assignable on purpose (verification status must never
            // come from request input) — a demo account starts verified so
            // nothing blocks the owner from using it immediately.
            $user->forceFill(['email_verified_at' => now()])->save();

            $company = Company::create([
                'slug' => $this->uniqueSlug($businessName),
                'name' => trim($businessName),
                'industry' => $industry ?: null,
                'currency' => 'USD',
                'email' => strtolower(trim($email)),
                'owner_id' => $user->id,
                'account_type' => 'demo',
                'demo_expires_at' => now()->addDays(14),
            ]);

            $company->users()->attach($user->id, [
                'role_id' => Role::where('slug', Role::OWNER)->value('id'),
                'job_title' => 'Business Owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            // The books start the day the business does. Seeded here rather
            // than lazily so an accountant opening the chart on day one finds
            // something to work from, and so the first invoice has somewhere
            // to post to.
            ChartOfAccounts::seed($company);

            $user->forceFill(['current_company_id' => $company->id])->save();

            app(CurrentCompany::class)->as($company, function () use ($company, $user) {
                VerificationToken::create([
                    'token' => VerificationToken::newToken(),
                    'subject_type' => Company::class,
                    'subject_id' => $company->id,
                ]);

                $this->seedOneOfEach($company, $user);
            });

            return ['company' => $company, 'user' => $user, 'password' => $password];
        });
    }

    protected function seedOneOfEach(Company $company, User $user): void
    {
        $contact = Contact::create([
            'type' => 'customer',
            'name' => 'Sample Customer',
            'email' => 'customer@example.com',
            'balance' => 0,
        ]);

        Item::create([
            'name' => 'Sample Product',
            'sku' => 'DEMO-001',
            'type' => 'product',
            'price' => 25,
        ]);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'USD',
            'subtotal' => 25,
            'total' => 25,
            'balance' => 25,
            'created_by' => $user->id,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'item_id' => null,
            'description' => 'Sample Product',
            'quantity' => 1,
            'unit_price' => 25,
            'line_total' => 25,
        ]);

        app(DocumentIssuer::class)->issue($document, $user);

        $template = DocumentTemplates::find('service_agreement');
        BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Sample Service Agreement',
            'recipient' => 'Sample Customer',
            'body' => $template['body'],
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        Form::create([
            'title' => 'Sample Feedback Form',
            'description' => 'Try editing this form, then open the share link.',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [
                ['id' => 'demo-name', 'type' => 'short_text', 'label' => 'Your name', 'help' => '', 'required' => true, 'options' => []],
                ['id' => 'demo-feedback', 'type' => 'long_text', 'label' => 'Feedback', 'help' => '', 'required' => false, 'options' => []],
            ],
            'created_by' => $user->id,
        ]);

        $event = Event::create([
            'title' => 'Sample Event',
            'description' => 'Try editing this event, then open the share link.',
            'venue' => 'Your venue',
            'starts_at' => now()->addWeek(),
            'status' => 'draft',
            'share_token' => Event::newShareToken(),
            'created_by' => $user->id,
        ]);

        $event->ticketTypes()->create([
            'company_id' => $company->id,
            'name' => 'General admission',
            'price' => 10,
            'quantity' => 50,
        ]);
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;

        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
