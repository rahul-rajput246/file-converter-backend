<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BatchCompressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get validation rules.
     */
    public function rules(): array
    {
        $maxKb = (int) config('file_converter.max_file_size', 102400);
        $maxFiles = (int) config('file_converter.max_batch_files', 10);

        return [
            'files' => [
                'required',
                'array',
                'min:1',
                'max:' . $maxFiles,
            ],
            'files.*' => [
                'required',
                'file',
                'max:' . $maxKb,
            ],
            'compression_level' => [
                'nullable',
                'string',
                'in:low,medium,high',
            ],
            'target_size_kb' => [
                'nullable',
                'numeric',
                'min:1',
                'max:102400',
            ],
        ];
    }

    /**
     * Validation messages.
     */
    public function messages(): array
    {
        $maxFiles = (int) config('file_converter.max_batch_files', 10);
        $maxMb = (int) (config('file_converter.max_file_size', 102400) / 1024);

        return [
            'files.required' => 'At least one file is required for batch compression.',
            'files.array' => 'Files must be provided as an array.',
            'files.min' => 'Please upload at least 1 image.',
            'files.max' => "Maximum {$maxFiles} images can be processed at a time.",
            'files.*.required' => 'Uploaded file item cannot be empty.',
            'files.*.file' => 'Each uploaded item must be a valid file.',
            'files.*.max' => "Each file size must not exceed {$maxMb} MB.",
            'compression_level.in' => 'Invalid compression level. Choose low, medium, or high.',
            'target_size_kb.numeric' => 'Target size must be a valid number in KB.',
        ];
    }

    /**
     * Custom JSON response on failed validation.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => $validator->errors()->first() ?: 'The uploaded files or compression settings are invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
