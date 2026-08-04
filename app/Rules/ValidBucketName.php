<?php

declare(strict_types=1);

namespace App\Rules;

use App\S3\Values\BucketName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts exactly the names the S3 data plane accepts.
 *
 * The rules live in BucketName rather than being restated here, so a name the
 * dashboard offers to create can never be one `PUT /{bucket}` would reject.
 */
final class ValidBucketName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! \is_string($value) || ! BucketName::isValid($value)) {
            $fail(__('Use 3 to 63 lowercase letters, numbers, dots or hyphens, starting and ending with a letter or number.'));
        }
    }
}
