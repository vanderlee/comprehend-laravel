# Comprehend for Laravel

A standalone Laravel validation bridge for Comprehend 2. Supports Illuminate
11, 12 and 13 on their respective supported PHP versions (PHP 8.2 minimum;
Illuminate 13 requires PHP 8.3). The core library has no Laravel dependency.

## Installation

This package is maintained as a separate project and has not been published to
Packagist. To try a local checkout, place it alongside your Laravel application:

```text
projects/
    your-laravel-app/
    Comprehend-Laravel/
```

Run these commands from your Laravel application's directory:

```sh
composer config repositories.comprehend-laravel path ../Comprehend-Laravel
composer require "vanderlee/comprehend-laravel:@dev"
```

Adjust the path if your checkout is elsewhere. The `@dev` flag allows the
unreleased package without changing your application's minimum stability.
Composer installs the Comprehend core dependency automatically.

Once the package is published to Packagist, installation will be:

```sh
composer require vanderlee/comprehend-laravel
```

## Usage

```php
use Vanderlee\Comprehend\Comprehend as C;
use Vanderlee\Comprehend\Laravel\Rules\MatchesGrammar;

// Inside a Form Request's rules() method:
return [
    'reference' => [
        'required',
        'string',
        new MatchesGrammar(C::text('INV-')->then(C::range('0', '9')->some())),
    ],
];
```

This accepts references such as `INV-123` and rejects `INV-`, `123` and
`INV-123-extra`.

The constructor accepts a `Comprehend` facade or a `FrozenGrammar` object,
not a class name. Inject your application's grammar service into the Form
Request's `rules()` method when grammars are registered in Laravel's container.
No service provider, facade, configuration file or package discovery is needed.

The rule requires a complete match and rejects recovered syntax. Input must
be a UTF-8 string; scalars and objects are never coerced. Syntax failures include
the first diagnostic's line, column and byte offset. Source text is not included,
but grammar expectations may appear; supply a custom message to hide them.

### Semantic validation, limits and messages

```php
new MatchesGrammar(
    $grammar->freeze(),
    validateSemantics: true,
    limits: new \Vanderlee\Comprehend\Runtime\ParseLimits(maxDepth: 100, maxWork: 100000),
    message: __('validation.reference_syntax'),
);
```

Custom messages replace the entire failure message, including syntax diagnostics,
for invalid text, syntax and semantic failures. They support Laravel's `:attribute`
placeholder. Translate the message in the application before passing it. The package
does not register translation keys.

Recognition alone runs by default. Set `validateSemantics: true` to run the
grammar's semantic validators; these may evaluate lazy mappings. Mappings and
validators should be pure. The rule never calls `commit()`, even after semantic
success, and retains no input or parse result between calls. Grammar errors,
application callback exceptions and execution-limit exceptions propagate to the
application; they are not converted into ordinary validation failures.

### Missing, empty and nullable fields

Laravel normally skips ordinary rules for missing attributes and empty strings.
Use `required` for mandatory input, or `nullable` for optional null values.
Laravel's request middleware may trim strings or convert empty strings to null
before validation; configure that middleware if exact input bytes matter.

### Reading the parsed value

Validation does not replace the field with a parsed value. After successful
validation, parse explicitly through the same grammar and call `value()`:

```php
// In a controller, with the validated Form Request and the same grammar
// used by its MatchesGrammar rule:
$data = $request->validated();
$result = $grammar->parseAll($data['reference']);
$reference = $result->value();
```

This example assumes `reference` is required. For an optional field, check that
it is present and non-null before parsing. Reuse any parse limits configured on
the rule. The value's type depends on the grammar and its mappings; validation
alone leaves those mappings unevaluated unless semantic validators need them.
Commit effects only at an explicit application boundary.

## Development

Install dependencies and run the tests from this package directory:

```sh
composer install
composer test
```

Tests use the real Illuminate validation factory, without booting a Laravel app.
The same behavioral cases run against builder and frozen grammars. They cover
recognition, UTF-8 and type rejection, multiline diagnostic locations without source
excerpts, custom messages, semantic checks, lazy mappings, effect isolation,
recovery rejection, execution limits, callback exceptions, rule reuse and Laravel's
handling of empty, missing and nullable fields.

MIT licensed; see [LICENSE](LICENSE).
