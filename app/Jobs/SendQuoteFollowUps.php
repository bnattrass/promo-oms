<?php

namespace App\Jobs;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Activity;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Orders\DocumentMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automatic follow-ups:
 *  - sent quotes past next_follow_up_at get the quote_follow_up email (max 2), then expire after valid_until
 *  - orders awaiting deposit get a deposit_reminder every 3 working days
 */
class SendQuoteFollowUps implements ShouldQueue
{
    use Queueable;

    public function handle(DocumentMailer $mailer): void
    {
        Quote::query()
            ->where('status', QuoteStatus::Sent)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', today())
            ->update(['status' => QuoteStatus::Expired->value]);

        Quote::query()
            ->with('company', 'customer', 'contact', 'salesRep')
            ->where('status', QuoteStatus::Sent)
            ->where('next_follow_up_at', '<=', now())
            ->each(function (Quote $quote) use ($mailer) {
                $sent = $quote->activities()->where('type', 'email_sent')->where('properties->template', 'quote_follow_up')->count();
                if ($sent >= 2) {
                    $quote->update(['next_follow_up_at' => null]);

                    return;
                }
                try {
                    $mailer->send($quote, 'quote_follow_up');
                    $quote->update(['next_follow_up_at' => now()->addWeekdays($quote->company->quote_follow_up_days ?: 3)]);
                } catch (Throwable $e) {
                    Log::warning("Follow-up failed for {$quote->number}: {$e->getMessage()}");
                }
            });

        SalesOrder::query()
            ->with('company', 'customer', 'contact')
            ->where('status', SalesOrderStatus::AwaitingDeposit)
            ->each(function (SalesOrder $order) use ($mailer) {
                $last = $order->activities()->where('properties->template', 'deposit_reminder')->latest()->value('created_at');
                if ($last && $last->gt(now()->subWeekdays(3))) {
                    return;
                }
                if ($order->created_at->gt(now()->subWeekdays(1))) {
                    return; // give the confirmation a day before the first reminder
                }
                try {
                    $mailer->send($order, 'deposit_reminder');
                } catch (Throwable $e) {
                    Log::warning("Deposit reminder failed for {$order->number}: {$e->getMessage()}");
                    Activity::log($order, 'follow_up', 'Deposit reminder could not be emailed: '.$e->getMessage());
                }
            });
    }
}
