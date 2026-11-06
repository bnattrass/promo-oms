<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(['slug' => Str::slug(env('SEED_COMPANY_NAME', 'Cape IT Guyz'))], [
            'name' => env('SEED_COMPANY_NAME', 'Cape IT Guyz'),
            'default_gp_percent' => 35,
            'vat_percent' => 15,
            'quote_terms' => "Prices exclude VAT unless stated. Quotes are valid for 14 days and subject to stock availability.\n"
                ."Production starts once artwork is approved and the deposit is received. Lead time is typically 10-15 working days.",
        ]);

        $admin = User::firstOrCreate(['email' => env('SEED_ADMIN_EMAIL', 'admin@example.com')], [
            'name' => env('SEED_ADMIN_NAME', 'Admin'),
            'password' => env('SEED_ADMIN_PASSWORD', 'change-me-now'),
            'is_super_admin' => true,
        ]);
        $admin->companies()->syncWithoutDetaching([$company->id => ['role' => 'admin']]);

        Supplier::firstOrCreate(['code' => 'AMROD'], [
            'name' => 'Amrod',
            'driver' => 'amrod',
            'website' => 'https://amrod.co.za',
            'is_active' => true,
            'sync_enabled' => false, // switch on once the login tests OK
        ]);

        foreach ($this->templates() as $key => [$name, $subject, $body]) {
            EmailTemplate::firstOrCreate(
                ['company_id' => $company->id, 'key' => $key],
                ['name' => $name, 'subject' => $subject, 'body' => $body, 'is_active' => true],
            );
        }
    }

    private function templates(): array
    {
        return [
            'quote_sent' => ['Quote sent', 'Quotation {{ document.number }} from {{ company.name }}',
                "Hi {{ contact.name }}\n\nThank you for your enquiry. Please find quotation {{ document.number }} attached, valid until {{ quote.valid_until }}.\n\nLet me know if you'd like any changes or samples.\n\nKind regards\n{{ user.name }}\n{{ company.name }} | {{ company.phone }}"],
            'quote_follow_up' => ['Quote follow-up', 'Following up: quotation {{ document.number }}',
                "Hi {{ contact.name }}\n\nJust checking in on quotation {{ document.number }} (attached again for convenience). Is there anything you'd like adjusted, or shall we go ahead?\n\nKind regards\n{{ rep.name }}\n{{ company.name }}"],
            'sales_order_confirmation' => ['Order confirmation', 'Order confirmation {{ document.number }}',
                "Hi {{ contact.name }}\n\nThank you for your order. Your sales order {{ document.number }} is attached.\n\nDeposit due: {{ order.deposit }}. We'll send artwork proofs for approval shortly.\n\nKind regards\n{{ user.name }}\n{{ company.name }}"],
            'deposit_reminder' => ['Deposit reminder', 'Deposit reminder for order {{ document.number }}',
                "Hi {{ contact.name }}\n\nA friendly reminder that a deposit of {{ order.deposit_outstanding }} is still due on order {{ document.number }}. Production starts as soon as it is received.\n\nKind regards\n{{ company.name }}"],
            'purchase_order' => ['Purchase order to supplier', 'Purchase order {{ document.number }} - {{ company.name }}',
                "Hi {{ supplier.contact }}\n\nPlease find purchase order {{ document.number }} attached. Kindly confirm receipt, your job number and the expected dispatch date.\n\nArtwork will follow separately for proof approval.\n\nThank you\n{{ user.name }}\n{{ company.name }}"],
        ];
    }
}
