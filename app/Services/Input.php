<?php
namespace App\Services;

use App\Exceptions\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class Input
{
    public static function body(Request $request, array $rules, array $integerFields = []): array
    {
        self::query($request, []);
        $data = $request->json()->all();
        self::known($data, array_keys($rules));
        foreach ($integerFields as $field) {
            if (array_key_exists($field, $data) && !is_int($data[$field])) {
                throw new ApiProblem(422, 'validation_failed', "$field must be a JSON integer.", [$field => ['Use a JSON integer.']]);
            }
        }
        return Validator::make($data, $rules)->validate();
    }

    public static function query(Request $request, array $allowed): array
    {
        $data = $request->query();
        self::known($data, $allowed);
        return $data;
    }

    public static function page(Request $request): array
    {
        $data = self::query($request, ['afterId', 'limit']);
        foreach ($data as $key => $value) {
            if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,8}$/D', $value)) {
                throw new ApiProblem(422, 'validation_failed', "$key must be a positive decimal integer.");
            }
        }
        $limit = (int) ($data['limit'] ?? 20);
        if ($limit > 50) {
            throw new ApiProblem(422, 'validation_failed', 'limit must be between 1 and 50.');
        }
        return [(int) ($data['afterId'] ?? 0), $limit];
    }

    private static function known(array $data, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new ApiProblem(422, 'unknown_fields', 'Unknown fields are not accepted.', ['fields' => $unknown]);
        }
    }
}
