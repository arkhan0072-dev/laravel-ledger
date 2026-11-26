<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Events\EntryPosted;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow('2026-11-26 10:00');
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->lines = [Line::debit($this->cash, 100), Line::credit($this->sales, 100)];
    $this->october = Carbon::parse('2026-10-31');
});

afterEach(fn () => Carbon::setTestNow());

it('starts with every period open', function () {
    expect($this->ledger->closedThrough())->toBeNull();

    $this->ledger->post('Old sale', $this->lines, postedAt: Carbon::parse('2020-01-01'));
    expect(JournalEntry::count())->toBe(1);
});

it('refuses entries dated on or before the closing date', function (string $date) {
    $this->ledger->closePeriod($this->october);

    $this->ledger->post('Late October sale', $this->lines, postedAt: Carbon::parse($date));
})->with(['2026-10-31 23:59:59', '2026-10-01', '2025-12-31'])
    ->throws(InvalidEntry::class, 'The books are closed through 2026-10-31; date this entry after that.');

it('accepts entries after the closing date, including the default date', function () {
    $this->ledger->closePeriod($this->october);

    $this->ledger->post('November sale', $this->lines, postedAt: Carbon::parse('2026-11-01 00:00'));
    $this->ledger->post('Sale today', $this->lines);

    expect(JournalEntry::count())->toBe(2)->and($this->ledger->closedThrough()?->toDateString())->toBe('2026-10-31');
});

it('stores nothing and announces nothing for a refused entry', function () {
    $this->ledger->closePeriod($this->october);
    Event::fake([EntryPosted::class]);

    expect(fn () => $this->ledger->post('Late', $this->lines, postedAt: $this->october))->toThrow(InvalidEntry::class);
    expect(JournalEntry::count())->toBe(0);
    Event::assertNotDispatched(EntryPosted::class);
});

it('still returns an entry stored before the close to a retry', function () {
    $entry = $this->ledger->post('Order 9', $this->lines, 'order-9', $this->october);
    $this->ledger->closePeriod($this->october);

    expect($this->ledger->post('Order 9', $this->lines, 'order-9', $this->october)->id)->toBe($entry->id);
});

it('reverses a closed-period entry today, but not inside the closed period', function () {
    $entry = $this->ledger->post('October sale', $this->lines, postedAt: Carbon::parse('2026-10-15'));
    $this->ledger->closePeriod($this->october);

    expect($this->ledger->reverse($entry)->posted_at->toDateString())->toBe('2026-11-26');
    $this->ledger->reverse($this->ledger->post('Another', $this->lines), postedAt: Carbon::parse('2026-10-20'));
})->throws(InvalidEntry::class, 'closed through 2026-10-31');

it('refuses a conversion dated in a closed period and keeps neither side', function () {
    $eur = Account::create(['code' => '1001', 'name' => 'Cash EUR', 'type' => AccountType::Asset, 'currency' => 'EUR']);
    $this->ledger->closePeriod($this->october);

    expect(fn () => $this->ledger->convert('Buy EUR', $this->cash, 100, $eur, 92, postedAt: $this->october))->toThrow(InvalidEntry::class);
    expect(JournalEntry::count())->toBe(0);
});

it('only moves the closing date forward', function () {
    $this->ledger->closePeriod($this->october);

    $this->ledger->closePeriod(Carbon::parse('2026-09-30'));
})->throws(InvalidArgumentException::class, 'The books are already closed through 2026-10-31. Use reopenPeriod() to move the date back.');

it('reopens all periods or back to an earlier date', function () {
    $this->ledger->closePeriod($this->october);

    $this->ledger->reopenPeriod(Carbon::parse('2026-09-30'));
    $this->ledger->post('Fix in October', $this->lines, postedAt: Carbon::parse('2026-10-15'));
    expect($this->ledger->closedThrough()?->toDateString())->toBe('2026-09-30');

    $this->ledger->reopenPeriod();
    $this->ledger->post('Fix in 2025', $this->lines, postedAt: Carbon::parse('2025-06-01'));
    expect($this->ledger->closedThrough())->toBeNull()->and(JournalEntry::count())->toBe(2);
});

it('only moves a reopening date back', function () {
    $this->ledger->closePeriod(Carbon::parse('2026-09-30'));

    $this->ledger->reopenPeriod($this->october);
})->throws(InvalidArgumentException::class, 'Reopening must move the closing date back from 2026-09-30.');

it('keeps every close and reopen as history', function () {
    $this->ledger->closePeriod(Carbon::parse('2026-09-30'));
    $this->ledger->closePeriod($this->october);
    $this->ledger->reopenPeriod();

    expect(DB::table('ledger_period_closes')->orderBy('id')->pluck('closed_through')->map(fn ($d) => $d === null ? null : substr($d, 0, 10))->all())
        ->toBe(['2026-09-30', '2026-10-31', null]);
});
