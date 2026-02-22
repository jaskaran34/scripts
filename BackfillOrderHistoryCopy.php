<?php

namespace App\Console\Commands\Seed;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\OrderHistory;
use App\Models\Member;
use Illuminate\Support\Facades\Log;
use App\Services\NotifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class BackfillOrderHistory extends Command
{
    protected $signature = 'seed:backfill-order-history 
                            {--chunk=100 : Number of members to process per chunk}
                            {--member= : Process specific member by mukafa_no}
                            {--dry-run : Preview calculations without writing data}
                            {--force : Skip confirmation prompts}
                            {--include-synced : Process members even if sync_flag is true}
                            {--truncate : Truncate order_histories before rebuilding}';

    protected $description = 'Rebuild OrderHistory from transactions ensuring balances align with NotifyService and per-transaction rules.';

    protected NotifyService $notifyService;

    protected int $processedCount = 0;
    protected int $syncedCount = 0;
    protected int $failedCount = 0;
    protected int $skippedCount = 0;
    protected int $errorCount = 0;

    protected array $failedMembers = [];

    protected array $purchaseTypes = ['P', 'C'];
    protected array $benefitTypes = ['W', 'T', 'B'];
    protected array $allowedTranTypes = ['W', 'P', 'R', 'T', 'B', 'C'];

    public function __construct(NotifyService $notifyService)
    {
        parent::__construct();
        $this->notifyService = $notifyService;
    }

    public function handle()
    {
        $this->info('🚀 Rebuilding OrderHistory ledger...');
        if ($this->option('dry-run')) {
            $this->warn('⚠️  DRY RUN MODE - Database will not be changed.');
            $this->newLine();
        }

        $query = Member::query();

        if (!$this->option('include-synced')) {
            $query->where(function ($q) {
                $q->whereNull('sync_flag')->orWhere('sync_flag', false);
            });
            $this->info('📍 Processing members with sync_flag NULL or false. Use --include-synced to rebuild everyone.');
        } else {
            $this->info('📍 Processing all members (include-synced enabled).');
        }

        if ($member = $this->option('member')) {
            $query->where('unique_identifier', $member);
            $this->info("   Restricting to mukafa_no {$member}");
        }

        $totalMembers = $query->count();
        if ($totalMembers === 0) {
            $this->info('✅ No members matched the selection.');
            return Command::SUCCESS;
        }

        if (!$this->option('dry-run') && !$this->option('force')) {
            if (!$this->confirm("About to rebuild OrderHistory for {$totalMembers} member(s). Continue?")) {
                $this->info('❌ Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        if ($this->option('truncate')) {
            if ($this->option('dry-run')) {
                $this->warn('🧹 Skipping truncate (dry-run).');
            } else {
                $this->info('🧹 Truncating order_histories table...');
                DB::statement('TRUNCATE TABLE order_histories');
            }
        }

        $chunkSize = (int) $this->option('chunk');
        $bar = $this->output->createProgressBar($totalMembers);
        $bar->start();

        $query->chunk($chunkSize, function ($members) use ($bar) {
            foreach ($members as $member) {
                try {
                    $this->processMember($member);
                } catch (\Throwable $e) {
                    $this->errorCount++;
                    Log::error("❌ Failed to rebuild order history for {$member->unique_identifier}", [
                        'member_id' => $member->id,
                        'mukafa_no' => $member->unique_identifier,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                } finally {
                    $bar->advance();
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info('📊 Summary');
        $this->line("   Processed: {$this->processedCount}");
        $this->line("   Synced: {$this->syncedCount}");
        $this->line("   Failed validation: {$this->failedCount}");
        $this->line("   Skipped (no transactions): {$this->skippedCount}");
        $this->line("   Errors: {$this->errorCount}");
        $this->newLine();

        if (!empty($this->failedMembers)) {
            $this->warn('⚠️  Members that failed reconciliation:');
            foreach (array_slice($this->failedMembers, 0, 10) as $failure) {
                $reason = $failure['reason'] ?? '';
                $line = "   • {$failure['mukafa_no']} | calc balance {$failure['calculated_balance']} vs notify {$failure['notify_balance']} | calc pending {$failure['calculated_pending']} vs notify {$failure['notify_pending']} | welcome={$failure['has_welcome']}";
                if ($reason !== '') {
                    $line .= " | reason: {$reason}";
                }
                $this->line($line);
            }
            if (count($this->failedMembers) > 10) {
                $this->line('   … additional failures omitted for brevity.');
            }
        } elseif ($this->processedCount > 0) {
            $this->info('🎉 All processed members reconciled successfully.');
        }

        return Command::SUCCESS;
    }

    protected function processMember(Member $member): void
    {
        $transactions = $this->sortTransactions(
            $this->getTransactionsForMember($member)
        );

        $completedOrders = $this->identifyCompletedPurchases($transactions);

        if ($transactions->isEmpty()) {
            $this->skippedCount++;
            if (!$this->option('dry-run')) {
                $member->sync_flag = true;
                $member->save();
            }
            return;
        }

        if (!$this->option('dry-run') && !$this->option('truncate')) {
            OrderHistory::where('mukafa_no', $member->unique_identifier)->delete();
        }

        $ledger = $this->initializeLedgerState();
        $historyPayloads = [];

        foreach ($transactions as $transaction) {
            if ($this->shouldSkipPendingDuplicate($transaction, $completedOrders)) {
                continue;
            }

            $entry = $this->buildHistoryEntry($member, $transaction, $ledger);

            if ($entry === null) {
                continue;
            }

            $validatedEntry = $this->validateHistoryEntry($member, $entry);

            if ($validatedEntry === null) {
                return;
            }

            $historyPayloads[] = $validatedEntry;
        }

        if (empty($historyPayloads)) {
            $this->skippedCount++;
            if (!$this->option('dry-run')) {
                $member->sync_flag = false;
                $member->save();
            }
            return;
        }

        if (!$this->option('dry-run')) {
            DB::transaction(function () use ($historyPayloads) {
                foreach ($historyPayloads as $payload) {
                    OrderHistory::create($payload);
                }
            });
        }

        $notifyBalance = (float) $this->notifyService->balance($member->unique_identifier);
        $notifyPending = (float) $this->notifyService->pendingbalance($member->unique_identifier);
        $matches = $this->ledgersMatch($ledger, $notifyBalance, $notifyPending);

        if ($matches) {
            $this->syncedCount++;
            if (!$this->option('dry-run')) {
                $member->sync_flag = true;
                $member->save();
            }
        } else {
            $this->markLedgerFailure($member, 'NotifyService mismatch after rebuild', [
                'calculated_balance' => $ledger['balance'],
                'notify_balance' => $notifyBalance,
                'calculated_pending' => $ledger['pending'],
                'notify_pending' => $notifyPending,
                'has_welcome' => $ledger['hasWelcome'],
            ]);
        }

        $this->processedCount++;
    }

    protected function getTransactionsForMember(Member $member): Collection
    {
        return Transaction::withTrashed()
            ->where('member_id', $member->id)
            ->whereIn('tran_type', $this->allowedTranTypes)
            ->whereNull('settlement_tran')
            ->whereIn('status', ['pending', 'completed', 'cancelled', 'refunded'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    protected function sortTransactions(Collection $transactions): Collection
    {
        return $transactions
            ->sortBy(function (Transaction $transaction) {
                return $this->getTransactionMoment($transaction)->format('Y-m-d H:i:s.u');
            })
            ->values();
    }

    protected function initializeLedgerState(): array
    {
        return [
            'balance' => 0.0,
            'pending' => 0.0,
            'pendingByOrder' => [],
            'creditedByOrder' => [],
            'hasWelcome' => false,
        ];
    }

    protected function buildHistoryEntry(Member $member, Transaction $transaction, array &$ledger): ?array
    {
        $tranType = $transaction->tran_type ?? 'P';
        if (!in_array($tranType, $this->allowedTranTypes, true)) {
            return null;
        }

        $orderId = $this->getOrderIdentifier($transaction);
        $rawStatus = strtolower((string) ($transaction->status ?? 'pending'));
        
        // Use points from transactions table (not calculated)
        // For purchase transactions: points should already be in the transactions table
        $points = $this->formatNumber(abs((float) ($transaction->points ?? 0)));
        $purchaseAmount = $this->formatNumber((float) ($transaction->purchase_amount ?? 0));

        // Store balance BEFORE this transaction
        $balanceBefore = $ledger['balance'];
        $pendingBefore = $ledger['pending'];

        $history = [
            'transaction_id' => $transaction->id,
            'order_id' => $orderId,
            'mukafa_no' => $member->unique_identifier,
            'original' => $this->shouldCapturePurchase($tranType, $rawStatus) ? $purchaseAmount : 0,
            'purchase' => $this->shouldCapturePurchase($tranType, $rawStatus) ? $purchaseAmount : 0,
            'm_credit' => 0,
            'm_debit' => 0,
            'benefits' => 0,
            'status' => $this->normalizeHistoryStatus($transaction),
            'tran_type' => $tranType,
            'remark_flag' => $tranType,
            'created_at' => $this->getTransactionMoment($transaction),
        ];

        if (in_array($tranType, $this->benefitTypes, true)) {
            $history['benefits'] = $points;
            $ledger['balance'] += $points;
            if ($tranType === 'W') {
                $ledger['hasWelcome'] = true;
            }
        } elseif (in_array($tranType, $this->purchaseTypes, true)) {
            $this->applyPurchaseEffects($orderId, $rawStatus, $points, $ledger, $history);
        } elseif ($tranType === 'R') {
            $this->applyRedemptionEffects($rawStatus, $points, $ledger, $history);
        } elseif ($points > 0) {
            $history['m_credit'] = $points;
            $ledger['balance'] += $points;
        }

        $history['balance'] = $this->formatNumber(
            $this->shouldFreezeBalanceSnapshot($tranType, $rawStatus)
                ? $balanceBefore
                : $ledger['balance']
        );

        $history['pending_balance'] = $this->formatNumber($ledger['pending']);
        $history['__prev_balance'] = $balanceBefore;
        $history['__prev_pending'] = $pendingBefore;
        $history['__raw_status'] = $rawStatus;
        $history['__points'] = $points;

        return $history;
    }

    protected function applyPurchaseEffects(string $orderId, string $status, float $points, array &$ledger, array &$history): void
    {
        // For purchase transactions: use points from transactions table
        // Logic: if completed -> add to balance, if pending -> add to pending
        
        if ($status === 'pending') {
            // Pending purchase: add points to pending balance, balance stays the same
            if ($points > 0) {
                $ledger['pending'] += $points;
                $ledger['pendingByOrder'][$orderId] = ($ledger['pendingByOrder'][$orderId] ?? 0) + $points;
            }
            return;
        }

        if ($status === 'completed') {
            // Completed purchase: add points to balance (from transactions table)
            // Balance = previous balance + points from transaction
            if ($points > 0) {
                $history['m_credit'] = $points;
                // Add points to balance (this is the key: balance = previous + points)
                $ledger['balance'] += $points;
                
                // Release any pending points for this order
                if (!empty($ledger['pendingByOrder'][$orderId])) {
                    $release = min($points, $ledger['pendingByOrder'][$orderId]);
                    $ledger['pending'] = max(0, $ledger['pending'] - $release);
                    $ledger['pendingByOrder'][$orderId] -= $release;
                }
                $ledger['creditedByOrder'][$orderId] = ($ledger['creditedByOrder'][$orderId] ?? 0) + $points;
            }
            return;
        }

        if (in_array($status, ['cancelled', 'refunded'], true) && $points > 0) {
            // Cancelled/refunded: debit points from balance
            $history['m_debit'] = $points;
            if (!empty($ledger['creditedByOrder'][$orderId])) {
                // Debit from balance if it was previously credited
                $debit = min($points, $ledger['creditedByOrder'][$orderId]);
                $ledger['balance'] -= $debit;
                $ledger['creditedByOrder'][$orderId] -= $debit;
            } elseif (!empty($ledger['pendingByOrder'][$orderId])) {
                // Release pending if it was pending
                $release = min($points, $ledger['pendingByOrder'][$orderId]);
                $ledger['pending'] = max(0, $ledger['pending'] - $release);
                $ledger['pendingByOrder'][$orderId] -= $release;
            }
        }
    }

    protected function applyRedemptionEffects(string $status, float $points, array &$ledger, array &$history): void
    {
        if ($points <= 0) {
            return;
        }

        if ($status === 'refunded') {
            $history['m_credit'] = $points;
            $ledger['balance'] += $points;
            return;
        }

        $history['m_debit'] = $points;
        $ledger['balance'] -= $points;
    }

    protected function shouldCapturePurchase(string $tranType, string $status): bool
    {
        if (!in_array($tranType, $this->purchaseTypes, true)) {
            return false;
        }

        return !in_array($status, ['cancelled', 'refunded'], true);
    }

    protected function normalizeHistoryStatus(Transaction $transaction): string
    {
        $status = strtolower((string) ($transaction->status ?? 'pending'));

        return match ($status) {
            'refunded' => 'Refunded',
            'partial cancel', 'partial_cancel' => 'Partial Cancel',
            default => $status,
        };
    }

    protected function ledgersMatch(array $ledger, float $notifyBalance, float $notifyPending): bool
    {
        return $this->approximatelyEquals($ledger['balance'], $notifyBalance)
            && $this->approximatelyEquals($ledger['pending'], $notifyPending)
            && $ledger['hasWelcome'];
    }

    protected function shouldFreezeBalanceSnapshot(string $tranType, string $status): bool
    {
        return $tranType === 'P' && $status === 'pending';
    }

    protected function identifyCompletedPurchases(Collection $transactions): array
    {
        $orders = [];

        foreach ($transactions as $transaction) {
            if (!in_array($transaction->tran_type, $this->purchaseTypes, true)) {
                continue;
            }

            $status = strtolower((string) ($transaction->status ?? 'pending'));
            if ($status !== 'completed') {
                continue;
            }

            $orders[$this->getOrderIdentifier($transaction)] = true;
        }

        return $orders;
    }

    protected function shouldSkipPendingDuplicate(Transaction $transaction, array $completedOrders): bool
    {
        if (!in_array($transaction->tran_type, $this->purchaseTypes, true)) {
            return false;
        }

        $status = strtolower((string) ($transaction->status ?? 'pending'));
        if ($status !== 'pending') {
            return false;
        }

        return isset($completedOrders[$this->getOrderIdentifier($transaction)]);
    }

    protected function getOrderIdentifier(Transaction $transaction): string
    {
        return $transaction->note ?: (string) $transaction->id;
    }

    protected function getTransactionMoment(Transaction $transaction): Carbon
    {
        $tranType = $transaction->tran_type ?? 'P';
        $status = strtolower((string) ($transaction->status ?? 'pending'));

        if ($transaction->expires_at) {
            $moment = ($transaction->expires_at instanceof Carbon
                ? $transaction->expires_at->copy()
                : Carbon::parse($transaction->expires_at)
            )->subYear();
        } elseif ($transaction->created_at instanceof Carbon) {
            $moment = $transaction->created_at->copy();
        } elseif (!empty($transaction->created_at)) {
            $moment = Carbon::parse($transaction->created_at);
        } else {
            $moment = now();
        }

        if ($tranType === 'P') {
            if ($status === 'pending') {
                return $moment->copy()->subMicrosecond();
            }

            if (in_array($status, ['cancelled', 'refunded'], true)) {
                return $moment->copy()->addMicrosecond();
            }
        }

        return $moment;
    }

    protected function validateHistoryEntry(Member $member, array $history): ?array
    {
        $prevBalance = $history['__prev_balance'];
        $prevPending = $history['__prev_pending'];
        $rawStatus = $history['__raw_status'];
        $points = $history['__points'];
        $type = $history['tran_type'];

        $balanceDelta = $this->formatNumber($history['balance'] - $prevBalance);
        $pendingDelta = $this->formatNumber($history['pending_balance'] - $prevPending);
        $issues = [];

        if (in_array($type, $this->benefitTypes, true)) {
            if (!$this->approximatelyEquals($balanceDelta, $history['benefits'])) {
                $issues[] = "Benefit balance delta {$balanceDelta} expected {$history['benefits']}";
            }
            if (!$this->approximatelyEquals($pendingDelta, 0)) {
                $issues[] = "Benefit altered pending ({$pendingDelta})";
            }
        } elseif ($type === 'P') {
            if ($rawStatus === 'pending') {
                if (!$this->approximatelyEquals($balanceDelta, 0)) {
                    $issues[] = "Pending purchase changed balance ({$balanceDelta})";
                }
                if (!$this->approximatelyEquals($pendingDelta, $points)) {
                    $issues[] = "Pending purchase pending delta {$pendingDelta} expected {$points}";
                }
            } elseif ($rawStatus === 'completed') {
                if (!$this->approximatelyEquals($balanceDelta, $history['m_credit'])) {
                    $issues[] = "Completed purchase balance delta {$balanceDelta} expected {$history['m_credit']}";
                }
                if ($pendingDelta > 0 && !$this->approximatelyEquals($pendingDelta, 0)) {
                    $issues[] = "Completed purchase increased pending ({$pendingDelta})";
                }
                if (abs($pendingDelta) > $points + 1) {
                    $issues[] = "Completed purchase pending delta {$pendingDelta} exceeds points {$points}";
                }
            } elseif (in_array($rawStatus, ['cancelled', 'refunded'], true)) {
                $expected = -$history['m_debit'];
                if (!$this->approximatelyEquals($balanceDelta, $expected)) {
                    $issues[] = "Cancelled purchase balance delta {$balanceDelta} expected {$expected}";
                }
            }
        } elseif ($type === 'R') {
            if ($rawStatus === 'refunded') {
                if (!$this->approximatelyEquals($balanceDelta, $history['m_credit'])) {
                    $issues[] = "Redemption refund balance delta {$balanceDelta} expected {$history['m_credit']}";
                }
            } else {
                $expected = -$history['m_debit'];
                if (!$this->approximatelyEquals($balanceDelta, $expected)) {
                    $issues[] = "Redemption balance delta {$balanceDelta} expected {$expected}";
                }
            }
            if (!$this->approximatelyEquals($pendingDelta, 0)) {
                $issues[] = "Redemption changed pending ({$pendingDelta})";
            }
        }

        if (!empty($issues)) {
            $this->markLedgerFailure($member, implode(' | ', $issues));
            return null;
        }

        unset(
            $history['__prev_balance'],
            $history['__prev_pending'],
            $history['__raw_status'],
            $history['__points']
        );

        return $history;
    }

    protected function markLedgerFailure(Member $member, string $message, array $snapshot = []): void
    {
        $this->failedCount++;

        $this->failedMembers[] = [
            'mukafa_no' => $member->unique_identifier,
            'calculated_balance' => isset($snapshot['calculated_balance'])
                ? $this->formatNumber($snapshot['calculated_balance'])
                : 'N/A',
            'notify_balance' => isset($snapshot['notify_balance'])
                ? $this->formatNumber($snapshot['notify_balance'])
                : 'N/A',
            'calculated_pending' => isset($snapshot['calculated_pending'])
                ? $this->formatNumber($snapshot['calculated_pending'])
                : 'N/A',
            'notify_pending' => isset($snapshot['notify_pending'])
                ? $this->formatNumber($snapshot['notify_pending'])
                : 'N/A',
            'has_welcome' => isset($snapshot['has_welcome'])
                ? ($snapshot['has_welcome'] ? 'yes' : 'no')
                : 'unknown',
            'reason' => $message,
        ];

        Log::warning("OrderHistory rebuild validation failed for {$member->unique_identifier}: {$message}");

        if (!$this->option('dry-run')) {
            $member->sync_flag = false;
            $member->save();
        }
    }

    protected function formatNumber(float $value): float
    {
        return round($value, 2);
    }

    protected function approximatelyEquals(float $a, float $b, float $tolerance = 1.0): bool
    {
        return abs($a - $b) <= $tolerance;
    }
}
