<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone field holds a number someone can actually be called on (SLO-151).
 *
 * The check is exactly PhoneNumber::toE164() returning something: the rule and
 * the normalization that runs before it (NormalizesPhone) share one
 * implementation, so a value can never pass here and then fail to normalize.
 */
final class Phone implements ValidationRule
{
    public function __construct(private readonly string $region) {}

    /**
     * The full rule list for an optional phone field, so every entry point states
     * the same thing — including CreateNewUser, which validates by hand because
     * customer registration runs outside a FormRequest.
     *
     * @return array<int, mixed>
     */
    public static function rules(string $region, int $max = 50): array
    {
        return [
            'nullable',
            // One message per field: without bail, junk long enough to trip max:
            // would be reported as both too long and not a phone number.
            'bail',
            'string',
            new self($region),
            // Unreachable once this rule passes (E.164 is at most 16 characters);
            // kept as the column-width backstop it has always been.
            'max:'.$max,
        ];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::toE164($value, $this->region) === null) {
            $fail('validation.phone')->translate([
                'example' => PhoneNumber::example($this->region),
            ]);
        }
    }
}
