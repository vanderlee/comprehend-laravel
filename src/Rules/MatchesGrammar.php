<?php

declare(strict_types=1);

namespace Vanderlee\Comprehend\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Vanderlee\Comprehend\Builder\FrozenGrammar;
use Vanderlee\Comprehend\Comprehend;
use Vanderlee\Comprehend\Runtime\ParseLimits;

/**
 * Validates text without retaining results or committing grammar effects.
 */
final readonly class MatchesGrammar implements ValidationRule
{
    /** @var Comprehend<mixed>|FrozenGrammar<mixed> */
    private Comprehend|FrozenGrammar $grammar;

    /** @param Comprehend<mixed>|FrozenGrammar<mixed> $grammar */
    public function __construct(
        Comprehend|FrozenGrammar $grammar,
        private bool $validateSemantics = false,
        private ?ParseLimits $limits = null,
        private ?string $message = null,
    ) {
        $this->grammar = $grammar;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            !is_string($value)
            || !mb_check_encoding($value, 'UTF-8')
        ) {
            $fail($this->message ?? 'The :attribute must be valid UTF-8 text.');

            return;
        }

        $result = $this->grammar->parseAll($value, limits: $this->limits);
        if (
            !$result->success
            || $result->hasRecovery()
        ) {
            $message = $this->message ?? 'The :attribute has invalid syntax.';

            $diagnostic = $result->diagnostics()[0] ?? null;
            if ($this->message === null && $diagnostic !== null) {
                $message .= ' ' . $diagnostic->message();
            }

            $fail($message);

            return;
        }

        if (
            $this->validateSemantics
            && !$result->validate()->valid
        ) {
            $fail($this->message ?? 'The :attribute does not satisfy the grammar constraints.');
        }
    }
}
