<?php

namespace Tests\Feature\Platform;

use App\Models\BusinessSetting;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Security\Roles;
use App\Services\Documents\DocumentSender;
use App\Services\Documents\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\Feature\Accounting\InteractsWithLedger;
use Tests\TestCase;

/**
 * B32: screens never show the build's internal notes (spec question numbers, the README) or the
 * server's settings; only the system administrator is told which setting is missing. The
 * financial year's start is a setting, not a note on the balance sheet.
 */
class NoInternalNotesTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    private const INTERNAL = '/\bspec\b[^.]{0,12}(Q-|§)|\(Q-[A-Z]\d|README|MAIL_MAILER|WHATSAPP_ACCESS_TOKEN|PDF_CHROME_BINARY|on Render/';

    public function test_no_ui_string_carries_an_internal_note(): void
    {
        $dictionary = json_decode((string) file_get_contents(lang_path('ar.json')), true);
        $admin = [
            'Email is not set up on this server: set MAIL_MAILER and the MAIL_* settings on Render. The PDF can still be downloaded.',
            'WhatsApp is not set up on this server (WHATSAPP_ACCESS_TOKEN, WHATSAPP_PHONE_NUMBER_ID).',
            'PDF is not set up on this server: it needs Chromium (PDF_CHROME_BINARY). Print the page from the browser instead.',
        ];
        $found = array_values(array_filter(array_keys($dictionary), fn (string $key) => preg_match(self::INTERNAL, $key) && ! in_array($key, $admin, true)));
        $this->assertSame([], $found, 'only the system administrator\'s setup notices name settings');
    }

    public function test_the_screens_that_showed_spec_references_no_longer_do(): void
    {
        $manager = $this->manager();
        $manager->update(['locale' => 'en']);
        foreach ([route('accounting.index'), route('finance-reports.balance-sheet'), route('accounting.posting-rules.index'),
            route('accounting.opening'), route('configuration.index', ['tab' => 'options'])] as $url) {
            $html = $this->actingAs($manager)->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression(self::INTERNAL, strip_tags($html), $url);
        }
    }

    public function test_people_are_told_plainly_and_the_system_administrator_is_told_what_to_set(): void
    {
        config(['documents.email_requires_real_mailer' => true, 'mail.default' => 'log', 'services.whatsapp.token' => null]);
        $manager = $this->manager();
        $manager->update(['locale' => 'en']);
        $admin = User::factory()->create(['locale' => 'en'])->assignRole(Roles::SYSTEM_ADMIN);
        $sender = app(DocumentSender::class);

        $this->assertSame('Email sending isn\'t set up yet. Ask your administrator.', $sender->emailReady($manager));
        $this->assertStringContainsString('MAIL_MAILER', $sender->emailReady($admin));
        $this->assertSame('WhatsApp sending isn\'t set up yet. Ask your administrator.', $sender->whatsappReady($manager));
        $this->assertStringContainsString('WHATSAPP_ACCESS_TOKEN', $sender->whatsappReady($admin));

        $pi = $this->confirmedPi();
        $send = route('documents.send.create', ['type' => 'proforma-invoice', 'id' => $pi->id]);
        $this->mock(PdfRenderer::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(true));
        $this->actingAs($manager)->get($send)->assertOk()
            ->assertSee('Email sending isn&#039;t set up yet. Ask your administrator.', false)
            ->assertSee('WhatsApp sending isn&#039;t set up yet. Ask your administrator.', false)
            ->assertDontSee('MAIL_MAILER')->assertDontSee('WHATSAPP_ACCESS_TOKEN');
        $this->assertInstanceOf(ProformaInvoice::class, $pi);
    }

    public function test_a_missing_pdf_setup_is_told_plainly(): void
    {
        $manager = $this->manager();
        $manager->update(['locale' => 'en']);
        $this->mock(PdfRenderer::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(false));
        $this->actingAs($manager)->get(route('documents.send.create', ['type' => 'proforma-invoice', 'id' => $this->confirmedPi()->id]))->assertOk()
            ->assertSee('PDF files can&#039;t be made yet', false)->assertDontSee('PDF_CHROME_BINARY');
    }

    public function test_the_financial_year_start_is_a_setting(): void
    {
        $manager = $this->manager();
        $manager->update(['locale' => 'en']);
        $this->travelTo('2026-11-19 10:00:00');
        // 100 earned in March, 50 in May.
        $this->posting()->post($this->draft($manager, '1101', '4101', '100', '2026-03-10'), $manager);
        $this->posting()->post($this->draft($manager, '1101', '4101', '50', '2026-05-10'), $manager);

        // From 1 January: all 150 is this year's.
        $page = $this->actingAs($manager)->get(route('finance-reports.balance-sheet', ['as_at' => '2026-11-19']))->assertOk()
            ->assertSee('This financial year started on 2026-01-01')->assertDontSee('name="year_start"', false);
        $this->assertSame(['150.0000', '0.0000'], [$this->figure($page->viewData('report')['current_year']), $this->figure($page->viewData('report')['earlier_years'])]);

        // MASDAR's year starts on 1 April: March is an earlier year.
        $this->actingAs($manager)->put(route('configuration.options.update'), BusinessSetting::current()->only(['pi_currency', 'supplier_currency', 'book_currency',
            'default_deposit_percent', 'pi_terms_template', 'step_warning_days', 'eta_warning_days']) + ['financial_year_start_month' => 4])->assertSessionHasNoErrors();
        $this->assertSame('2026-04-01', BusinessSetting::current()->financialYearStart('2026-11-19'));
        $this->assertSame('2025-04-01', BusinessSetting::current()->financialYearStart('2026-03-31'));
        $page = $this->actingAs($manager)->get(route('finance-reports.balance-sheet', ['as_at' => '2026-11-19']))->assertOk()
            ->assertSee('This financial year started on 2026-04-01');
        $this->assertSame(['50.0000', '100.0000'], [$this->figure($page->viewData('report')['current_year']), $this->figure($page->viewData('report')['earlier_years'])]);
        $this->actingAs($manager)->put(route('configuration.options.update'), ['financial_year_start_month' => 13] + BusinessSetting::current()->only(['pi_currency',
            'supplier_currency', 'book_currency', 'default_deposit_percent', 'pi_terms_template', 'step_warning_days', 'eta_warning_days']))
            ->assertSessionHasErrors('financial_year_start_month');
    }

    private function figure(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }
}
