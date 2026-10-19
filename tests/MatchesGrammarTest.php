<?php

declare(strict_types=1);

namespace Vanderlee\Comprehend\Laravel\Tests;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Vanderlee\Comprehend\Comprehend;
use Vanderlee\Comprehend\Laravel\Rules\MatchesGrammar;
use Vanderlee\Comprehend\Result\ParseResult;
use Vanderlee\Comprehend\Runtime\ParseLimitException;
use Vanderlee\Comprehend\Runtime\ParseLimits;

final class MatchesGrammarTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function grammarForms(): iterable
    {
        yield 'builder' => [false];
        yield 'frozen' => [true];
    }

    private function rule(
        bool $frozen,
        Comprehend $grammar,
        bool $validateSemantics = false,
        ?ParseLimits $limits = null,
        ?string $message = null,
    ): MatchesGrammar {
        return new MatchesGrammar(
            $frozen
                ? $grammar->freeze()
                : $grammar,
            validateSemantics: $validateSemantics,
            limits: $limits,
            message: $message,
        );
    }

    private function validator(mixed $value, MatchesGrammar $rule): Validator
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));

        return $factory->make([
            'expression' => $value
        ], [
            'expression' => [
                'required',
                $rule
            ]
        ]);
    }

    #[DataProvider('grammarForms')]
    public function testCompleteMatchPreservesTheOriginalInput(bool $frozen): void
    {
        $validator = $this->validator('hello', $this->rule($frozen, Comprehend::text('hello')));

        self::assertTrue($validator->passes());
        self::assertSame(['expression' => 'hello'], $validator->validated());
    }

    #[DataProvider('grammarForms')]
    public function testTrailingInputReportsItsByteOffset(bool $frozen): void
    {
        $validator = $this->validator('é!', $this->rule($frozen, Comprehend::text('é')));

        self::assertFalse($validator->passes());
        self::assertStringStartsWith('The expression has invalid syntax.', $validator->errors()->first('expression'));
        self::assertStringContainsString('byte 2', $validator->errors()->first('expression'));
        self::assertStringContainsString('expression', $validator->errors()->first('expression'));
    }

    #[DataProvider('grammarForms')]
    public function testNonStringsAndInvalidUtf8AreRejectedWithoutCoercion(bool $frozen): void
    {
        foreach ([42, false, ['hello'], new stdClass(), "\xff"] as $value) {
            self::assertFalse($this->validator($value, $this->rule($frozen, Comprehend::text('hello')))->passes());
        }
    }

    #[DataProvider('grammarForms')]
    public function testSemanticsAreExplicitAndNeverCommitEffects(bool $frozen): void
    {
        $effects = 0;
        $validations = 0;
        $grammar = Comprehend::text('hello')
            ->validateSemantic(static function (ParseResult $result) use (&$validations): bool {
                ++$validations;

                return false;
            })
            ->onCommit(static function (ParseResult $result) use (&$effects): void {
                ++$effects;
            });

        self::assertTrue($this->validator('hello', $this->rule($frozen, $grammar))->passes());
        self::assertSame(0, $validations);
        self::assertFalse($this->validator('hello', $this->rule($frozen, $grammar, validateSemantics: true))->passes());
        self::assertSame(1, $validations);
        self::assertSame(0, $effects);
    }

    #[DataProvider('grammarForms')]
    public function testSuccessfulSemanticValidationDoesNotCommit(bool $frozen): void
    {
        $effects = 0;
        $validations = 0;
        $grammar = Comprehend::text('hello')
            ->validateSemantic(static function (ParseResult $result) use (&$validations): bool {
                ++$validations;

                return true;
            })
            ->onCommit(static function (ParseResult $result) use (&$effects): void {
                ++$effects;
            });

        self::assertTrue($this->validator('hello', $this->rule($frozen, $grammar, validateSemantics: true))->passes());
        self::assertSame(1, $validations);
        self::assertSame(0, $effects);
    }

    #[DataProvider('grammarForms')]
    public function testRuleCanBeReusedAfterFailure(bool $frozen): void
    {
        $rule = $this->rule($frozen, Comprehend::text('hello'));
        $validator = $this->validator('goodbye', $rule);

        self::assertFalse($validator->passes());
        self::assertTrue($this->validator('hello', $rule)->passes());
        self::assertFalse($this->validator('goodbye', $rule)->passes());
    }

    #[DataProvider('grammarForms')]
    public function testApplicationExceptionsAreNotHiddenAsInvalidInput(bool $frozen): void
    {
        $grammar = Comprehend::text('hello')->validateSemantic(static function (ParseResult $result): bool {
            throw new RuntimeException('Application failure');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Application failure');
        $this->validator('hello', $this->rule($frozen, $grammar, validateSemantics: true))->passes();
    }

    #[DataProvider('grammarForms')]
    public function testMissingAndEmptyValuesRemainLaravelsResponsibility(bool $frozen): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $rule = $this->rule($frozen, Comprehend::text('hello'));

        self::assertTrue($factory->make([], ['expression' => [$rule]])->passes());
        self::assertTrue($factory->make(['expression' => ''], ['expression' => [$rule]])->passes());
        self::assertTrue($factory->make(['expression' => null], ['expression' => ['nullable', $rule]])->passes());
        self::assertFalse($this->validator('', $rule)->passes());
    }

    #[DataProvider('grammarForms')]
    public function testRecoveryDoesNotTurnMalformedInputIntoValidInput(bool $frozen): void
    {
        $grammar = Comprehend::text('ok;')->recoverUntil(';', consumeBoundary: true)->some();

        self::assertTrue($grammar->parse('bad;')->success);
        self::assertFalse($this->validator('bad;', $this->rule($frozen, $grammar))->passes());
        self::assertTrue($this->validator('ok;', $this->rule($frozen, $grammar))->passes());
    }

    #[DataProvider('grammarForms')]
    public function testRecognitionDoesNotEvaluateLazyMappings(bool $frozen): void
    {
        $grammar = Comprehend::text('hello')->map(static function (string $value): string {
            throw new RuntimeException('Mapping must stay lazy');
        });

        self::assertTrue($this->validator('hello', $this->rule($frozen, $grammar))->passes());
    }

    #[DataProvider('grammarForms')]
    public function testExecutionLimitsPropagateToTheApplication(bool $frozen): void
    {
        $rule = $this->rule($frozen, Comprehend::text('hello'), limits: new ParseLimits(maxWork: 1));

        $this->expectException(ParseLimitException::class);
        $this->validator('hello', $rule)->passes();
    }

    #[DataProvider('grammarForms')]
    public function testCustomMessageReplacesEveryFailure(bool $frozen): void
    {
        $grammar = Comprehend::text('hello')->validateSemantic(static fn(ParseResult $result): bool => false);
        $rule = $this->rule(
            $frozen,
            $grammar,
            validateSemantics: true,
            message: 'The :attribute must be a greeting.',
        );

        foreach ([42, "\xff", 'private-input', 'hello'] as $value) {
            $validator = $this->validator($value, $rule);

            self::assertFalse($validator->passes());
            self::assertSame('The expression must be a greeting.', $validator->errors()->first('expression'));
        }
    }

    #[DataProvider('grammarForms')]
    public function testMultilineDiagnosticsExcludeSourceText(bool $frozen): void
    {
        $validator = $this->validator(
            "header\néPRIVATE",
            $this->rule($frozen, Comprehend::text("header\né")->then(Comprehend::text('!'))),
        );

        self::assertFalse($validator->passes());
        $message = $validator->errors()->first('expression');
        self::assertStringContainsString('line 2, column 2 (byte 9)', $message);
        self::assertStringContainsString('Expected "!"', $message);
        self::assertStringNotContainsString('PRIVATE', $message);
    }

    #[DataProvider('grammarForms')]
    public function testDefaultFailureMessages(bool $frozen): void
    {
        $grammar = Comprehend::text('hello')->validateSemantic(static fn(ParseResult $result): bool => false);
        $rule = $this->rule($frozen, $grammar, validateSemantics: true);

        foreach (
            [
                "\xff" => 'The expression must be valid UTF-8 text.',
                'hello' => 'The expression does not satisfy the grammar constraints.',
            ] as $value => $expected
        ) {
            $validator = $this->validator($value, $rule);

            self::assertFalse($validator->passes());
            self::assertSame($expected, $validator->errors()->first('expression'));
        }
    }

    #[DataProvider('grammarForms')]
    public function testSyntaxFailureSkipsSemantics(bool $frozen): void
    {
        $grammar = Comprehend::text('hello')->validateSemantic(static function (ParseResult $result): bool {
            throw new RuntimeException('Syntax failure must skip semantics');
        });

        self::assertFalse(
            $this->validator('hello!', $this->rule($frozen, $grammar, validateSemantics: true))->passes()
        );
    }

    #[DataProvider('grammarForms')]
    public function testRecoveredSyntaxSkipsSemanticsAndHonorsCustomMessages(bool $frozen): void
    {
        $grammar = Comprehend::text('ok;')->recoverUntil(';', consumeBoundary: true)->some()
            ->validateSemantic(static function (ParseResult $result): bool {
                throw new RuntimeException('Recovered syntax must skip semantics');
            });
        $validator = $this->validator(
            'bad;',
            $this->rule(
                $frozen,
                $grammar,
                validateSemantics: true,
                message: 'The :attribute must contain valid statements.',
            )
        );

        self::assertFalse($validator->passes());
        self::assertSame('The expression must contain valid statements.', $validator->errors()->first('expression'));
    }

    #[DataProvider('grammarForms')]
    public function testSemanticValidationEvaluatesFreshMappedValues(bool $frozen): void
    {
        $mappings = 0;
        $grammar = Comprehend::text('hello')
            ->map(static function (string $value) use (&$mappings): string {
                ++$mappings;

                return strtoupper($value);
            })
            ->validateSemantic(static fn(ParseResult $result): bool => $result->value() === 'HELLO');
        $rule = $this->rule($frozen, $grammar, validateSemantics: true);

        self::assertTrue($this->validator('hello', $rule)->passes());
        self::assertSame(1, $mappings);
        self::assertTrue($this->validator('hello', $rule)->passes());
        self::assertSame(2, $mappings);
    }

    #[DataProvider('grammarForms')]
    public function testDocumentedReferenceGrammar(bool $frozen): void
    {
        $rule = $this->rule($frozen, Comprehend::text('INV-')->then(Comprehend::range('0', '9')->some()));

        self::assertTrue($this->validator('INV-123', $rule)->passes());
        self::assertFalse($this->validator('INV-', $rule)->passes());
    }
}
