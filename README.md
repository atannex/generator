# atannex/generator

Unique, human-readable codes for Laravel from one small pattern language: staff IDs, department codes, invoice and order numbers, vouchers, tickets, account numbers with check digits.

```
EMP26ALC482913        {prefix}{yy}{abbr:3}{random:6}
INV-2026-00042        INV-{year}-{seq:5}
7KQ9-M2XD             {alnum:4}-{alnum:4}
ACC4829137705         ACC{random:9}{check}
TKT-0f8f…             TKT-{uuid}
```

- **Model trait.** Codes are filled on save. Hand-set codes are kept, existing rows can be backfilled, and soft-deleted rows keep theirs.
- **Concurrency-safe.** Sequences lock their counter row, and `saveWithCodeRetry()` recovers when two requests pick the same random code.
- **Readable.** Random parts can avoid look-alike characters (0/O, 1/I/L), and names are abbreviated with accents transliterated.
- **Extensible.** Add your own tokens with `Generator::extend()`.

Requires PHP 8.3+ and Laravel 11 or 12.

## Installation

```bash
composer require atannex/generator
php artisan migrate            # creates the generator_sequences table (used by {seq})
```

Optional:

```bash
php artisan vendor:publish --tag=generator-config
php artisan vendor:publish --tag=generator-migrations
```

## Patterns

A token is `{name}` or `{name:argument}`. Text outside tokens is kept as is.

| Token | Output | Example |
|---|---|---|
| `{prefix}` | the configured prefix, upper-cased | `EMP` |
| `{abbr:N}` | N-letter abbreviation of the source field(s), default 3 | `Atanga Leke Collins` → `ALC`, `Collins` → `COL` |
| `{field:path}` | an attribute (dot notation), upper-cased A–Z/0–9 | `{field:region.code}` → `NW` |
| `{year}` `{yy}` `{month}` `{day}` | current date parts | `2026` `26` `03` `09` |
| `{date:FORMAT}` | any PHP date format, default `Ymd` | `{date:Ym}` → `202603` |
| `{random:N}` | N digits, default 6 | `482913` |
| `{alpha:N}` | N letters without look-alikes, default 6 | `KQMXDR` |
| `{alnum:N}` | N letters/digits without look-alikes, default 8 | `7KQ9M2XD` |
| `{hex:N}` | N hex digits, default 8 | `9F03A1BC` |
| `{uuid}` `{ulid}` | UUID v4 / ULID | |
| `{seq}` `{seq:N}` | next number of a counter, zero-padded to N | `00042` |
| `{check}` | Luhn check digit over all other digits (put it last) | `ACC4829137705` |

### Sequences

`{seq}` hands out 1, 2, 3… from a counter in the database, locked per increment so concurrent requests never get the same number.

**The counter restarts whenever the rest of the code changes.** For example:

- `INV-{year}-{seq:5}` gives `INV-2026-00001` and restarts at `INV-2027-00001` the next year.
- `{prefix}-{seq}` keeps a separate count per prefix.

Random tokens don't count as a change.

Numbers are taken when the code is generated. If the save then fails, that number is skipped. For strictly gap-free numbering, wrap the save in `DB::transaction()`: the counter row then stays locked until the save commits.

## Models

```php
use Atannex\Generator\CodeOptions;
use Atannex\Generator\Concerns\HasCodes;

class Employee extends Model
{
    use HasCodes;

    public function codeOptions(): CodeOptions
    {
        return CodeOptions::for('code')                    // column
            ->format('{prefix}{yy}{abbr:3}{random:6}')
            ->prefix('EMP')
            ->from('user.name');                           // or 'name', or ['first_name', 'last_name']
    }
}

Employee::create(['user_id' => $user->id])->code;          // EMP26ALC482913
```

Give the column a unique index. Without `codeOptions()`, the model uses the `code` column with the configured default pattern.

### Several codes, per-tenant numbering

```php
public function codeOptions(): array
{
    return [
        CodeOptions::for('number')
            ->format('INV-{year}-{seq:5}')
            ->sequence('invoices.tenant-'.$this->tenant_id)                     // one counter per tenant
            ->uniqueWithin(fn (Builder $q, self $m) => $q->where('tenant_id', $m->tenant_id)),

        CodeOptions::for('reference')->format('{alnum:10}'),                     // public, unguessable
    ];
}
```

With `uniqueWithin()`, use a composite unique index such as `unique(['tenant_id', 'number'])`.

### Options

| Method | Default | |
|---|---|---|
| `format(string)` | `config('generator.pattern')` | the pattern |
| `prefix(string)` | `''` | value of `{prefix}` |
| `from(string\|array)` | `'name'` | field(s) for `{abbr}`; relations with dot notation |
| `regenerateOnChange()` | off | issue a new code when a source field changes |
| `uniqueWithin(Closure)` | whole table | limit uniqueness (tenant, branch…) |
| `sequence(string)` | `Model.column` | counter name; share one to number several models in one series |
| `maxAttempts(int)` | `15` | tries before giving up |

### Behaviour

- **New records** get a code on save. **Existing rows without one** get it on their next save, or run the backfill command.
- **A code set by hand is kept.** The trait only fills empty columns.
- **Codes never change once set,** because they're identifiers that get printed, shared and bookmarked. Opt in with `regenerateOnChange()`. Editing the code yourself always wins.
- **Soft-deleted rows keep their code,** like the database index does. A restored record keeps its code and only gets a new one if another record has taken it.

### API

```php
Invoice::findByCode('INV-2026-00042');
Invoice::findByCodeOrFail($ref, 'reference');
Invoice::whereCode($code)->exists();

$invoice->getCode();                 // first code column
$invoice->regenerateCode('reference');
$invoice->codeExists($code);

$invoice->saveWithCodeRetry();       // regenerates generated codes on a unique-key race (3 attempts)
```

## Without a model

```php
use Atannex\Generator\Facades\Generator;

Generator::render('VCH-{alnum:4}-{alnum:4}');                             // one code, no uniqueness check
Generator::generate('VCH-{alnum:8}', exists: fn ($c) => Voucher::where('code', $c)->exists());
Generator::generate('{prefix}-{seq:4}', ['prefix' => 'DLA', 'sequence' => 'branches']);

Generator::verify('ACC4829137705');                                      // check digit intact?
Generator::abbreviate('Human Resources');                                // HRU
Generator::random(8);                                                    // 8 safe letters/digits

Generator::sequences()->set('invoices.tenant-1|INV-2026-{seq:5}', 1500);  // continue an imported series:
                                                                         // key = sequence name | pattern with fixed parts filled
```

## Custom tokens

```php
// AppServiceProvider::boot()
Generator::extend('branch', fn (string $arg, array $context) => $context['attributes']->branch->code);
Generator::extend('pin', fn (string $arg) => (string) random_int(1000, 9999), random: true);
```

`$context['attributes']` is the model being coded. Mark a token `random: true` when its value changes on every call. That lets retries produce a different code and keeps the token out of the sequence scope.

## Backfilling

```bash
php artisan generator:backfill "App\Models\Employee" "App\Models\Department"
php artisan generator:backfill "App\Models\Employee" --force     # regenerate ALL codes (changes identifiers)
```

## Testing

```bash
composer install && composer test
```

## License

MIT
