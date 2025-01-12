<?php
/**
 * FixAccountTypes.php
 * Copyright (c) 2020 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Correction;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use Illuminate\Console\Command;
use JsonException;
use Illuminate\Support\Facades\Log;

/**
 * Class FixAccountTypes
 */
class FixAccountTypes extends Command
{
    protected $description = 'Make sure all journals have the correct from/to account types.';
    protected $signature   = 'firefly-iii:fix-account-types';
    private int            $count;
    private array          $expected;
    private AccountFactory $factory;

    /**
     * Execute the console command.
     *
     * @return int
     * @throws FireflyException
     */
    public function handle(): int
    {
        $this->stupidLaravel();
        $this->factory  = app(AccountFactory::class);
        $this->expected = config('firefly.source_dests');
        $journals       = TransactionJournal::with(['TransactionType', 'transactions', 'transactions.account', 'transactions.account.accounttype'])->get();
        foreach ($journals as $journal) {
            $this->inspectJournal($journal);
        }
        if (0 === $this->count) {
            $this->info('Correct: all account types are OK');
        }
        if (0 !== $this->count) {
            Log::debug(sprintf('%d journals had to be fixed.', $this->count));
            $this->info(sprintf('Acted on %d transaction(s)!', $this->count));
        }

        return 0;
    }

    /**
     * @param  TransactionJournal  $journal
     * @param  string  $type
     * @param  Transaction  $source
     * @param  Transaction  $dest
     *
     * @throws FireflyException
     * @throws JsonException
     */
    private function fixJournal(TransactionJournal $journal, string $type, Transaction $source, Transaction $dest): void
    {
        $this->count++;
        // variables:
        $combination = sprintf('%s%s%s', $type, $source->account->accountType->type, $dest->account->accountType->type);

        switch ($combination) {
            case sprintf('%s%s%s', TransactionType::TRANSFER, AccountType::ASSET, AccountType::LOAN):
            case sprintf('%s%s%s', TransactionType::TRANSFER, AccountType::ASSET, AccountType::DEBT):
            case sprintf('%s%s%s', TransactionType::TRANSFER, AccountType::ASSET, AccountType::MORTGAGE):
                // from an asset to a liability should be a withdrawal:
                $withdrawal = TransactionType::whereType(TransactionType::WITHDRAWAL)->first();
                $journal->transactionType()->associate($withdrawal);
                $journal->save();
                $message = sprintf('Converted transaction #%d from a transfer to a withdrawal.', $journal->id);
                $this->info($message);
                Log::debug($message);
                // check it again:
                $this->inspectJournal($journal);
                break;
            case sprintf('%s%s%s', TransactionType::TRANSFER, AccountType::LOAN, AccountType::ASSET):
            case sprintf('%s%s%s', TransactionType::TRANSFER, AccountType::DEBT, AccountType::ASSET):
            case sprintf('%s%s%s', TransactionType::TRANSFER, AccountType::MORTGAGE, AccountType::ASSET):
                // from a liability to an asset should be a deposit.
                $deposit = TransactionType::whereType(TransactionType::DEPOSIT)->first();
                $journal->transactionType()->associate($deposit);
                $journal->save();
                $message = sprintf('Converted transaction #%d from a transfer to a deposit.', $journal->id);
                $this->info($message);
                Log::debug($message);
                // check it again:
                $this->inspectJournal($journal);

                break;
            case sprintf('%s%s%s', TransactionType::WITHDRAWAL, AccountType::ASSET, AccountType::REVENUE):
                // withdrawals with a revenue account as destination instead of an expense account.
                $this->factory->setUser($journal->user);
                $oldDest = $dest->account;
                $result  = $this->factory->findOrCreate($dest->account->name, AccountType::EXPENSE);
                $dest->account()->associate($result);
                $dest->save();
                $message = sprintf(
                    'Transaction journal #%d, destination account changed from #%d ("%s") to #%d ("%s").',
                    $journal->id,
                    $oldDest->id,
                    $oldDest->name,
                    $result->id,
                    $result->name
                );
                $this->info($message);
                Log::debug($message);
                $this->inspectJournal($journal);
                break;
            case sprintf('%s%s%s', TransactionType::DEPOSIT, AccountType::EXPENSE, AccountType::ASSET):
                // deposits with an expense account as source instead of a revenue account.
                // find revenue account.
                $this->factory->setUser($journal->user);
                $result    = $this->factory->findOrCreate($source->account->name, AccountType::REVENUE);
                $oldSource = $dest->account;
                $source->account()->associate($result);
                $source->save();
                $message = sprintf(
                    'Transaction journal #%d, source account changed from #%d ("%s") to #%d ("%s").',
                    $journal->id,
                    $oldSource->id,
                    $oldSource->name,
                    $result->id,
                    $result->name
                );
                $this->info($message);
                Log::debug($message);
                $this->inspectJournal($journal);
                break;
            default:
                $message = sprintf('The source account of %s #%d cannot be of type "%s".', $type, $journal->id, $source->account->accountType->type);
                $this->info($message);
                Log::debug($message);

                $message = sprintf('The destination account of %s #%d cannot be of type "%s".', $type, $journal->id, $dest->account->accountType->type);
                $this->info($message);
                Log::debug($message);

                break;
        }
    }

    /**
     * @param  TransactionJournal  $journal
     *
     * @return Transaction
     */
    private function getDestinationTransaction(TransactionJournal $journal): Transaction
    {
        return $journal->transactions->firstWhere('amount', '>', 0);
    }

    /**
     * @param  TransactionJournal  $journal
     *
     * @return Transaction
     */
    private function getSourceTransaction(TransactionJournal $journal): Transaction
    {
        return $journal->transactions->firstWhere('amount', '<', 0);
    }

    /**
     * @param  TransactionJournal  $journal
     *
     * @throws FireflyException
     */
    private function inspectJournal(TransactionJournal $journal): void
    {
        $transactions = $journal->transactions()->count();
        if (2 !== $transactions) {
            Log::debug(sprintf('Journal has %d transactions, so can\'t fix.', $transactions));
            $this->info(sprintf('Cannot inspect transaction journal #%d because it has %d transaction(s) instead of 2.', $journal->id, $transactions));

            return;
        }
        $type              = $journal->transactionType->type;
        $sourceTransaction = $this->getSourceTransaction($journal);
        $destTransaction   = $this->getDestinationTransaction($journal);
        $sourceAccount     = $sourceTransaction->account;
        $sourceAccountType = $sourceAccount->accountType->type;
        $destAccount       = $destTransaction->account;
        $destAccountType   = $destAccount->accountType->type;

        if (!array_key_exists($type, $this->expected)) {
            Log::info(sprintf('No source/destination info for transaction type %s.', $type));
            $this->info(sprintf('No source/destination info for transaction type %s.', $type));

            return;
        }
        if (!array_key_exists($sourceAccountType, $this->expected[$type])) {
            Log::debug(sprintf('Going to fix journal #%d', $journal->id));
            $this->fixJournal($journal, $type, $sourceTransaction, $destTransaction);

            return;
        }
        $expectedTypes = $this->expected[$type][$sourceAccountType];
        if (!in_array($destAccountType, $expectedTypes, true)) {
            Log::debug(sprintf('Going to fix journal #%d', $journal->id));
            $this->fixJournal($journal, $type, $sourceTransaction, $destTransaction);
        }
    }

    /**
     * Laravel will execute ALL __construct() methods for ALL commands whenever a SINGLE command is
     * executed. This leads to noticeable slow-downs and class calls. To prevent this, this method should
     * be called from the handle method instead of using the constructor to initialize the command.
     *

     */
    private function stupidLaravel(): void
    {
        $this->count = 0;
    }
}
