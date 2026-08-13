<?php

namespace Tests\Feature;

use App\Livewire\Imports\Index as ImportsIndex;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Role;
use App\Models\User;
use App\Services\RecordImporter;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Bringing an existing business's records in.
 *
 * The behaviour worth protecting is what the importer refuses to guess: it
 * never writes during a preview, never doubles a record it has already seen,
 * and never silently drops a column somebody expected it to read.
 */
class RecordImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
    }

    protected function importer(): RecordImporter
    {
        return app(RecordImporter::class);
    }

    public function test_a_preview_writes_nothing(): void
    {
        $csv = "name,phone\nBoulangerie Nkolbisson,+237670000000\n";

        $result = $this->importer()->preview('customers', $csv);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(0, Contact::count(), 'A preview that writes is not a preview.');
    }

    public function test_customers_import_with_their_details(): void
    {
        $csv = "name,phone,email,city\n"
            ."Boulangerie Nkolbisson,+237670000000,contact@boulangerie.cm,Yaoundé\n"
            ."Garage Akwa,+237699000000,,Douala\n";

        $preview = $this->importer()->preview('customers', $csv);
        $result = $this->importer()->commit('customers', $preview['rows'], $this->owner);

        $this->assertSame(['created' => 2, 'updated' => 0], $result);

        $contact = Contact::where('name', 'Boulangerie Nkolbisson')->firstOrFail();

        $this->assertSame('contact@boulangerie.cm', $contact->email);
        $this->assertSame(['+237670000000'], $contact->phones);
        $this->assertSame('Yaoundé', data_get($contact->address, 'city'));
        $this->assertSame('customer', $contact->type);
    }

    /**
     * Every export names its columns differently, and a business that has to
     * rename headers before it can import will do it once and then stop.
     */
    public function test_column_names_are_matched_loosely_and_in_french(): void
    {
        $csv = "Nom,Telephone,Ville\nGarage Akwa,+237699000000,Douala\n";

        $preview = $this->importer()->preview('customers', $csv);
        $this->importer()->commit('customers', $preview['rows'], $this->owner);

        $contact = Contact::where('name', 'Garage Akwa')->firstOrFail();

        $this->assertSame(['+237699000000'], $contact->phones);
        $this->assertSame('Douala', data_get($contact->address, 'city'));
    }

    /** Excel's "CSV UTF-8" writes a BOM that lands inside the first header. */
    public function test_an_excel_byte_order_mark_does_not_break_the_first_column(): void
    {
        $csv = "\xEF\xBB\xBFname,phone\nGarage Akwa,+237699000000\n";

        $result = $this->importer()->preview('customers', $csv);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Garage Akwa', $result['rows'][0]['name']);
    }

    public function test_a_file_with_no_name_column_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->importer()->preview('customers', "phone,city\n+237670000000,Douala\n");
    }

    public function test_rows_without_a_name_are_skipped_with_their_line_number(): void
    {
        $csv = "name,phone\nGarage Akwa,+237699000000\n,+237670000000\n";

        $result = $this->importer()->preview('customers', $csv);

        $this->assertCount(1, $result['rows']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(3, $result['skipped'][0]['line']);
    }

    public function test_duplicates_inside_one_file_are_caught_before_they_are_written(): void
    {
        $csv = "name,phone\nGarage Akwa,+237699000000\nGarage Akwa,+237670000000\n";

        $result = $this->importer()->preview('customers', $csv);

        $this->assertCount(1, $result['rows']);
        $this->assertStringContainsString('line 2', $result['skipped'][0]['reason']);
    }

    /** Running the same import twice must not double the customer book. */
    public function test_importing_the_same_file_twice_updates_rather_than_duplicates(): void
    {
        $csv = "name,phone,email\nGarage Akwa,+237699000000,akwa@example.cm\n";

        $first = $this->importer()->preview('customers', $csv);
        $this->importer()->commit('customers', $first['rows'], $this->owner);

        $second = $this->importer()->preview('customers', $csv);
        $result = $this->importer()->commit('customers', $second['rows'], $this->owner);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertSame(1, Contact::count());
    }

    public function test_columns_the_importer_ignored_are_reported_rather_than_dropped_silently(): void
    {
        $csv = "name,favourite colour,loyalty tier\nGarage Akwa,blue,gold\n";

        $result = $this->importer()->preview('customers', $csv);

        $this->assertContains('favourite colour', $result['unmatched']);
        $this->assertContains('loyalty tier', $result['unmatched']);
    }

    public function test_products_import_with_prices(): void
    {
        $csv = "name,sku,price,cost,unit\n"
            ."Ciment 50kg,CIM-50,6500,5800,bag\n"
            ."Fer à béton 12mm,FER-12,3850,3400,length\n";

        $preview = $this->importer()->preview('products', $csv);
        $result = $this->importer()->commit('products', $preview['rows'], $this->owner);

        $this->assertSame(2, $result['created']);

        $item = Item::where('sku', 'CIM-50')->firstOrFail();

        $this->assertSame('Ciment 50kg', $item->name);
        $this->assertEquals(6500, $item->price);
        $this->assertEquals(5800, $item->cost);
    }

    /**
     * Amounts arrive formatted by whoever exported them. "1 250 000" and
     * "1,250,000" are the same number, and "1.250,50" is a European decimal.
     */
    public function test_amounts_survive_the_separators_people_actually_use(): void
    {
        $csv = "name,price\n"
            ."Spaced,\"1 250 000\"\n"
            ."Commas,\"1,250,000\"\n"
            ."European,\"1.250,50\"\n";

        $preview = $this->importer()->preview('products', $csv);
        $this->importer()->commit('products', $preview['rows'], $this->owner);

        $this->assertEquals(1250000, Item::where('name', 'Spaced')->value('price'));
        $this->assertEquals(1250000, Item::where('name', 'Commas')->value('price'));
        $this->assertEquals(1250.50, Item::where('name', 'European')->value('price'));
    }

    public function test_a_product_matched_by_sku_is_updated_not_duplicated(): void
    {
        $this->importer()->commit('products', [
            ['name' => 'Ciment 50kg', 'sku' => 'CIM-50', 'price' => '6500'],
        ], $this->owner);

        $result = $this->importer()->commit('products', [
            ['name' => 'Ciment 50kg (nouveau sac)', 'sku' => 'CIM-50', 'price' => '7000'],
        ], $this->owner);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertSame(1, Item::count());
        $this->assertEquals(7000, Item::where('sku', 'CIM-50')->value('price'));
    }

    /**
     * A business's records are in an .xlsx far more often than a .csv, and
     * "export to CSV first" is a step people get wrong or refuse outright.
     *
     * @return string the path to a real workbook on disk
     */
    protected function makeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                // Explicitly typed: left to itself the writer reads "+237…" as
                // a formula and stores it as a number, which is the writer's
                // behaviour and not the importer's, and would make this test
                // about the wrong thing.
                $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'opes-test-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_an_excel_workbook_imports_without_being_converted_first(): void
    {
        $path = $this->makeXlsx([
            ['name', 'phone', 'city'],
            ['Boulangerie Nkolbisson', '+237670000000', 'Yaoundé'],
            ['Garage Akwa', '+237699000000', 'Douala'],
        ]);

        $preview = $this->importer()->preview('customers', file_get_contents($path));
        $result = $this->importer()->commit('customers', $preview['rows'], $this->owner);

        @unlink($path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(
            ['+237670000000'],
            Contact::where('name', 'Boulangerie Nkolbisson')->value('phones')
        );
    }

    /**
     * Workbooks carry trailing formatting that reads back as rows of empty
     * strings. They are not data, and must not be reported as skipped either.
     */
    public function test_a_workbooks_trailing_blank_rows_are_not_counted(): void
    {
        $path = $this->makeXlsx([
            ['name', 'phone'],
            ['Garage Akwa', '+237699000000'],
            ['', ''],
            ['', ''],
        ]);

        $result = $this->importer()->preview('customers', file_get_contents($path));

        @unlink($path);

        $this->assertCount(1, $result['rows']);
        $this->assertSame([], $result['skipped']);
    }

    /**
     * Excel stores a phone number as a number whenever it can, and PHP prints
     * a large float in scientific notation — so without care a customer's
     * number imports as "2.3767E+11".
     */
    public function test_a_number_stored_as_a_number_does_not_arrive_in_scientific_notation(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'name');
        $sheet->setCellValue('B1', 'phone');
        $sheet->setCellValue('A2', 'Garage Akwa');
        $sheet->setCellValue('B2', 237670000000);

        $path = tempnam(sys_get_temp_dir(), 'opes-test-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $result = $this->importer()->preview('customers', file_get_contents($path));

        @unlink($path);

        $this->assertSame('237670000000', $result['rows'][0]['phone']);
    }

    /** A file named .csv that is really a workbook is a common way this fails. */
    public function test_a_workbook_is_detected_by_content_not_by_its_name(): void
    {
        $path = $this->makeXlsx([['name'], ['Garage Akwa']]);

        Livewire::actingAs($this->owner)
            ->test(ImportsIndex::class)
            ->set('file', UploadedFile::fake()->createWithContent('records.csv', file_get_contents($path)))
            ->assertSet('previewed', true)
            ->assertSet('error', null)
            ->assertSee('Garage Akwa');

        @unlink($path);
    }

    public function test_the_screen_previews_then_imports(): void
    {
        $csv = "name,phone\nBoulangerie Nkolbisson,+237670000000\n";

        Livewire::actingAs($this->owner)
            ->test(ImportsIndex::class)
            ->set('file', UploadedFile::fake()->createWithContent('customers.csv', $csv))
            ->assertSet('previewed', true)
            ->assertSee('Boulangerie Nkolbisson')
            // Still nothing written at this point.
            ->tap(fn () => $this->assertSame(0, Contact::count()))
            ->call('commit')
            ->assertSet('previewed', false);

        $this->assertSame(1, Contact::count());
    }

    public function test_a_cashier_cannot_import_products(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Livewire::actingAs($cashier)
            ->test(ImportsIndex::class)
            ->set('type', 'products')
            ->call('preview')
            ->assertForbidden();
    }
}
